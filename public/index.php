<?php

declare(strict_types=1);

use Benzina\App;
use Benzina\Config;
use Benzina\ErrorLog;

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
