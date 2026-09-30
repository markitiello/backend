<?php

declare(strict_types=1);

namespace Benzina\Tests;

use Benzina\Database;
use Benzina\Importer;
use Benzina\Mimit\Parser;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\StreamInterface;
use Psr\Log\AbstractLogger;

final class ImporterTest extends TestCase
{
    private static function average(Database $db, string $day, string $fuel, string $mode): ?float
    {
        $row = $db->one(
            'SELECT price FROM national_averages WHERE day = ? AND fuel = ? AND mode = ?',
            [$day, $fuel, $mode],
        );
        return $row === null ? null : (float) $row['price'];
    }

    private static function rows(Database $db, string $table, string $where = '1 = 1'): int
    {
        return (int) $db->one("SELECT COUNT(*) AS n FROM $table WHERE $where")['n'];
    }

    public function testPrimoImport(): void
    {
        $db = self::database();
        $result = (new Importer($db, self::config()))->run(
            self::lines('stations_day1.csv'),
            self::lines('prices_day1.csv'),
        );
        // 9999 non è in anagrafica, 1006 non ha coordinate: esclusi.
        self::assertSame(['day' => '2026-09-24', 'stations' => 5, 'prices' => 11, 'new_changes' => 11], $result);
        self::assertSame(5, self::rows($db, 'stations'));

        // Il prezzo del 1004 è di giugno: resta tra i prezzi attuali ma non
        // entra nella media nazionale.
        self::assertEqualsWithDelta((1.739 + 1.759 + 1.772 + 1.701) / 4, self::average($db, '2026-09-24', 'benzina', 'self'), 1e-4);
        self::assertEqualsWithDelta(1.899, self::average($db, '2026-09-24', 'benzina', 'servito'), 1e-9);
        // GPL e metano: un prezzo per distributore, il più basso.
        self::assertEqualsWithDelta((0.749 + 0.719) / 2, self::average($db, '2026-09-24', 'gpl', 'any'), 1e-9);
        self::assertEqualsWithDelta(1.459, self::average($db, '2026-09-24', 'metano', 'any'), 1e-9);
    }

    public function testSecondoImportAggiornaESalvaSoloLeVariazioni(): void
    {
        $db = self::importedDatabase();
        self::assertSame(13, self::rows($db, 'price_changes'));
        self::assertSame(11, self::rows($db, 'current_prices'));
        $current = $db->one("SELECT price FROM current_prices WHERE station_id = 1001 AND fuel = 'benzina' AND is_self = 1");
        self::assertEqualsWithDelta(1.729, (float) $current['price'], 1e-9);
        $station = $db->one('SELECT name, last_seen FROM stations WHERE id = 1001');
        self::assertSame('Q8 EASY PACINI', $station['name']);
        self::assertSame('2026-09-25', substr((string) $station['last_seen'], 0, 10));
        self::assertSame(2, self::rows($db, 'imports'));
    }

    public function testReimportDelloStessoGiornoEIdempotente(): void
    {
        $db = self::importedDatabase();
        $result = (new Importer($db, self::config()))->run(
            self::lines('stations_day2.csv'),
            self::lines('prices_day2.csv'),
        );
        self::assertSame(0, $result['new_changes']);
        $n = $db->one("SELECT COUNT(*) AS n FROM national_averages WHERE day = '2026-09-25'")['n'];
        self::assertSame(5, (int) $n); // benzina self/servito, gasolio self, gpl, metano
    }

    public function testMediaSenzaValoriAnomali(): void
    {
        self::assertEqualsWithDelta((1.8 + 1.82 + 1.79) / 3, Importer::trimmedMean([1.8, 1.82, 1.79, 0.18]), 1e-9);
        self::assertNull(Importer::trimmedMean([]));
    }

    // --- Import a blocchi (tabelle di appoggio, file letti a righe) ----------

    /** @return list<string> file anagrafica con le righe date */
    private static function stationFile(string $day, array $rows): array
    {
        return ["Estrazione del $day", 'idImpianto|Gestore|Bandiera|Tipo Impianto|Nome Impianto|Indirizzo|Comune|Provincia|Latitudine|Longitudine', ...$rows];
    }

    /** @return list<string> file prezzi con le righe date */
    private static function priceFile(string $day, array $rows): array
    {
        return ["Estrazione del $day", 'idImpianto|descCarburante|prezzo|isSelf|dtComu', ...$rows];
    }

    private static function station(int $id, string $name = 'IMPIANTO'): string
    {
        return "$id|GESTORE S.R.L.|Q8|Stradale|$name|VIA ROMA 1|MILANO|MI|45.4800|9.2300";
    }

    /** @return array<string, mixed>|null */
    private static function current(Database $db, int $id, string $fuel = 'benzina', int $self = 1): ?array
    {
        $row = $db->one(
            'SELECT price, reported_at FROM current_prices WHERE station_id = ? AND fuel = ? AND is_self = ?',
            [$id, $fuel, $self],
        );
        return $row === null ? null : ['price' => round((float) $row['price'], 3), 'at' => substr((string) $row['reported_at'], 0, 19)];
    }

