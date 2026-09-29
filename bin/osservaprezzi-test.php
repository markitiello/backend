<?php

declare(strict_types=1);

/**
 * Prova dell'API di Osservaprezzi (carburanti.mise.gov.it), usata dal sito
 * ufficiale per la ricerca per zona: verifica che il server riesca a
 * raggiungerla e mostra com'è fatta la risposta.
 *
 *   php bin/osservaprezzi-test.php [LAT] [LNG] [RAGGIO_KM]
 *
 * Non scrive nulla nel database.
 */

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

require __DIR__ . '/../vendor/autoload.php';

$lat = (float) ($argv[1] ?? 45.4642);
$lng = (float) ($argv[2] ?? 9.1900);
$radius = (float) ($argv[3] ?? 2);

$http = new Client(['timeout' => 20, 'http_errors' => false]);
$start = microtime(true);
try {
    $response = $http->post('https://carburanti.mise.gov.it/ospzApi/search/zone', [
        'headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Origin' => 'https://carburanti.mise.gov.it',
            'Referer' => 'https://carburanti.mise.gov.it/ospzSearch/zona',
            'User-Agent' => 'Mozilla/5.0 (compatible; Benzina/1.0)',
        ],
        'json' => ['points' => [['lat' => $lat, 'lng' => $lng]], 'radius' => $radius],
    ]);
} catch (RequestException $e) {
    fwrite(STDOUT, 'ERRORE di connessione: ' . $e->getMessage() . "\n");
    exit(1);
}

$body = (string) $response->getBody();
fwrite(STDOUT, sprintf(
    "Stato HTTP %d in %.1f s, %s, %d byte\n\n",
    $response->getStatusCode(),
    microtime(true) - $start,
    $response->getHeaderLine('Content-Type'),
    strlen($body),
));

$json = json_decode($body, true);
if (!is_array($json)) {
    fwrite(STDOUT, "La risposta non è JSON (probabile blocco anti-bot). Inizio:\n" . substr($body, 0, 1500) . "\n");
    exit(1);
}

// Struttura: chiavi di primo livello e primo elemento di ogni lista.
$preview = [];
foreach ($json as $key => $value) {
    $preview[$key] = is_array($value) && array_is_list($value)
        ? ['elementi' => count($value), 'primo' => $value[0] ?? null]
        : $value;
}
fwrite(STDOUT, json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
