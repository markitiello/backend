<?php

declare(strict_types=1);

namespace Benzina\Google;

use Benzina\Database;
use Benzina\Geo;
use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Valutazioni e recensioni da Google Places API (New).
 *
 * - Il distributore MIMIT si abbina al luogo Google con una ricerca testuale
 *   vicino alle sue coordinate; si salva solo il place_id (l'unico dato che i
 *   termini di Google permettono di conservare).
 * - Valutazione e recensioni si chiedono a Google a ogni richiesta.
 * - La chiave Google resta sul server: l'app non la vede mai.
 */
final class PlacesClient
{
    public const BASE_URL = 'https://places.googleapis.com/v1';
    // Distanza massima tra il distributore MIMIT e il luogo Google.
    public const MATCH_RADIUS_M = 250;
    public const ATTRIBUTION = 'Valutazioni fornite da Google';

    public function __construct(
        private readonly string $apiKey,
        private readonly ClientInterface $http = new Client(['timeout' => 10]),
        private readonly int $rematchDays = 30,
        private readonly ?GoogleBudget $budget = null,
    ) {
    }

    /** @return array<string, string> */
    private function headers(string $fieldMask): array
    {
        return ['X-Goog-Api-Key' => $this->apiKey, 'X-Goog-FieldMask' => $fieldMask];
    }

    /**
     * @param array<string, mixed> $station riga della tabella stations
     * @throws GooglePlacesException
     * @throws GoogleQuotaException
     */
    public function findPlaceId(array $station): ?string
    {
        $lat = (float) $station['lat'];
        $lng = (float) $station['lng'];
        $this->budget?->spend();
        try {
            $response = $this->http->request('POST', self::BASE_URL . '/places:searchText', [
                'headers' => $this->headers('places.id,places.location'),
                'json' => [
                    'textQuery' => "{$station['brand']} {$station['address']} {$station['city']}",
                    'includedType' => 'gas_station',
                    'languageCode' => 'it',
                    'regionCode' => 'IT',
                    'maxResultCount' => 5,
                    'locationBias' => ['circle' => [
                        'center' => ['latitude' => $lat, 'longitude' => $lng],
                        'radius' => (float) self::MATCH_RADIUS_M,
                    ]],
                ],
            ]);
            $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (GuzzleException | \JsonException $e) {
            throw new GooglePlacesException('searchText: ' . $e->getMessage(), 0, $e);
        }

        $best = null;
        foreach ($data['places'] ?? [] as $place) {
            if (!isset($place['id'], $place['location']['latitude'], $place['location']['longitude'])) {
                continue;
            }
            $meters = 1000 * Geo::distanceKm($lat, $lng, $place['location']['latitude'], $place['location']['longitude']);
            if ($meters <= self::MATCH_RADIUS_M && ($best === null || $meters < $best[0])) {
                $best = [$meters, $place['id']];
            }
        }
        return $best[1] ?? null;
    }

    /**
     * place_id salvato, oppure cercato e salvato ora. Un distributore non
     * trovato si ricerca solo dopo $rematchDays giorni.
     *
     * @param array<string, mixed> $station
     */
    public function placeIdFor(Database $db, array $station): ?string
    {
        $match = $db->one('SELECT place_id, checked_at FROM google_places WHERE station_id = ?', [$station['id']]);
        $now = new DateTimeImmutable();
        if ($match !== null) {
            $recent = new DateTimeImmutable((string) $match['checked_at']) > $now->modify("-{$this->rematchDays} days");
            if ($match['place_id'] !== null || $recent) {
                return $match['place_id'];
            }
        }
        $placeId = $this->findPlaceId($station);
        $db->transaction(function () use ($db, $station, $placeId, $now): void {
            $db->execute('DELETE FROM google_places WHERE station_id = ?', [$station['id']]);
            $db->insertMany('google_places', ['station_id', 'place_id', 'checked_at'], [
                [(int) $station['id'], $placeId, $now->format('Y-m-d H:i:s')],
            ]);
        });
        return $placeId;
    }

    /**
     * Valutazione media, numero di voti e link a Google Maps. Le recensioni
     * non si chiedono: costano di più (tariffa "Atmosphere") e l'app mostra
     * solo le stelle.
     *
     * @return array<string, mixed> nella forma dello schema GoogleRating
     * @throws GooglePlacesException
     * @throws GoogleQuotaException
     */
    public function details(string $placeId): array
    {
        $this->budget?->spend();
        try {
            $response = $this->http->request('GET', self::BASE_URL . '/places/' . rawurlencode($placeId), [
                'headers' => $this->headers('id,rating,userRatingCount,googleMapsUri'),
                'query' => ['languageCode' => 'it', 'regionCode' => 'IT'],
            ]);
            $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (GuzzleException | \JsonException $e) {
            throw new GooglePlacesException('details: ' . $e->getMessage(), 0, $e);
        }

        return [
            'place_id' => $placeId,
            'rating' => isset($data['rating']) ? (float) $data['rating'] : null,
            'rating_count' => (int) ($data['userRatingCount'] ?? 0),
            'maps_url' => $data['googleMapsUri'] ?? null,
            'reviews' => [],
            'attribution' => self::ATTRIBUTION,
        ];
    }
}