    private static function assertStagingEmpty(Database $db): void
    {
        self::assertSame(0, self::rows($db, 'import_prices'));
        self::assertSame(0, self::rows($db, 'import_latest'));
    }

    public function testPrezzoRipetutoNelFileValeLUltimoEAPariOraIlPrimo(): void
    {
        $db = self::database();
        $result = (new Importer($db, self::config()))->run(
            self::stationFile('2026-09-24', [self::station(1001)]),
            self::priceFile('2026-09-24', [
                '1001|Benzina|1.700|1|24/09/2026 10:00:00',
                '1001|Benzina|1.750|1|24/09/2026 12:00:00',
                '1001|Benzina|1.720|1|24/09/2026 11:00:00',
                '1001|Benzina|1.800|0|24/09/2026 10:00:00',
                '1001|Benzina|1.810|0|24/09/2026 10:00:00',
            ]),
        );
        self::assertSame(['day' => '2026-09-24', 'stations' => 1, 'prices' => 2, 'new_changes' => 2], $result);
        self::assertSame(['price' => 1.75, 'at' => '2026-09-24 12:00:00'], self::current($db, 1001));
        self::assertSame(['price' => 1.8, 'at' => '2026-09-24 10:00:00'], self::current($db, 1001, 'benzina', 0));
        self::assertSame(2, self::rows($db, 'price_changes'));
        self::assertEqualsWithDelta(1.75, self::average($db, '2026-09-24', 'benzina', 'self'), 1e-9);
        self::assertStagingEmpty($db);
    }

    public function testPrezziAttualiPiuRecentiDelFileRestano(): void
    {
        $db = self::database();
        $importer = new Importer($db, self::config());
        $importer->run(self::lines('stations_day1.csv'), self::lines('prices_day1.csv'));
        // Osservaprezzi dopo l'import: 1001 più recente del file di domani,
        // 1002 più vecchio, 1005 gasolio non c'è nel file.
        $db->execute("UPDATE current_prices SET price = 1.650, reported_at = '2026-09-25 09:00:00' WHERE station_id = 1001 AND fuel = 'benzina' AND is_self = 1");
        $db->execute("UPDATE current_prices SET price = 1.111, reported_at = '2026-01-01 00:00:00' WHERE station_id = 1002 AND fuel = 'benzina' AND is_self = 1");
        $db->execute("INSERT INTO current_prices (station_id, fuel, is_self, price, reported_at) VALUES (1005, 'diesel', 1, 1.6, '2026-09-25 08:00:00')");
        // A pari ora vale il file.
        $db->execute("UPDATE current_prices SET price = 1.222 WHERE station_id = 1003 AND fuel = 'benzina' AND is_self = 1");

        $importer->run(self::lines('stations_day2.csv'), self::lines('prices_day2.csv'));

        self::assertSame(['price' => 1.65, 'at' => '2026-09-25 09:00:00'], self::current($db, 1001));
        self::assertSame(['price' => 1.759, 'at' => '2026-09-20 08:10:00'], self::current($db, 1002));
        self::assertSame(['price' => 1.779, 'at' => '2026-09-24 07:00:00'], self::current($db, 1003));
        self::assertNull(self::current($db, 1005, 'diesel'));
        self::assertSame(11, self::rows($db, 'current_prices'));
        // Nello storico entra comunque il prezzo del file.
        self::assertSame(1, self::rows($db, 'price_changes', "station_id = 1001 AND fuel = 'benzina' AND is_self = 1 AND price > 1.72 AND price < 1.73"));
        self::assertStagingEmpty($db);
    }

    public function testDistributoreRipetutoInAnagraficaValeLUltimaRiga(): void
    {
        $db = self::database();
        $importer = new Importer($db, self::config());
        $result = $importer->run(
            self::stationFile('2026-09-24', [self::station(2001, 'PRIMA'), self::station(2001, 'SECONDA')]),
            self::priceFile('2026-09-24', ['2001|Benzina|1.700|1|24/09/2026 10:00:00']),
        );
        self::assertSame(1, $result['stations']);
        self::assertSame('SECONDA', $db->one('SELECT name FROM stations WHERE id = 2001')['name']);

        $importer->run(
            self::stationFile('2026-09-25', [self::station(2001, 'TERZA'), self::station(2001, 'QUARTA')]),
            self::priceFile('2026-09-25', ['2001|Benzina|1.700|1|24/09/2026 10:00:00']),
        );
        self::assertSame('QUARTA', $db->one('SELECT name FROM stations WHERE id = 2001')['name']);
        self::assertSame(1, self::rows($db, 'stations'));
    }

