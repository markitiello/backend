<?php

declare(strict_types=1);

namespace Benzina;

use PDO;

/**
 * Accesso al database con PDO: SQLite (sviluppo e test), PostgreSQL o MySQL/MariaDB.
 *
 * Date e orari sono salvati come testo ISO ('2026-09-25', '2026-09-24 19:00:00')
 * e i booleani come 0/1, così le query sono identiche sui tre database.
 */
final class Database
{
    private const BATCH = 500;

    public function __construct(public readonly PDO $pdo)
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
    }

    public static function connect(Config $config): self
    {
        if (str_starts_with($config->dbDsn, 'sqlite:') && $config->dbDsn !== 'sqlite::memory:') {
            $dir = dirname(substr($config->dbDsn, strlen('sqlite:')));
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Impossibile creare la cartella del database: $dir");
            }
            if (!is_writable($dir)) {
                throw new \RuntimeException(
                    "La cartella del database non è scrivibile dal web server: $dir"
                );
            }
        }
        // Timeout di connessione: un database irraggiungibile deve dare un errore
        // chiaro, non tenere la richiesta appesa fino al timeout del web server.
        $options = [PDO::ATTR_TIMEOUT => 5];
        if (str_starts_with($config->dbDsn, 'mysql:')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES utf8mb4';
        }
        return new self(new PDO($config->dbDsn, $config->dbUser, $config->dbPassword, $options));
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function createSchema(): void
    {
        $timestamp = $this->driver() === 'mysql' ? 'DATETIME' : 'TIMESTAMP';
        $text = static fn (int $n): string => "VARCHAR($n)";
        $engine = $this->driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $statements = [
            // Anagrafica MIMIT (id = idImpianto). last_seen: ultimo import in cui compariva.
            "CREATE TABLE IF NOT EXISTS stations (
                id INTEGER PRIMARY KEY, operator {$text(200)} NOT NULL, brand {$text(100)} NOT NULL,
                kind {$text(30)} NOT NULL, name {$text(200)} NOT NULL, address {$text(300)} NOT NULL,
                city {$text(100)} NOT NULL, province {$text(4)} NOT NULL,
                lat DOUBLE PRECISION NOT NULL, lng DOUBLE PRECISION NOT NULL, last_seen DATE NOT NULL
            )$engine",
            'CREATE INDEX ix_stations_lat_lng ON stations (lat, lng)',
            // Prezzi dell'ultimo import, sostituiti per intero a ogni import.
            "CREATE TABLE IF NOT EXISTS current_prices (
                station_id INTEGER NOT NULL, fuel {$text(10)} NOT NULL, is_self SMALLINT NOT NULL,
                price DOUBLE PRECISION NOT NULL, reported_at $timestamp NOT NULL,
                PRIMARY KEY (station_id, fuel, is_self)
            )$engine",
            'CREATE INDEX ix_current_prices_fuel ON current_prices (fuel, is_self)',
            // Storico: una riga per ogni prezzo comunicato (non una per giorno).
            "CREATE TABLE IF NOT EXISTS price_changes (
                station_id INTEGER NOT NULL, fuel {$text(10)} NOT NULL, is_self SMALLINT NOT NULL,
                reported_at $timestamp NOT NULL, price DOUBLE PRECISION NOT NULL,
                PRIMARY KEY (station_id, fuel, is_self, reported_at)
            )$engine",
            // Media nazionale giornaliera. mode: self, servito oppure any (GPL e metano).
            "CREATE TABLE IF NOT EXISTS national_averages (
                day DATE NOT NULL, fuel {$text(10)} NOT NULL, mode {$text(10)} NOT NULL,
                price DOUBLE PRECISION NOT NULL, stations INTEGER NOT NULL,
                PRIMARY KEY (day, fuel, mode)
            )$engine",
            "CREATE TABLE IF NOT EXISTS imports (
                day DATE PRIMARY KEY, stations INTEGER NOT NULL, prices INTEGER NOT NULL,
                finished_at $timestamp NOT NULL
            )$engine",
            // Tendenze della media nazionale rilevate dopo gli import. sent_at: quando
            // la notifica push è stata inviata (NULL = non ancora / FCM non configurato).
            "CREATE TABLE IF NOT EXISTS trend_alerts (
                fuel {$text(10)} NOT NULL, mode {$text(10)} NOT NULL, day DATE NOT NULL,
                direction {$text(4)} NOT NULL, days INTEGER NOT NULL,
                change_ratio DOUBLE PRECISION NOT NULL, price DOUBLE PRECISION NOT NULL,
                sent_at $timestamp NULL,
                PRIMARY KEY (fuel, mode, day)
            )$engine",
            // Ultima richiesta a Osservaprezzi per zona (vedi Live\LivePrices).
            "CREATE TABLE IF NOT EXISTS live_fetches (
                cell {$text(40)} PRIMARY KEY, fetched_at $timestamp NOT NULL
            )$engine",
            // Abbinamento con Google: si salva solo il place_id (NULL = cercato, non trovato).
            "CREATE TABLE IF NOT EXISTS google_places (
                station_id INTEGER PRIMARY KEY, place_id {$text(300)} NULL, checked_at $timestamp NOT NULL
            )$engine",
        ];
        foreach ($statements as $sql) {
            if (str_starts_with($sql, 'CREATE INDEX')) {
                $this->createIndex($sql);
            } else {
                $this->pdo->exec($sql);
            }
        }
    }

    /** CREATE INDEX IF NOT EXISTS non esiste su MySQL: si ignora l'errore "esiste già". */
    private function createIndex(string $sql): void
    {
        if ($this->driver() !== 'mysql') {
            $this->pdo->exec(str_replace('CREATE INDEX', 'CREATE INDEX IF NOT EXISTS', $sql));
            return;
        }
        try {
            $this->pdo->exec($sql);
        } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), 'Duplicate key name')) {
                throw $e;
            }
        }
    }

    public function dropSchema(): void
    {
        foreach (['stations', 'current_prices', 'price_changes', 'national_averages', 'imports', 'google_places', 'trend_alerts', 'live_fetches'] as $t) {
            $this->pdo->exec("DROP TABLE IF EXISTS $t");
        }
    }

    /**
     * INSERT a blocchi. Con $ignoreDuplicates le righe già presenti (stessa chiave
     * primaria) vengono saltate. Restituisce il numero di righe inserite.
     *
     * @param list<string> $columns
     * @param list<list<mixed>> $rows
     */
    public function insertMany(string $table, array $columns, array $rows, bool $ignoreDuplicates = false): int
    {
        $inserted = 0;
        $cols = implode(', ', $columns);
        $tuple = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $values = implode(', ', array_fill(0, count($chunk), $tuple));
            $sql = match (true) {
                !$ignoreDuplicates => "INSERT INTO $table ($cols) VALUES $values",
                $this->driver() === 'mysql' => "INSERT IGNORE INTO $table ($cols) VALUES $values",
                default => "INSERT INTO $table ($cols) VALUES $values ON CONFLICT DO NOTHING",
            };
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(array_merge(...$chunk));
            $inserted += $stmt->rowCount();
        }
        return $inserted;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    public function one(string $sql, array $params = []): ?array
    {
        $rows = $this->all($sql, $params);
        return $rows[0] ?? null;
    }

    /** @param list<mixed> $params */
    public function execute(string $sql, array $params = []): void
    {
        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** "?, ?, ?" per una clausola IN. */
    public static function placeholders(int $count): string
    {
        return implode(', ', array_fill(0, max($count, 1), '?'));
    }
}
