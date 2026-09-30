<?php

declare(strict_types=1);

namespace Benzina;

use Benzina\Mimit\Parser;
use Benzina\Mimit\PriceRow;
use Benzina\Mimit\StationRow;
use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Utils;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Import giornaliero degli open data MIMIT (bin/import.php).
 *
 * - aggiorna l'anagrafica dei distributori;
 * - sostituisce i prezzi attuali;
 * - aggiunge allo storico solo i prezzi cambiati;
 * - calcola la media nazionale del giorno.
 */
final class Importer
{
    /** Righe scritte nel database per volta: la memoria usata dipende da qui, non dal file. */
    private const BATCH = 1000;
    private const PRICE_COLUMNS = ['station_id', 'fuel', 'is_self', 'price', 'reported_at'];
    private const STATION_COLUMNS = ['id', 'operator', 'brand', 'kind', 'name', 'address', 'city', 'province', 'lat', 'lng', 'last_seen'];

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly LoggerInterface $log = new NullLogger(),
    ) {
    }

    /**
     * I file si leggono una riga alla volta e i prezzi passano dalle tabelle
     * import_prices e import_latest: la memoria resta di pochi MB anche con
     * oltre 100.000 prezzi (gli hosting condivisi danno spesso 128 MB).
     *
     * @param iterable<string> $stationLines
     * @param iterable<string> $priceLines
     * @return array{day: string, stations: int, prices: int, new_changes: int}
     */
    public function run(iterable $stationLines, iterable $priceLines): array
    {
        // Data e intestazione di entrambi i file prima di toccare il database.
        [$stationsDay, $stations] = Parser::stations($stationLines);
        [$day, $prices] = Parser::prices($priceLines);
        if ($stationsDay !== $day) {
            $this->log->warning("date diverse: anagrafica $stationsDay, prezzi $day");
        }

        $result = $this->db->transaction(function () use ($stations, $prices, $day): array {
            $known = $this->saveStations($stations, $day);
            $this->stagePrices($prices, $known);
            $stationCount = count($known);
            unset($known);
            $priceCount = (int) $this->db->one('SELECT COUNT(*) AS n FROM import_latest')['n'];

            $this->db->execute('DELETE FROM national_averages WHERE day = ?', [$day]);
            $this->db->insertMany(
                'national_averages',
                ['day', 'fuel', 'mode', 'price', 'stations'],
                self::nationalAverages($this->latestPrices(), $day, $this->config->averageMaxAgeDays),
            );

            // Prezzi comunicati dopo quelli del file (da Osservaprezzi, vedi
            // Live\LivePrices): restano quelli, non si torna indietro. Gli altri
            // prezzi attuali sono sostituiti da quelli del file.
            $sameKey = 'l.station_id = current_prices.station_id AND l.fuel = current_prices.fuel AND l.is_self = current_prices.is_self';
            $this->db->execute(
                "DELETE FROM current_prices WHERE NOT EXISTS (
                    SELECT 1 FROM import_latest l WHERE $sameKey AND l.reported_at < current_prices.reported_at
                 )"
            );
            $columns = implode(', ', self::PRICE_COLUMNS);
            $this->db->insertFrom('current_prices', self::PRICE_COLUMNS,
                "SELECT $columns FROM import_latest l WHERE NOT EXISTS (
                    SELECT 1 FROM current_prices c
                    WHERE c.station_id = l.station_id AND c.fuel = l.fuel AND c.is_self = l.is_self
                 )",
            );
            $newChanges = $this->db->insertFrom('price_changes', self::PRICE_COLUMNS,
                "SELECT $columns FROM import_latest", ignoreDuplicates: true);

            $this->db->execute('DELETE FROM imports WHERE day = ?', [$day]);
            $this->db->insertMany(
                'imports',
                ['day', 'stations', 'prices', 'finished_at'],
                [[$day, $stationCount, $priceCount, (new DateTimeImmutable())->format('Y-m-d H:i:s')]],
            );
            $this->clearStaging();
            return ['day' => $day, 'stations' => $stationCount, 'prices' => $priceCount, 'new_changes' => $newChanges];
        });

        $this->log->info('import completato', $result);
        return $result;
    }

    /**
     * Anagrafica: aggiorna i distributori già presenti e aggiunge i nuovi.
     *
     * @param iterable<StationRow> $stations
     * @return array<int, true> distributori del file
     */
    private function saveStations(iterable $stations, string $day): array
    {
        $existing = [];
        foreach ($this->db->each('SELECT id FROM stations') as $row) {
            $existing[(int) $row['id']] = true;
        }
        $update = $this->db->pdo->prepare(
            'UPDATE stations SET operator = ?, brand = ?, kind = ?, name = ?, address = ?,
             city = ?, province = ?, lat = ?, lng = ?, last_seen = ? WHERE id = ?'
        );
        $known = [];
        $new = [];
        foreach ($stations as $s) {
            $values = [$s->operator, $s->brand, $s->kind, $s->name, $s->address,
                $s->city, $s->province, $s->lat, $s->lng, $day];
            $known[$s->id] = true;
            if (isset($existing[$s->id])) {
                $update->execute([...$values, $s->id]);
                continue;
            }
            // Un id ripetuto nel file: vale l'ultima riga, come per quelli già presenti.
            $new[$s->id] = [$s->id, ...$values];
            if (count($new) >= self::BATCH) {
                $this->db->insertMany('stations', self::STATION_COLUMNS, array_values($new));
                $existing += array_fill_keys(array_keys($new), true);
                $new = [];
            }
        }
        $this->db->insertMany('stations', self::STATION_COLUMNS, array_values($new));
        return $known;
    }

    /**
     * Prezzi del file dei distributori in anagrafica, a blocchi in import_prices;
     * poi in import_latest l'ultimo per distributore, carburante e modalità
     * (a parità di ora, il primo del file).
     *
     * @param iterable<PriceRow> $prices
     * @param array<int, true> $known
     */
    private function stagePrices(iterable $prices, array $known): void
    {
        $this->clearStaging();
        $columns = ['seq', ...self::PRICE_COLUMNS];
        $batch = [];
        $seq = 0;
        foreach ($prices as $p) {
            if (!isset($known[$p->stationId])) {
                continue;
            }
            $batch[] = [++$seq, $p->stationId, $p->fuel->value, (int) $p->isSelf, $p->price, $p->reportedAt];
            if (count($batch) >= self::BATCH) {
                $this->db->insertMany('import_prices', $columns, $batch);
                $batch = [];
            }
        }
        $this->db->insertMany('import_prices', $columns, $batch);

        $this->db->insertFrom('import_latest', self::PRICE_COLUMNS,
            'SELECT s.station_id, s.fuel, s.is_self, s.price, s.reported_at FROM import_prices s
             WHERE NOT EXISTS (
                SELECT 1 FROM import_prices t
                WHERE t.station_id = s.station_id AND t.fuel = s.fuel AND t.is_self = s.is_self
                  AND (t.reported_at > s.reported_at OR (t.reported_at = s.reported_at AND t.seq < s.seq))
             )',
        );
    }

    /** @return \Generator<int, PriceRow> */
    private function latestPrices(): \Generator
    {
        foreach ($this->db->each('SELECT station_id, fuel, is_self, price, reported_at FROM import_latest') as $r) {
            yield new PriceRow(
                stationId: (int) $r['station_id'],
                fuel: Fuel::from((string) $r['fuel']),
                isSelf: (int) $r['is_self'] === 1,
                price: (float) $r['price'],
                reportedAt: substr((string) $r['reported_at'], 0, 19),
            );
        }
    }

    private function clearStaging(): void
    {
        $this->db->execute('DELETE FROM import_prices');
        $this->db->execute('DELETE FROM import_latest');
    }

    /**
     * Se un distributore compare due volte per lo stesso carburante e modalità,
     * vale il prezzo comunicato per ultimo.
     *
     * @param list<PriceRow> $prices
     * @return list<PriceRow>
     */
    public static function latestPerKey(array $prices): array
    {
        $latest = [];
        foreach ($prices as $p) {
            $key = $p->stationId . '|' . $p->fuel->value . '|' . (int) $p->isSelf;
            if (!isset($latest[$key]) || $p->reportedAt > $latest[$key]->reportedAt) {
                $latest[$key] = $p;
            }
        }
        return array_values($latest);
    }

    /**
     * Media escludendo i valori lontani più del 30% dalla mediana
     * (errori di inserimento dei gestori).
     *
     * @param list<float> $values
     */
    public static function trimmedMean(array $values, float $tolerance = 0.3): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $median = $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
        $kept = array_filter($values, static fn (float $v): bool => abs($v - $median) <= $median * $tolerance);
        return $kept === [] ? null : array_sum($kept) / count($kept);
    }

    /**
     * Medie nazionali del giorno per carburante e modalità. Esclusi i prezzi
     * comunicati da più di $maxAgeDays giorni.
     *
     * @param iterable<PriceRow> $prices
     * @return list<list<mixed>> righe [day, fuel, mode, price, stations]
     */
    public static function nationalAverages(iterable $prices, string $day, int $maxAgeDays): array
    {
        $oldest = (new DateTimeImmutable($day))->modify("-$maxAgeDays days")->format('Y-m-d 00:00:00');
        $groups = [];
        $anyMode = []; // GPL e metano: un prezzo per distributore, il più basso
        foreach ($prices as $p) {
            if ($p->reportedAt < $oldest) {
                continue;
            }
            if ($p->fuel->hasServiceModes()) {
                $groups[$p->fuel->value . '|' . Mode::fromIsSelf($p->isSelf)->value][] = $p->price;
            } else {
                $key = $p->fuel->value . '|' . $p->stationId;
                $anyMode[$key] = min($p->price, $anyMode[$key] ?? $p->price);
            }
        }
        foreach ($anyMode as $key => $price) {
            $groups[explode('|', $key)[0] . '|' . Mode::Any->value][] = $price;
        }
        ksort($groups);

        $rows = [];
        foreach ($groups as $key => $values) {
            [$fuel, $mode] = explode('|', $key);
            $avg = self::trimmedMean($values);
            if ($avg !== null) {
                $rows[] = [$day, $fuel, $mode, round($avg, 4), count($values)];
            }
        }
        return $rows;
    }

    /**
     * Scarica un file MIMIT in un file temporaneo (non in memoria) e ne
     * restituisce le righe, lette una alla volta. Il file temporaneo sparisce
     * quando le righe sono state lette.
     *
     * @return \Generator<int, string>
     */
    public static function download(string $url, ?ClientInterface $http = null): \Generator
    {
        $http ??= new Client(['timeout' => 120]);
        $file = tmpfile();
        if ($file === false) {
            throw new \RuntimeException('impossibile creare un file temporaneo per il download');
        }
        // Uno stream nostro: Guzzle ci scrive il corpo della risposta e, staccato
        // con detach(), non lo chiude quando la risposta viene distrutta.
        $sink = Utils::streamFor($file);
        $http->request('GET', $url, ['sink' => $sink]);
        $sink->rewind();
        return Parser::readLines($sink->detach());
    }
}
