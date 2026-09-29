<?php

declare(strict_types=1);

namespace Benzina\Live;

use Benzina\Fuel;
use Benzina\Mimit\PriceRow;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * API della ricerca per zona del sito Osservaprezzi Carburanti
 * (carburanti.mise.gov.it): prezzi aggiornati a pochi minuti dalla
 * comunicazione del gestore, mentre il file open data è quello delle 8.
 *
 * Non è un'API documentata: può cambiare senza preavviso. Per questo ogni
 * errore diventa una OsservaprezziException e il chiamante resta sui dati
 * del file giornaliero.
 */
final class OsservaprezziClient
{
    public const URL = 'https://carburanti.mise.gov.it/ospzApi/search/zone';
    public const STATION_URL = 'https://carburanti.mise.gov.it/ospzApi/registry/servicearea/';

    /** Il sito cerca al massimo in questo raggio. */
    public const MAX_RADIUS_KM = 10.0;

    public function __construct(
        private readonly Client $http = new Client(['timeout' => 5, 'connect_timeout' => 3]),
    ) {
    }

    /**
     * Prezzi dei distributori entro $radiusKm, uno per carburante e modalità.
     * `insertDate` è l'ultima comunicazione del distributore: vale per tutti i
     * suoi prezzi.
     *
     * @return list<PriceRow>
     * @throws OsservaprezziException
     */
    public function nearby(float $lat, float $lng, float $radiusKm): array
    {
        $json = $this->request('POST', self::URL, 'https://carburanti.mise.gov.it/ospzSearch/zona', [
            'points' => [['lat' => $lat, 'lng' => $lng]],
            'radius' => min($radiusKm, self::MAX_RADIUS_KM),
        ]);
        if (($json['success'] ?? false) !== true || !is_array($json['results'] ?? null)) {
            throw new OsservaprezziException('Osservaprezzi: risposta inattesa');
        }
        return self::parse($json['results']);
    }

    /**
     * Prezzi di un distributore, ognuno con la propria ora di comunicazione
     * (più precisa della ricerca per zona).
     *
     * @return list<PriceRow>
     * @throws OsservaprezziException
     */
    public function station(int $id): array
    {
        $json = $this->request('GET', self::STATION_URL . $id, "https://carburanti.mise.gov.it/ospzSearch/dettaglio/$id");
        if (($json['id'] ?? null) !== $id || !is_array($json['fuels'] ?? null)) {
            throw new OsservaprezziException('Osservaprezzi: risposta inattesa');
        }
        return self::parse([$json]);
    }

    /**
     * @param array<mixed>|null $body
     * @return array<mixed>
     */
    private function request(string $method, string $url, string $referer, ?array $body = null): array
    {
        try {
            $response = $this->http->request($method, $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Origin' => 'https://carburanti.mise.gov.it',
                    'Referer' => $referer,
                    'User-Agent' => 'Mozilla/5.0 (compatible; Benzina/1.0)',
                ],
                ...($body === null ? [] : ['json' => $body]),
            ]);
            $json = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (GuzzleException | \JsonException $e) {
            throw new OsservaprezziException('Osservaprezzi non disponibile: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($json)) {
            throw new OsservaprezziException('Osservaprezzi: risposta inattesa');
        }
        return $json;
    }

    /**
     * Distributori nel formato di Osservaprezzi. L'ora di comunicazione è
     * quella del singolo prezzo se c'è (dettaglio), altrimenti quella del
     * distributore (ricerca per zona).
     *
     * @param array<mixed> $results
     * @return list<PriceRow>
     */
    public static function parse(array $results): array
    {
        $rows = [];
        foreach ($results as $station) {
            if (!is_array($station) || !is_int($station['id'] ?? null) || !is_array($station['fuels'] ?? null)) {
                continue;
            }
            $stationTime = self::romeTime($station['insertDate'] ?? null);
            foreach ($station['fuels'] as $f) {
                $fuel = is_array($f) ? Fuel::fromMimit((string) ($f['name'] ?? '')) : null;
                $price = is_array($f) ? ($f['price'] ?? null) : null;
                $reportedAt = is_array($f) ? (self::romeTime($f['insertDate'] ?? null) ?? $stationTime) : null;
                if ($fuel === null || $reportedAt === null || !is_numeric($price) || $price < 0.3 || $price > 5.0) {
                    continue;
                }
                $rows[] = new PriceRow(
                    stationId: $station['id'],
                    fuel: $fuel,
                    isSelf: ($f['isSelf'] ?? false) === true,
                    price: (float) $price,
                    reportedAt: $reportedAt,
                );
            }
        }
        return $rows;
    }

    /** "2026-09-28T08:36:21Z" → "2026-09-28 10:36:21" (ora italiana, come nel database). */
    private static function romeTime(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Europe/Rome'))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }
}
