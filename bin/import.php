<?php

declare(strict_types=1);

/**
 * Import giornaliero degli open data MIMIT. Da lanciare ogni mattina dopo le 8
 * (cron o .github/workflows/import.yml).
 *
 *   php bin/import.php
 *   php bin/import.php --stations-file=anagrafica.csv --prices-file=prezzi.csv
 */

use Benzina\Config;
use Benzina\Database;
use Benzina\Importer;
use Psr\Log\AbstractLogger;

require __DIR__ . '/../vendor/autoload.php';

$options = getopt('', ['stations-file:', 'prices-file:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Uso: php bin/import.php [--stations-file=FILE] [--prices-file=FILE]\n");
    exit(0);
}

$config = Config::fromEnv(Config::loadEnv(__DIR__ . '/../.env'));
$db = Database::connect($config);
$db->createSchema();

$read = static function (?string $file, string $url): array {
    if ($file === null) {
        return Importer::download($url);
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        fwrite(STDERR, "Impossibile leggere $file\n");
        exit(1);
    }
    return $lines;
};

$log = new class extends AbstractLogger {
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        fwrite(STDERR, strtoupper((string) $level) . " $message " . ($context ? json_encode($context) : '') . "\n");
    }
};

try {
    (new Importer($db, $config, $log))->run(
        $read($options['stations-file'] ?? null, $config->mimitStationsUrl),
        $read($options['prices-file'] ?? null, $config->mimitPricesUrl),
    );
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERRORE ' . $e->getMessage() . "\n");
    exit(1);
}