    public function testFileGrandiAScrittureABlocchi(): void
    {
        // Più righe di Importer::BATCH, sia di anagrafica sia di prezzi.
        $stations = $prices = [];
        for ($id = 1; $id <= 2500; $id++) {
            $stations[] = self::station($id);
            $prices[] = "$id|Benzina|1.800|1|24/09/2026 08:00:00";
            $prices[] = "$id|Gasolio|1.700|1|24/09/2026 08:00:00";
            $prices[] = "$id|Gasolio|1.690|1|24/09/2026 09:00:00"; // più recente
        }
        // Ripetuto dopo che il suo blocco è già stato scritto: si aggiorna.
        $stations[] = self::station(1, 'RIPETUTO');
        $db = self::database();
        $result = (new Importer($db, self::config()))->run(
            self::stationFile('2026-09-24', $stations),
            self::priceFile('2026-09-24', $prices),
        );
        self::assertSame(['day' => '2026-09-24', 'stations' => 2500, 'prices' => 5000, 'new_changes' => 5000], $result);
        self::assertSame(2500, self::rows($db, 'stations'));
        self::assertSame('RIPETUTO', $db->one('SELECT name FROM stations WHERE id = 1')['name']);
        self::assertSame(5000, self::rows($db, 'current_prices'));
        self::assertSame(['price' => 1.69, 'at' => '2026-09-24 09:00:00'], self::current($db, 2500, 'diesel'));
        self::assertEqualsWithDelta(1.69, self::average($db, '2026-09-24', 'diesel', 'self'), 1e-9);
        self::assertStagingEmpty($db);
    }

    public function testImportInterrottoNonLasciaDatiAMeta(): void
    {
        $db = self::importedDatabase();
        $before = array_map(static fn (string $t): int => self::rows($db, $t), ['stations', 'current_prices', 'price_changes', 'national_averages', 'imports']);
        $current = self::current($db, 1001);

        $prices = (static function (): \Generator {
            yield 'Estrazione del 2026-09-26';
            yield 'idImpianto|descCarburante|prezzo|isSelf|dtComu';
            yield '1001|Benzina|1.500|1|26/09/2026 08:00:00';
            throw new \RuntimeException('connessione interrotta');
        })();
        $stations = self::stationFile('2026-09-26', [self::station(1001, 'NUOVO NOME'), self::station(3001)]);

        try {
            (new Importer($db, self::config()))->run($stations, $prices);
            self::fail('eccezione attesa');
        } catch (\RuntimeException $e) {
            self::assertSame('connessione interrotta', $e->getMessage());
        }
        $after = array_map(static fn (string $t): int => self::rows($db, $t), ['stations', 'current_prices', 'price_changes', 'national_averages', 'imports']);
        self::assertSame($before, $after);
        self::assertSame($current, self::current($db, 1001));
        self::assertSame('Q8 EASY PACINI', $db->one('SELECT name FROM stations WHERE id = 1001')['name']);
        self::assertStagingEmpty($db);
    }

    public function testDateDiverseENotaNelLog(): void
    {
        $log = new class extends AbstractLogger {
            /** @var list<array{string, string, array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
        $db = self::database();
        $result = (new Importer($db, self::config(), $log))->run(self::lines('stations_day2.csv'), self::lines('prices_day1.csv'));

        self::assertSame(['warning', 'date diverse: anagrafica 2026-09-25, prezzi 2026-09-24', []], $log->records[0]);
        self::assertSame(['info', 'import completato', $result], $log->records[1]);
        self::assertSame('2026-09-24', $result['day']);
    }

    public function testFileLettiUnaRigaAllaVolta(): void
    {
        $db = self::database();
        $result = (new Importer($db, self::config()))->run(
            Parser::readLines(__DIR__ . '/fixtures/stations_day1.csv'),
            Parser::readLines(__DIR__ . '/fixtures/prices_day1.csv'),
        );
        self::assertSame(['day' => '2026-09-24', 'stations' => 5, 'prices' => 11, 'new_changes' => 11], $result);
    }

    public function testMediaNazionaleDaGeneratoreComeDaArray(): void
    {
        [$day, $prices] = Parser::parsePrices(self::lines('prices_day1.csv'));
        $fromArray = Importer::nationalAverages($prices, $day, 30);
        $fromGenerator = Importer::nationalAverages((static fn () => yield from $prices)(), $day, 30);
        self::assertSame($fromArray, $fromGenerator);
        self::assertNotEmpty($fromArray);
    }

    public function testDownloadSuFileTemporaneo(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], "Estrazione del 2026-09-24\r\nidImpianto|descCarburante|prezzo|isSelf|dtComu\r\n1001|Benzina|1.739|1|23/09/2026 20:00:21"),
        ]));
        $stack->push(Middleware::history($history));

        $lines = Importer::download('https://example.test/prezzi.csv', new Client(['handler' => $stack]));

        // Scaricato subito, non alla prima lettura.
        self::assertCount(1, $history);
        self::assertSame('GET', $history[0]['request']->getMethod());
        self::assertSame('https://example.test/prezzi.csv', (string) $history[0]['request']->getUri());
        self::assertInstanceOf(StreamInterface::class, $history[0]['options']['sink']);
        self::assertSame([
            'Estrazione del 2026-09-24',
            'idImpianto|descCarburante|prezzo|isSelf|dtComu',
            '1001|Benzina|1.739|1|23/09/2026 20:00:21',
        ], iterator_to_array($lines, false));
    }

    public function testDownloadConErroreHttp(): void
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(503)]))]);
        $this->expectException(ServerException::class);
        Importer::download('https://example.test/prezzi.csv', $client);
    }
}
