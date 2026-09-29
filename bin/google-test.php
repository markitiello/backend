<?php

declare(strict_types=1);

/**
 * Prova dell'integrazione con Google Places per un distributore: mostra la
 * ricerca, i luoghi trovati con la distanza e le recensioni. Non salva nulla.
 *
 *   php bin/google-test.php ID_DISTRIBUTORE
 */

use Benzina\Config;
use Benzina\Database;
use Benzina\Geo;
use Benzina\Google\PlacesClient;
use GuzzleHttp\Client;

require __DIR__ . '/../vendor/autoload.php';

$id = (int) ($argv[1] ?? 0);
$config = Config::fromEnv(Config::loadEnv(__DIR__ . '/../.env'));
if ($config->googlePlacesApiKey === null) {
    fwrite(STDOUT, "BENZINA_GOOGLE_PLACES_API_KEY non è impostata nel .env: le recensioni sono disattivate (503).\n");
    exit(1);
}
$db = Database::connect($config);
$station = $db->one('SELECT * FROM stations WHERE id = ?', [$id]);
if ($station === null) {
    fwrite(STDOUT, "Uso: php bin/google-test.php ID_DISTRIBUTORE (id MIMIT presente nel database)\n");
    exit(1);
}
$query = "{$station['brand']} {$station['address']} {$station['city']}";
fwrite(STDOUT, "Distributore $id: {$station['name']}, $query ({$station['lat']}, {$station['lng']})\n\n");

// Stessa ricerca di PlacesClient::findPlaceId, ma mostrando tutti i risultati.
$http = new Client(['timeout' => 15, 'http_errors' => false]);
$response = $http->post(PlacesClient::BASE_URL . '/places:searchText', [
    'headers' => [
        'X-Goog-Api-Key' => $config->googlePlacesApiKey,
        'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.location',
    ],
    'json' => [
        'textQuery' => $query,
        'includedType' => 'gas_station',
        'languageCode' => 'it',
        'regionCode' => 'IT',
        'maxResultCount' => 5,
        'locationBias' => ['circle' => [
            'center' => ['latitude' => (float) $station['lat'], 'longitude' => (float) $station['lng']],
            'radius' => (float) PlacesClient::MATCH_RADIUS_M,
        ]],
    ],
]);
$body = (string) $response->getBody();
fwrite(STDOUT, "Ricerca: HTTP {$response->getStatusCode()}\n");
$data = json_decode($body, true);
if ($response->getStatusCode() !== 200) {
    // Qui Google spiega il problema: chiave non valida, API non abilitata, fatturazione...
    fwrite(STDOUT, ($data['error']['message'] ?? $body) . "\n");
    exit(1);
}
foreach ($data['places'] ?? [] as $place) {
    $m = 1000 * Geo::distanceKm((float) $station['lat'], (float) $station['lng'], $place['location']['latitude'], $place['location']['longitude']);
    fwrite(STDOUT, sprintf(
        "  %s %s, %s: %.0f m%s\n",
        $m <= PlacesClient::MATCH_RADIUS_M ? '✓' : '·',
        $place['displayName']['text'] ?? '?',
        $place['formattedAddress'] ?? '',
        $m,
        $m <= PlacesClient::MATCH_RADIUS_M ? '' : ' (oltre ' . PlacesClient::MATCH_RADIUS_M . ' m, scartato)',
    ));
}
$match = (new PlacesClient($config->googlePlacesApiKey))->findPlaceId($station);
if ($match === null) {
    fwrite(STDOUT, "\nNessun luogo Google entro " . PlacesClient::MATCH_RADIUS_M . " m: per questo distributore niente recensioni.\n");
    exit(0);
}
$details = (new PlacesClient($config->googlePlacesApiKey))->details($match);
fwrite(STDOUT, sprintf(
    "\nAbbinato a %s: %s stelle, %d valutazioni, %d recensioni restituite.\n",
    $match,
    $details['rating'] ?? '—',
    $details['rating_count'],
    count($details['reviews']),
));
$saved = $db->one('SELECT place_id, checked_at FROM google_places WHERE station_id = ?', [$id]);
if ($saved !== null && $saved['place_id'] === null) {
    fwrite(STDOUT, "Attenzione: nel database questo distributore risulta \"non trovato\" dal {$saved['checked_at']}; "
        . "verrà ricercato dopo {$config->googleRematchDays} giorni (oppure cancellare la riga in google_places).\n");
}
