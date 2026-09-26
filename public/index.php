<?php

declare(strict_types=1);

use Benzina\App;
use Benzina\Config;

require __DIR__ . '/../vendor/autoload.php';

try {
    $app = App::create(Config::fromEnv(Config::loadEnv(__DIR__ . '/../.env')));
} catch (Throwable $e) {
    // Errore all'avvio (di solito database o .env): risposta JSON invece di
    // una pagina vuota; i dettagli vanno nel log degli errori di PHP.
    error_log('Benzina: avvio non riuscito: ' . $e);
    http_response_code(503);
    header('Content-Type: application/problem+json');
    echo json_encode([
        'type' => 'about:blank',
        'title' => 'Servizio non disponibile',
        'status' => 503,
        'detail' => 'Avvio non riuscito: controllare il database e il file .env (dettagli nel log degli errori).',
    ]);
    exit;
}
$app->run();
