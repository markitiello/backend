<?php

declare(strict_types=1);

/**
 * Import giornaliero degli open data MIMIT. Da lanciare ogni mattina dopo le 8
 * (cron o .github/workflows/import.yml).
 *
 *   php bin/import.php
 *   php bin/import.php --stations-file=anagrafica.csv --prices-file=prezzi.csv
 *
 * Dopo l'import rileva le tendenze dei prezzi e, se BENZINA_FCM_CREDENTIALS è
 * configurato, invia le notifiche push (--no-alerts per saltare).
 */

use Benzina\Config;
use Benzina\Database;
use Benzina\Importer;
use Benzina\Trend\FcmClient;
use Benzina\Trend\TrendAlerts;
use Psr\Log\AbstractLogger;

require __DIR__ . '/../vendor/autoload.php';

// Traccia delle esecuzioni (anche da cron): una riga per avvio e una per esito.
$trace = static function (string $message): void {
    @mkdir(__DIR__ . '/../var', 0775, true);
    @file_put_contents(__DIR__ . '/../var/import-runs.log', date('Y-m-d H:i:s') . " $message\n", FILE_APPEND | LOCK_EX);
};
$trace('avvio (pid ' . getmypid() . ')');

$options = getopt('', ['stations-file:', 'prices-file:', 'no-alerts', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Uso: php bin/import.php [--stations-file=FILE] [--prices-file=FILE] [--no-alerts]\n");
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
    $result = (new Importer($db, $config, $log))->run(
        $read($options['stations-file'] ?? null, $config->mimitStationsUrl),
        $read($options['prices-file'] ?? null, $config->mimitPricesUrl),
    );
    if (!isset($options['no-alerts'])) {
        $push = $config->fcmCredentials === null ? null : FcmClient::fromCredentials($config->fcmCredentials);
        $alerts = (new TrendAlerts($db, $config, $push, $log))->run($result['day']);
        $log->info($push === null ? 'tendenze (notifiche push non configurate)' : 'tendenze', $alerts);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERRORE ' . $e->getMessage() . "\n");
    $trace('ERRORE ' . $e->getMessage());
    exit(1);
}
$trace("fine: giorno {$result['day']}, {$result['stations']} distributori, {$result['prices']} prezzi");
