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
     * (più precisa della ricerca per zona), e i suoi dettagli: orari, servizi,
     * contatti.
     *
     * @return array{prices: list<PriceRow>, details: array<string, mixed>}
     * @throws OsservaprezziException
     */
    public function station(int $id): array
    {
        $json = $this->request('GET', self::STATION_URL . $id, "https://carburanti.mise.gov.it/ospzSearch/dettaglio/$id");
        if (($json['id'] ?? null) !== $id || !is_array($json['fuels'] ?? null)) {
            throw new OsservaprezziException('Osservaprezzi: risposta inattesa');
        }
        return ['prices' => self::parse([$json]), 'details' => self::details($json)];
    }

    /**
     * Più distributori in parallelo (per i primi risultati di una ricerca).
     * I distributori che non rispondono vengono saltati.
     *
     * @param list<int> $ids
     * @return array<int, array{prices: list<PriceRow>, details: array<string, mixed>}>
     */
    public function stations(array $ids): array
    {
        $promises = [];
        foreach ($ids as $id) {
            $promises[$id] = $this->http->requestAsync('GET', self::STATION_URL . $id, [
                'headers' => self::headers("https://carburanti.mise.gov.it/ospzSearch/dettaglio/$id"),
            ]);
        }
        $result = [];
        foreach (\GuzzleHttp\Promise\Utils::settle($promises)->wait() as $id => $outcome) {
            if ($outcome['state'] !== 'fulfilled') {
                continue;
            }
            $json = json_decode((string) $outcome['value']->getBody(), true);
            if (is_array($json) && ($json['id'] ?? null) === $id && is_array($json['fuels'] ?? null)) {
                $result[$id] = ['prices' => self::parse([$json]), 'details' => self::details($json)];
            }
        }
        return $result;
    }

    /** @return array<string, string> */
    private static function headers(string $referer): array
    {
        return [
            'Accept' => 'application/json',
            'Origin' => 'https://carburanti.mise.gov.it',
            'Referer' => $referer,
            'User-Agent' => 'Mozilla/5.0 (compatible; Benzina/1.0)',
        ];
    }

    /**
     * Dettagli nella forma dello schema StationDetails: telefono, email, sito,
     * servizi e orari. Negli orari giornoSettimanaId va da 1 (lunedì) a 7
     * (domenica); 8 sono i festivi.
     *
     * @param array<mixed> $json
     * @return array<string, mixed>
     */
    public static function details(array $json): array
    {
        $text = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $services = [];
        foreach (is_array($json['services'] ?? null) ? $json['services'] : [] as $service) {
            if (is_array($service) && ($name = $text($service['description'] ?? null)) !== null) {
                $services[] = $name;
            }
        }
        $hours = [];
        foreach (is_array($json['orariapertura'] ?? null) ? $json['orariapertura'] : [] as $day) {
            if (!is_array($day) || !is_int($day['giornoSettimanaId'] ?? null) || ($day['flagNonComunicato'] ?? false) === true) {
                continue;
            }
            $range = static fn (mixed $from, mixed $to): ?string =>
                $text($from) !== null && $text($to) !== null ? $text($from) . '–' . $text($to) : null;
            $value = match (true) {
                ($day['flagChiusura'] ?? false) === true => 'Chiuso',
                ($day['flagH24'] ?? false) === true => '24 ore',
                ($day['flagOrarioContinuato'] ?? false) === true
                    => $range($day['oraAperturaOrarioContinuato'] ?? null, $day['oraChiusuraOrarioContinuato'] ?? null),
                default => implode(', ', array_filter([
                    $range($day['oraAperturaMattina'] ?? null, $day['oraChiusuraMattina'] ?? null),
                    $range($day['oraAperturaPomeriggio'] ?? null, $day['oraChiusuraPomeriggio'] ?? null),
                ])) ?: null,
            };
            if ($value !== null) {
                $hours[] = ['day' => $day['giornoSettimanaId'], 'hours' => $value];
            }
        }
        usort($hours, static fn (array $a, array $b): int => $a['day'] <=> $b['day']);
        return [
            'phone' => $text($json['phoneNumber'] ?? null),
            'email' => $text($json['email'] ?? null),
            'website' => $text($json['website'] ?? null),
            'services' => $services,
            'opening_hours' => $hours,
        ];
    }

    /**
     * @param array<mixed>|null $body
     * @return array<mixed>
     */
    private function request(string $method, string $url, string $referer, ?array $body = null): array
    {
        try {
            $response = $this->http->request($method, $url, [
                'headers' => self::headers($referer),
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
