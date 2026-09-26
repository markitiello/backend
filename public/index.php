<?php

declare(strict_types=1);

use Benzina\App;
use Benzina\Config;
use Benzina\ErrorLog;

// Su IIS (Windows) tutto ciò che PHP scrive su stderr diventa un 500 vuoto:
// avvisi ed errori vanno invece in un file di log, se PHP non ne ha già uno.
if ((string) ini_get('error_log') === '') {
    @mkdir(__DIR__ . '/../var/log', 0775, true);
    ini_set('error_log', __DIR__ . '/../var/log/errori.log');
}
// BENZINA_DEBUG=true nel .env (solo per configurare il server): gli errori di
// PHP compaiono nella risposta invece che nel log.
if (preg_match('/^\s*BENZINA_DEBUG\s*=\s*["\']?(1|true|yes|on)\b/mi', (string) @file_get_contents(__DIR__ . '/../.env'))) {
    ini_set('display_errors', '1');
    ini_set('log_errors', '0');
    error_reporting(E_ALL);
}

require __DIR__ . '/../vendor/autoload.php';

$env = Config::loadEnv(__DIR__ . '/../.env');
try {
    $app = App::create(Config::fromEnv($env));
} catch (Throwable $e) {
    // Errore all'avvio (di solito database o .env): risposta JSON invece di
    // una pagina vuota. Il dettaglio va nel log (vedi ErrorLog) e, solo con
    // BENZINA_DEBUG=true nel .env, anche nella risposta.
    ErrorLog::write('benzina: avvio non riuscito: ' . $e);
    $debug = filter_var($env['BENZINA_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
    http_response_code(503);
    header('Content-Type: application/problem+json');
    echo json_encode([
        'type' => 'about:blank',
        'title' => 'Servizio non disponibile',
        'status' => 503,
        'detail' => $debug
            ? get_class($e) . ': ' . $e->getMessage()
            : 'Avvio non riuscito: controllare il database e il file .env (dettagli nel log degli errori).',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$app->run();
