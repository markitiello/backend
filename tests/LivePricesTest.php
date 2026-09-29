<?php

declare(strict_types=1);

namespace Benzina\Tests;

use Benzina\Database;
use Benzina\Importer;
use Benzina\Live\LivePrices;
use Benzina\Live\OsservaprezziClient;
use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

final class LivePricesTest extends TestCase
{
    private MockHandler $mock;
    /** @var list<array<string, mixed>> */
    private array $sent = [];
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->mock = new MockHandler();
        $this->now = new DateTimeImmutable('2026-09-25 11:00:00');
    }

    private function live(Database $db): LivePrices
    {
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->sent));
        return new LivePrices(
            $db,
            new OsservaprezziClient(new Client(['handler' => $stack])),
            now: fn (): DateTimeImmutable => $this->now,
        );
    }

    /** Risposta nel formato di /ospzApi/search/zone (da una risposta reale). */
    private static function zone(array $results): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'success' => true,
            'center' => ['lat' => 45.47, 'lng' => 9.22],
            'results' => $results,
        ]));
    }

    private static function station(int $id, string $insertDate, array $fuels): array
    {
        return [
            'id' => $id,
            'name' => "PV $id",
            'fuels' => array_map(static fn (array $f): array => [
                'id' => random_int(1, 999999), 'price' => $f[1], 'name' => $f[0], 'fuelId' => 1, 'isSelf' => $f[2],
            ], $fuels),
            'location' => ['lat' => 45.47, 'lng' => 9.22],
            'insertDate' => $insertDate,
            'address' => null,
            'brand' => 'AgipEni',
            'distance' => '1.2',
        ];
    }

    private static function current(Database $db, int $id, string $fuel, int $self): array
    {
        return $db->one('SELECT price, reported_at FROM current_prices WHERE station_id = ? AND fuel = ? AND is_self = ?', [$id, $fuel, $self]);
    }

    private function sampleResponse(): Response
    {
        return self::zone([
            // Benzina self cambiata alle 10:31 di oggi; servito invariato.
            self::station(1001, '2026-09-25T10:31:10+02:00', [
                ['Benzina', 1.699, true], ['Benzina', 1.899, false], ['Blue Diesel', 2.1, true],
            ]),
            // Comunicazione più vecchia di quella del file: ignorata.
            self::station(1002, '2026-01-01T08:00:00+01:00', [['Benzina', 1.5, true]]),
            // Non in anagrafica: ignorato.
            self::station(61952, '2026-09-25T10:31:10+02:00', [['Benzina', 2.139, true]]),
        ]);
    }

    public function testAggiornaSoloIPrezziCambiatiEPiuRecenti(): void
    {
        $db = self::importedDatabase();
        $before = self::current($db, 1002, 'benzina', 1);
        $this->mock->append($this->sampleResponse());

        self::assertSame(1, $this->live($db)->refresh(45.4781, 9.227, 5));

        self::assertEqualsWithDelta(1.699, (float) self::current($db, 1001, 'benzina', 1)['price'], 1e-9);
        self::assertSame('2026-09-25 10:31:10', substr((string) self::current($db, 1001, 'benzina', 1)['reported_at'], 0, 19));
        self::assertSame($before, self::current($db, 1002, 'benzina', 1));
        self::assertNotNull($db->one("SELECT 1 AS x FROM price_changes WHERE station_id = 1001 AND fuel = 'benzina' AND is_self = 1 AND price = 1.699"));
        self::assertNull($db->one('SELECT 1 AS x FROM price_changes WHERE station_id = 61952'));

        $body = json_decode((string) $this->sent[0]['request']->getBody(), true);
        self::assertSame(['points' => [['lat' => 45.4781, 'lng' => 9.227]], 'radius' => 5], $body);
    }

    public function testUnaRichiestaOgniDieciMinutiPerZona(): void
    {
        $db = self::importedDatabase();
        $this->mock->append($this->sampleResponse(), self::zone([]));
        $live = $this->live($db);

        $live->refresh(45.4781, 9.227, 5);
        // Stessa cella (circa 2 km), pochi minuti dopo: nessuna richiesta.
        $this->now = $this->now->modify('+5 minutes');
        self::assertSame(0, $live->refresh(45.4790, 9.228, 5));
        self::assertCount(1, $this->sent);

        $this->now = $this->now->modify('+6 minutes');
        $live->refresh(45.4781, 9.227, 5);
        self::assertCount(2, $this->sent);
    }

    public function testSeOsservaprezziNonRispondeSiResta(): void
    {
        $db = self::importedDatabase();
        $before = self::current($db, 1001, 'benzina', 1);
        // Pagina HTML dell'anti-bot al posto del JSON, poi un 403.
        $this->mock->append(new Response(200, ['Content-Type' => 'text/html'], '<html>Request Rejected</html>'));
        $live = $this->live($db);

        self::assertSame(0, $live->refresh(45.4781, 9.227, 5));
        self::assertSame($before, self::current($db, 1001, 'benzina', 1));
        // Pausa di 5 minuti anche per le altre zone.
        self::assertSame(0, $live->refresh(41.9, 12.5, 5));
        self::assertCount(1, $this->sent);

        $this->now = $this->now->modify('+6 minutes');
        $this->mock->append(new Response(403));
        self::assertSame(0, $live->refresh(41.9, 12.5, 5));
        self::assertCount(2, $this->sent);
    }

    public function testLaRicercaMostraIPrezziAggiornati(): void
    {
        $db = self::importedDatabase();
        $this->mock->append($this->sampleResponse());
        $app = self::app($db, live: $this->live($db));

        [$status, $body] = self::get($app, '/v1/stations/nearby', ['lat' => '45.4781', 'lng' => '9.227', 'radius_km' => '5']);

        self::assertSame(200, $status);
        $offer = array_values(array_filter($body['offers'], static fn (array $o): bool => $o['station']['id'] === 1001))[0];
        self::assertEqualsWithDelta(1.699, $offer['price'], 1e-9);
        self::assertSame('2026-09-25T10:31:10+02:00', $offer['reported_at']);
    }

    public function testDettaglioDistributoreConOraPerCarburante(): void
    {
        $db = self::importedDatabase();
        // Formato di /ospzApi/registry/servicearea/{id} (da una risposta reale).
        $this->mock->append(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 1001,
            'name' => 'AGIP/ENI 00515 GAS PETROL SERVICE',
            'fuels' => [
                ['id' => 1, 'price' => 1.719, 'name' => 'Benzina', 'fuelId' => 1, 'isSelf' => true, 'serviceAreaId' => 1001,
                    'insertDate' => '2026-09-25T08:36:21Z', 'validityDate' => '2026-09-25T08:36:20Z'],
                ['id' => 2, 'price' => 0.739, 'name' => 'GPL', 'fuelId' => 4, 'isSelf' => false, 'serviceAreaId' => 1001,
                    'insertDate' => '2026-09-25T06:02:37Z', 'validityDate' => '2026-09-25T06:02:37Z'],
                ['id' => 3, 'price' => 2.4, 'name' => 'HVOlution', 'fuelId' => 394, 'isSelf' => false, 'serviceAreaId' => 1001,
                    'insertDate' => '2026-09-25T08:36:20Z', 'validityDate' => '2026-09-25T08:36:20Z'],
            ],
            'phoneNumber' => '366 6286969',
            'email' => 'gaspetrolservice@libero.it',
            'website' => '',
            'services' => [
                ['id' => '6', 'description' => 'Bancomat'],
                ['id' => '1', 'description' => 'Food&Beverage'],
                ['id' => '8', 'description' => 'Wi-Fi'],
            ],
            'orariapertura' => [
                self::day(1, continuous: ['07:00', '18:30']),
                self::day(6, continuous: ['07:30', '12:00']),
                self::day(7, closed: true),
                self::day(3, morning: ['08:00', '12:30'], afternoon: ['15:00', '19:00']),
                self::day(4, h24: true),
                self::day(5, notCommunicated: true),
                // Festivi: nessun orario indicato.
                self::day(8),
            ],
        ])));
        $app = self::app($db, live: $this->live($db));

        [$status, $body] = self::get($app, '/v1/stations/1001');

        self::assertSame(200, $status);
        self::assertSame('https://carburanti.mise.gov.it/ospzApi/registry/servicearea/1001', (string) $this->sent[0]['request']->getUri());
        $prices = [];
        foreach ($body['prices'] as $p) {
            $prices[$p['fuel'] . '|' . $p['mode']] = $p;
        }
        self::assertEqualsWithDelta(1.719, $prices['benzina|self']['price'], 1e-9);
        // Ore UTC convertite in ora italiana, una per carburante.
        self::assertSame('2026-09-25T10:36:21+02:00', $prices['benzina|self']['reported_at']);
        self::assertSame('2026-09-25T08:02:37+02:00', $prices['gpl|servito']['reported_at']);

        self::assertSame([
            'phone' => '366 6286969',
            'email' => 'gaspetrolservice@libero.it',
            'website' => null,
            'services' => ['Bancomat', 'Food&Beverage', 'Wi-Fi'],
            'opening_hours' => [
                ['day' => 1, 'hours' => '07:00–18:30'],
                ['day' => 3, 'hours' => '08:00–12:30, 15:00–19:00'],
                ['day' => 4, 'hours' => '24 ore'],
                ['day' => 6, 'hours' => '07:30–12:00'],
                ['day' => 7, 'hours' => 'Chiuso'],
            ],
        ], $body['details']);
        // Anche nella lista dei preferiti.
        [, $list] = self::get($app, '/v1/stations', ['ids' => '1001,1002']);
        self::assertSame('366 6286969', $list['stations'][0]['details']['phone']);
        self::assertNull($list['stations'][1]['details']);
    }

    /** Un giorno di "orariapertura" come nella risposta di Osservaprezzi. */
    private static function day(
        int $id,
        ?array $continuous = null,
        ?array $morning = null,
        ?array $afternoon = null,
        bool $closed = false,
        bool $h24 = false,
        bool $notCommunicated = false,
    ): array {
        return [
            'orariAperturaId' => 80000 + $id,
            'giornoSettimanaId' => $id,
            'oraAperturaMattina' => $morning[0] ?? null,
            'oraChiusuraMattina' => $morning[1] ?? null,
            'oraAperturaPomeriggio' => $afternoon[0] ?? null,
            'oraChiusuraPomeriggio' => $afternoon[1] ?? null,
            'flagOrarioContinuato' => $continuous !== null,
            'oraAperturaOrarioContinuato' => $continuous[0] ?? null,
            'oraChiusuraOrarioContinuato' => $continuous[1] ?? null,
            'flagH24' => $h24,
            'flagChiusura' => $closed,
            'flagNonComunicato' => $notCommunicated,
            'flagServito' => false,
            'flagSelf' => true,
        ];
    }

    public function testLImportDelleOttoNonCancellaIPrezziPiuRecenti(): void
    {
        $db = self::importedDatabase();
        $this->mock->append($this->sampleResponse());
        $this->live($db)->refresh(45.4781, 9.227, 5);

        // Il file del giorno dopo ha ancora il prezzo vecchio per 1001.
        (new Importer($db, self::config()))->run(self::lines('stations_day2.csv'), self::lines('prices_day2.csv'));

        self::assertEqualsWithDelta(1.699, (float) self::current($db, 1001, 'benzina', 1)['price'], 1e-9);
    }
}
