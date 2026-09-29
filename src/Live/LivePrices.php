<?php

declare(strict_types=1);

namespace Benzina\Live;

use Benzina\Database;
use Benzina\ErrorLog;
use Benzina\Importer;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Aggiorna prezzi attuali e storico con i dati di Osservaprezzi per la zona
 * richiesta, prima di rispondere dal database.
 *
 * - una richiesta a Osservaprezzi al massimo ogni $ttlMinutes per zona
 *   (celle di circa 2 km), qualunque sia il numero di utenti;
 * - si salvano solo i prezzi cambiati e più recenti di quelli noti;
 * - se Osservaprezzi non risponde si resta sui dati del file delle 8 e per
 *   $pauseMinutes non si riprova.
 */
final class LivePrices
{
    /** Lato delle celle in gradi (circa 2 km). */
    private const CELL = 0.02;
    private const ERROR_KEY = 'errore';

    public function __construct(
        private readonly Database $db,
        private readonly OsservaprezziClient $client,
        private readonly int $ttlMinutes = 10,
        private readonly int $pauseMinutes = 5,
        private readonly ?\Closure $now = null,
    ) {
    }

    /** @return int prezzi aggiornati */
    public function refresh(float $lat, float $lng, float $radiusKm): int
    {
        $radius = (int) ceil(min($radiusKm, OsservaprezziClient::MAX_RADIUS_KM));
        $cell = sprintf('%.2f|%.2f|%d', round($lat / self::CELL) * self::CELL, round($lng / self::CELL) * self::CELL, $radius);
        $now = $this->now();
        if ($this->recent(self::ERROR_KEY, $now, $this->pauseMinutes) || $this->recent($cell, $now, $this->ttlMinutes)) {
            return 0;
        }
        try {
            $rows = $this->client->nearby($lat, $lng, $radius);
        } catch (OsservaprezziException $e) {
            ErrorLog::write('benzina: ' . $e->getMessage());
            $this->mark(self::ERROR_KEY, $now);
            return 0;
        }
        $updated = $this->store($rows);
        $this->mark($cell, $now);
        return $updated;
    }

    /** Prezzi di un distributore, per la pagina di dettaglio. @return int prezzi aggiornati */
    public function refreshStation(int $id): int
    {
        $key = "impianto|$id";
        $now = $this->now();
        if ($this->recent(self::ERROR_KEY, $now, $this->pauseMinutes) || $this->recent($key, $now, $this->ttlMinutes)) {
            return 0;
        }
        try {
            $station = $this->client->station($id);
        } catch (OsservaprezziException $e) {
            ErrorLog::write('benzina: ' . $e->getMessage());
            $this->mark(self::ERROR_KEY, $now);
            return 0;
        }
        $updated = $this->store($station['prices']);
        $this->db->transaction(function () use ($id, $station, $now): void {
            $this->db->execute('DELETE FROM station_details WHERE station_id = ?', [$id]);
            $this->db->insertMany('station_details', ['station_id', 'details', 'fetched_at'], [
                [$id, json_encode($station['details'], JSON_UNESCAPED_UNICODE), $now->format('Y-m-d H:i:s')],
            ]);
        });
        $this->mark($key, $now);
        return $updated;
    }

    /** @param list<\Benzina\Mimit\PriceRow> $rows */
    private function store(array $rows): int
    {
        $rows = Importer::latestPerKey($rows);
        if ($rows === []) {
            return 0;
        }
        $ids = array_values(array_unique(array_map(static fn ($r): int => $r->stationId, $rows)));
        $in = Database::placeholders(count($ids));
        $known = [];
        foreach ($this->db->all("SELECT id FROM stations WHERE id IN ($in)", $ids) as $s) {
            $known[(int) $s['id']] = true;
        }
        $current = [];
        foreach ($this->db->all("SELECT * FROM current_prices WHERE station_id IN ($in)", $ids) as $c) {
            $current[$c['station_id'] . '|' . $c['fuel'] . '|' . (int) $c['is_self']] = $c;
        }

        $changed = [];
        foreach ($rows as $r) {
            $key = $r->stationId . '|' . $r->fuel->value . '|' . (int) $r->isSelf;
            $old = $current[$key] ?? null;
            if (!isset($known[$r->stationId])) {
                continue;
            }
            // insertDate vale per tutto il distributore: un prezzo uguale non è
            // una nuova comunicazione di quel carburante.
            if ($old !== null && (abs((float) $old['price'] - $r->price) < 0.0005
                || substr((string) $old['reported_at'], 0, 19) >= $r->reportedAt)) {
                continue;
            }
            $changed[] = $r;
        }
        if ($changed === []) {
            return 0;
        }
        $this->db->transaction(function () use ($changed): void {
            $columns = ['station_id', 'fuel', 'is_self', 'price', 'reported_at'];
            $values = array_map(
                static fn ($r): array => [$r->stationId, $r->fuel->value, (int) $r->isSelf, $r->price, $r->reportedAt],
                $changed,
            );
            foreach ($changed as $r) {
                $this->db->execute(
                    'DELETE FROM current_prices WHERE station_id = ? AND fuel = ? AND is_self = ?',
                    [$r->stationId, $r->fuel->value, (int) $r->isSelf],
                );
            }
            $this->db->insertMany('current_prices', $columns, $values);
            $this->db->insertMany('price_changes', $columns, $values, ignoreDuplicates: true);
        });
        return count($changed);
    }

    private function recent(string $key, DateTimeImmutable $now, int $minutes): bool
    {
        $row = $this->db->one('SELECT fetched_at FROM live_fetches WHERE cell = ?', [$key]);
        return $row !== null
            && substr((string) $row['fetched_at'], 0, 19) > $now->modify("-$minutes minutes")->format('Y-m-d H:i:s');
    }

    private function mark(string $key, DateTimeImmutable $now): void
    {
        $this->db->execute('DELETE FROM live_fetches WHERE cell = ?', [$key]);
        $this->db->execute('INSERT INTO live_fetches (cell, fetched_at) VALUES (?, ?)', [$key, $now->format('Y-m-d H:i:s')]);
    }

    private function now(): DateTimeImmutable
    {
        return $this->now !== null
            ? ($this->now)()
            : new DateTimeImmutable('now', new DateTimeZone('Europe/Rome'));
    }
}
