<?php

declare(strict_types=1);

namespace Benzina;

use DateTimeImmutable;

/**
 * Interrogazioni sui dati importati. Restituisce array pronti per il JSON,
 * nella forma descritta da docs/openapi.yaml.
 */
final class PriceService
{
    public function __construct(private readonly Database $db)
    {
    }

    public function latestImportDay(): ?string
    {
        $row = $this->db->one('SELECT MAX(day) AS day FROM imports');
        return $row['day'] ?? null;
    }

    /** @return array<int, array{row: array<string, mixed>, distance: float}> */
    private function stationsInRadius(float $lat, float $lng, float $radiusKm): array
    {
        [$minLat, $maxLat, $minLng, $maxLng] = Geo::boundingBox($lat, $lng, $radiusKm);
        $rows = $this->db->all(
            'SELECT * FROM stations WHERE lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?',
            [$minLat, $maxLat, $minLng, $maxLng],
        );
        $result = [];
        foreach ($rows as $row) {
            $d = Geo::distanceKm($lat, $lng, (float) $row['lat'], (float) $row['lng']);
            if ($d <= $radiusKm) {
                $result[(int) $row['id']] = ['row' => $row, 'distance' => $d];
            }
        }
        return $result;
    }

    /** @return array{string, list<int>} condizione SQL e parametri per la modalità */
    private static function modeFilter(Mode $mode, string $column = 'is_self'): array
    {
        return match ($mode) {
            Mode::Any => ['1 = 1', []],
            Mode::Self => ["$column = ?", [1]],
            Mode::Servito => ["$column = ?", [0]],
        };
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function summary(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'brand' => $row['brand'],
            'name' => $row['name'],
            'address' => $row['address'],
            'city' => $row['city'],
            'province' => $row['province'],
            'lat' => (float) $row['lat'],
            'lng' => (float) $row['lng'],
        ];
    }

    /** Gli orari MIMIT sono in ora italiana: si restituiscono con il fuso (RFC 3339). */
    private static function isoDateTime(string $value): string
    {
        return (new DateTimeImmutable($value, new \DateTimeZone('Europe/Rome')))->format(DATE_RFC3339);
    }

    /** @return array<string, mixed> */
    public function nearby(float $lat, float $lng, float $radiusKm, Fuel $fuel, Mode $mode, int $limit): array
    {
        $inRadius = $this->stationsInRadius($lat, $lng, $radiusKm);
        $best = [];
        foreach (array_chunk(array_keys($inRadius), 500) as $ids) {
            [$modeSql, $modeParams] = self::modeFilter($mode);
            $prices = $this->db->all(
                'SELECT * FROM current_prices WHERE station_id IN (' . Database::placeholders(count($ids)) . ")
                 AND fuel = ? AND $modeSql",
                [...$ids, $fuel->value, ...$modeParams],
            );
            foreach ($prices as $p) {
                $id = (int) $p['station_id'];
                // GPL/metano: se ci sono self e servito si tiene il più basso.
                if (!isset($best[$id]) || (float) $p['price'] < (float) $best[$id]['price']) {
                    $best[$id] = $p;
                }
            }
        }

        $offers = [];
        foreach ($best as $id => $p) {
            $offers[] = [
                'station' => self::summary($inRadius[$id]['row']),
                'price' => (float) $p['price'],
                'mode' => Mode::fromIsSelf($p['is_self'])->value,
                'reported_at' => self::isoDateTime((string) $p['reported_at']),
                'distance_km' => round($inRadius[$id]['distance'], 3),
            ];
        }
        usort($offers, static fn (array $a, array $b): int => [$a['price'], $a['distance_km']] <=> [$b['price'], $b['distance_km']]);

        $day = $this->latestImportDay();
        $average = $day === null ? null : $this->db->one(
            'SELECT price FROM national_averages WHERE day = ? AND fuel = ? AND mode = ?',
            [$day, $fuel->value, $mode->value],
        );
        return [
            'fuel' => $fuel->value,
            'mode' => $mode->value,
            'data_date' => $day,
            'national_average' => $average === null ? null : (float) $average['price'],
            'offers' => array_slice($offers, 0, $limit),
        ];
    }

