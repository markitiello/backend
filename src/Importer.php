<?php

declare(strict_types=1);

namespace Benzina;

use Benzina\Mimit\Parser;
use Benzina\Mimit\PriceRow;
use DateTimeImmutable;
use GuzzleHttp\Client;
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
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly LoggerInterface $log = new NullLogger(),
    ) {
    }

    /**
     * @param iterable<string> $stationLines
     * @param iterable<string> $priceLines
     * @return array{day: string, stations: int, prices: int, new_changes: int}
     */
    public function run(iterable $stationLines, iterable $priceLines): array
    {
        [$stationsDay, $stations] = Parser::parseStations($stationLines);
        [$day, $prices] = Parser::parsePrices($priceLines);
        if ($stationsDay !== $day) {
            $this->log->warning("date diverse: anagrafica $stationsDay, prezzi $day");
        }

        $known = [];
        foreach ($stations as $s) {
            $known[$s->id] = true;
        }
        $prices = array_values(array_filter(
            self::latestPerKey($prices),
            static fn (PriceRow $p): bool => isset($known[$p->stationId]),
        ));

        $newChanges = $this->db->transaction(function () use ($stations, $prices, $day): int {
            $existing = [];
            foreach ($this->db->all('SELECT id FROM stations') as $row) {
                $existing[(int) $row['id']] = true;
            }
            $new = [];
            $update = $this->db->pdo->prepare(
                'UPDATE stations SET operator = ?, brand = ?, kind = ?, name = ?, address = ?,
                 city = ?, province = ?, lat = ?, lng = ?, last_seen = ? WHERE id = ?'
            );
            foreach ($stations as $s) {
                $values = [$s->operator, $s->brand, $s->kind, $s->name, $s->address,
                    $s->city, $s->province, $s->lat, $s->lng, $day];
                if (isset($existing[$s->id])) {
                    $update->execute([...$values, $s->id]);
                } else {
                    $new[] = [$s->id, ...$values];
                }
            }
            $this->db->insertMany(
                'stations',
                ['id', 'operator', 'brand', 'kind', 'name', 'address', 'city', 'province', 'lat', 'lng', 'last_seen'],
                $new,
            );

            $rows = array_map(
                static fn (PriceRow $p): array => [$p->stationId, $p->fuel->value, (int) $p->isSelf, $p->price, $p->reportedAt],
                $prices,
            );
            $columns = ['station_id', 'fuel', 'is_self', 'price', 'reported_at'];
            $this->db->execute('DELETE FROM current_prices');
            $this->db->insertMany('current_prices', $columns, $rows);
            $newChanges = $this->db->insertMany('price_changes', $columns, $rows, ignoreDuplicates: true);

            $this->db->execute('DELETE FROM national_averages WHERE day = ?', [$day]);
            $this->db->insertMany(
                'national_averages',
                ['day', 'fuel', 'mode', 'price', 'stations'],
                self::nationalAverages($prices, $day, $this->config->averageMaxAgeDays),
            );

            $this->db->execute('DELETE FROM imports WHERE day = ?', [$day]);
            $this->db->insertMany(
                'imports',
                ['day', 'stations', 'prices', 'finished_at'],
                [[$day, count($stations), count($prices), (new DateTimeImmutable())->format('Y-m-d H:i:s')]],
            );
            return $newChanges;
        });

        $result = ['day' => $day, 'stations' => count($stations), 'prices' => count($prices), 'new_changes' => $newChanges];
        $this->log->info('import completato', $result);
        return $result;
    }

    /**
     * Se un distributore compare due volte per lo stesso carburante e modalità,
     * vale il prezzo comunicato per ultimo.
     *
     * @param list<PriceRow> $prices
     * @return list<PriceRow>
     */
    private static function latestPerKey(array $prices): array
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
     * @param list<PriceRow> $prices
     * @return list<list<mixed>> righe [day, fuel, mode, price, stations]
     */
    public static function nationalAverages(array $prices, string $day, int $maxAgeDays): array
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

    /** @return list<string> */
    public static function download(string $url, ?Client $http = null): array
    {
        $http ??= new Client(['timeout' => 120]);
        $body = (string) $http->get($url)->getBody();
        return preg_split('/\r\n|\n/', $body) ?: [];
    }
}
