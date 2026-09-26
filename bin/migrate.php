<?php

declare(strict_types=1);

/**
 * Crea o aggiorna le tabelle del database. Lo lancia il deploy prima di
 * mettere online una nuova versione (le tabelle mancanti vengono create anche
 * alla prima richiesta, ma così un errore di database blocca il deploy).
 *
 *   php bin/migrate.php
 */

use Benzina\Config;
use Benzina\Database;

require __DIR__ . '/../vendor/autoload.php';

try {
    $db = Database::connect(Config::fromEnv(Config::loadEnv(__DIR__ . '/../.env')));
    $db->createSchema();
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERRORE database: ' . $e->getMessage() . "\n");
    exit(1);
}
fwrite(STDOUT, 'Schema del database aggiornato (' . $db->driver() . ").\n");