    /**
     * Distributori con i prezzi attuali, nell'ordine di $ids. Gli id inesistenti
     * vengono omessi.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function stationsById(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = Database::placeholders(count($ids));
        $stations = [];
        foreach ($this->db->all("SELECT * FROM stations WHERE id IN ($in)", $ids) as $row) {
            $stations[(int) $row['id']] = $row;
        }
        $prices = [];
        $rows = $this->db->all(
            "SELECT * FROM current_prices WHERE station_id IN ($in) ORDER BY fuel, is_self DESC",
            $ids,
        );
        foreach ($rows as $p) {
            $prices[(int) $p['station_id']][] = [
                'fuel' => $p['fuel'],
                'mode' => Mode::fromIsSelf($p['is_self'])->value,
                'price' => (float) $p['price'],
                'reported_at' => self::isoDateTime((string) $p['reported_at']),
            ];
        }
        $details = [];
        foreach ($this->db->all("SELECT station_id, details FROM station_details WHERE station_id IN ($in)", $ids) as $d) {
            $decoded = json_decode((string) $d['details'], true);
            if (is_array($decoded)) {
                $details[(int) $d['station_id']] = $decoded;
            }
        }
        $result = [];
        foreach ($ids as $id) {
            if (!isset($stations[$id])) {
                continue;
            }
            $row = $stations[$id];
            $result[] = self::summary($row) + [
                'operator' => $row['operator'],
                'kind' => $row['kind'],
                'prices' => $prices[$id] ?? [],
                // Orari, servizi e contatti: solo se già letti da Osservaprezzi.
                'details' => $details[$id] ?? null,
            ];
        }
        return $result;
    }

    public function stationExists(int $id): bool
    {
        return $this->db->one('SELECT id FROM stations WHERE id = ?', [$id]) !== null;
    }

    /** @return list<string> giorni 'Y-m-d' dal più vecchio, fino a $end incluso */
    private function window(int $days): array
    {
        $end = new DateTimeImmutable($this->latestImportDay() ?? 'today');
        $result = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $result[] = $end->modify("-$i days")->format('Y-m-d');
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public function nationalTrend(Fuel $fuel, Mode $mode, int $days): array
    {
        $window = $this->window($days);
        $rows = $this->db->all(
            'SELECT day, price FROM national_averages
             WHERE fuel = ? AND mode = ? AND day BETWEEN ? AND ? ORDER BY day',
            [$fuel->value, $mode->value, $window[0], end($window)],
        );
        return self::trend($fuel, $mode, array_map(
            static fn (array $r): array => ['day' => substr((string) $r['day'], 0, 10), 'price' => round((float) $r['price'], 3)],
            $rows,
        ));
    }

    /**
     * Prezzo di ogni distributore a fine giornata, ricostruito dallo storico delle
     * variazioni: l'ultimo prezzo comunicato fino a quel giorno.
     *
     * @param list<int> $stationIds
     * @param list<string> $days
     * @return array<int, array<string, float>> station_id => [giorno => prezzo]
     */
    public function dailyPrices(array $stationIds, Fuel $fuel, Mode $mode, array $days): array
    {
        if ($stationIds === [] || $days === []) {
            return [];
        }
        $start = $days[0] . ' 00:00:00';
        $end = end($days) . ' 23:59:59';
        [$modeSql, $modeParams] = self::modeFilter($mode, 'pc.is_self');
        $changes = [];
        foreach (array_chunk($stationIds, 500) as $ids) {
            $in = Database::placeholders(count($ids));
            $base = [...$ids, $fuel->value, ...$modeParams];
            // Ultimo prezzo prima della finestra, per ogni distributore e modalità...
            $before = $this->db->all(
                "SELECT pc.* FROM price_changes pc JOIN (
                    SELECT station_id, is_self, MAX(reported_at) AS at FROM price_changes pc
                    WHERE station_id IN ($in) AND fuel = ? AND $modeSql AND reported_at < ?
                    GROUP BY station_id, is_self
                 ) lastp ON pc.station_id = lastp.station_id AND pc.is_self = lastp.is_self
                    AND pc.reported_at = lastp.at AND pc.fuel = ?",
                [...$base, $start, $fuel->value],
            );
            // ...e tutte le variazioni dentro la finestra.
            $inside = $this->db->all(
                "SELECT * FROM price_changes pc WHERE station_id IN ($in) AND fuel = ? AND $modeSql
                 AND reported_at BETWEEN ? AND ?",
                [...$base, $start, $end],
            );
            foreach ([...$before, ...$inside] as $c) {
                $changes[$c['station_id'] . '|' . $c['is_self']][] = $c;
            }
        }

        $result = [];
        foreach ($changes as $key => $series) {
            usort($series, static fn (array $a, array $b): int => strcmp((string) $a['reported_at'], (string) $b['reported_at']));
            $stationId = (int) explode('|', $key)[0];
            $i = 0;
            $current = null;
            foreach ($days as $day) {
                $dayEnd = $day . ' 23:59:59';
                while ($i < count($series) && substr((string) $series[$i]['reported_at'], 0, 19) <= $dayEnd) {
                    $current = (float) $series[$i]['price'];
                    $i++;
                }
                if ($current === null) {
                    continue;
                }
                $previous = $result[$stationId][$day] ?? null;
                $result[$stationId][$day] = $previous === null ? $current : min($previous, $current);
            }
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public function stationTrend(int $stationId, Fuel $fuel, Mode $mode, int $days): array
    {
        $window = $this->window($days);
        $prices = $this->dailyPrices([$stationId], $fuel, $mode, $window)[$stationId] ?? [];
        $points = [];
        foreach ($window as $day) {
            if (isset($prices[$day])) {
                $points[] = ['day' => $day, 'price' => $prices[$day]];
            }
        }
        return self::trend($fuel, $mode, $points);
    }

    /** @return array<string, mixed> */
    public function areaTrend(float $lat, float $lng, float $radiusKm, Fuel $fuel, Mode $mode, int $days): array
    {
        $window = $this->window($days);
        $perStation = $this->dailyPrices(array_keys($this->stationsInRadius($lat, $lng, $radiusKm)), $fuel, $mode, $window);
        $points = [];
        foreach ($window as $day) {
            $values = [];
            foreach ($perStation as $prices) {
                if (isset($prices[$day])) {
                    $values[] = $prices[$day];
                }
            }
            if ($values !== []) {
                $points[] = ['day' => $day, 'price' => round(array_sum($values) / count($values), 3)];
            }
        }
        return self::trend($fuel, $mode, $points);
    }

    /**
     * @param list<array{day: string, price: float}> $points
     * @return array<string, mixed>
     */
    private static function trend(Fuel $fuel, Mode $mode, array $points): array
    {
        return ['fuel' => $fuel->value, 'mode' => $mode->value, 'points' => $points];
    }
}
