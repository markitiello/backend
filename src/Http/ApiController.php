<?php

declare(strict_types=1);

namespace Benzina\Http;

use Benzina\Database;
use Benzina\ErrorLog;
use Benzina\Fuel;
use Benzina\Google\GooglePlacesException;
use Benzina\Google\GoogleQuotaException;
use Benzina\Google\PlacesClient;
use Benzina\Live\LivePrices;
use Benzina\Mode;
use Benzina\PriceService;
use Benzina\Trend\TrendAlerts;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Le rotte descritte in docs/openapi.yaml. */
final class ApiController
{
    public function __construct(
        private readonly Database $db,
        private readonly PriceService $prices,
        private readonly ?PlacesClient $google,
        private readonly ?LivePrices $live = null,
    ) {
    }

    /** @param array<mixed>|null $data */
    public static function json(Response $response, ?array $data): Response
    {
        $response->getBody()->write(json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function health(Request $request, Response $response): Response
    {
        return self::json($response, ['status' => 'ok', 'data_date' => $this->prices->latestImportDay()]);
    }

    /** @return array{Fuel, Mode} */
    private static function fuelAndMode(QueryParams $q): array
    {
        $fuel = $q->enum('fuel', Fuel::class, Fuel::Benzina);
        $mode = $q->enum('mode', Mode::class, Mode::Self);
        return [$fuel, Mode::effective($fuel, $mode)];
    }

    /** @return array{float, float, float} */
    private static function position(QueryParams $q): array
    {
        return [
            $q->float('lat', -90, 90),
            $q->float('lng', -180, 180),
            $q->float('radius_km', 0, 50, 5.0, exclusiveMin: true),
        ];
    }

    private static function days(QueryParams $q): int
    {
        return $q->int('days', 2, 366, 30);
    }

    public function nearby(Request $request, Response $response): Response
    {
        $q = new QueryParams($request->getQueryParams());
        [$lat, $lng, $radius] = self::position($q);
        [$fuel, $mode] = self::fuelAndMode($q);
        $limit = $q->int('limit', 1, 200, 50);
        $q->check();
        $this->live?->refresh($lat, $lng, $radius);
        $result = $this->prices->nearby($lat, $lng, $radius, $fuel, $mode, $limit);
        // Servizi (bar, bancomat...) dei primi risultati che non li hanno ancora.
        $top = array_map(static fn (array $o): int => $o['station']['id'], array_slice($result['offers'], 0, 5));
        if ($this->live?->prefetchDetails($top) > 0) {
            $result = $this->prices->nearby($lat, $lng, $radius, $fuel, $mode, $limit);
        }
        return self::json($response, $result);
    }

    public function stations(Request $request, Response $response): Response
    {
        $q = new QueryParams($request->getQueryParams());
        $ids = $q->idList('ids', 50);
        $q->check();
        return self::json($response, ['stations' => $this->prices->stationsById($ids)]);
    }

    private function stationId(array $args): int
    {
        $id = (int) $args['id'];
        if (!$this->prices->stationExists($id)) {
            throw new HttpProblem(404, 'Distributore non trovato.');
        }
        return $id;
    }

    /** @param array<string, string> $args */
    public function station(Request $request, Response $response, array $args): Response
    {
        $id = $this->stationId($args);
        $this->live?->refreshStation($id);
        return self::json($response, $this->prices->stationsById([$id])[0]);
    }

    /** @param array<string, string> $args */
    public function stationTrend(Request $request, Response $response, array $args): Response
    {
        $q = new QueryParams($request->getQueryParams());
        [$fuel, $mode] = self::fuelAndMode($q);
        $days = self::days($q);
        $q->check();
        return self::json($response, $this->prices->stationTrend($this->stationId($args), $fuel, $mode, $days));
    }

    /** @param array<string, string> $args */
    public function googleRating(Request $request, Response $response, array $args): Response
    {
        if ($this->google === null) {
            throw new HttpProblem(503, 'Recensioni Google non attive.');
        }
        $station = $this->db->one('SELECT * FROM stations WHERE id = ?', [(int) $args['id']]);
        if ($station === null) {
            throw new HttpProblem(404, 'Distributore non trovato.');
        }
        try {
            $placeId = $this->google->placeIdFor($this->db, $station);
            if ($placeId === null) {
                throw new HttpProblem(404, 'Nessun luogo Google abbinato.');
            }
            return self::json($response, $this->google->details($placeId));
        } catch (GoogleQuotaException) {
            throw new HttpProblem(503, 'Limite giornaliero di richieste a Google raggiunto.');
        } catch (GooglePlacesException $e) {
            // Il motivo (chiave non valida, API non abilitata, fatturazione...) va
            // nel log; per capirlo: php bin/google-test.php ID_DISTRIBUTORE.
            ErrorLog::write('benzina: Google Places: ' . $e->getMessage());
            throw new HttpProblem(502, 'Google Places non disponibile.');
        }
    }

    public function nationalTrend(Request $request, Response $response): Response
    {
        $q = new QueryParams($request->getQueryParams());
        [$fuel, $mode] = self::fuelAndMode($q);
        $days = self::days($q);
        $q->check();
        return self::json($response, $this->prices->nationalTrend($fuel, $mode, $days));
    }

    public function trendAlerts(Request $request, Response $response): Response
    {
        $q = new QueryParams($request->getQueryParams());
        $fuel = $q->has('fuel') ? $q->enum('fuel', Fuel::class, Fuel::Benzina) : null;
        $days = $q->int('days', 1, 366, 30);
        $q->check();
        $until = $this->prices->latestImportDay() ?? date('Y-m-d');
        return self::json($response, ['alerts' => TrendAlerts::recent($this->db, $until, $days, $fuel)]);
    }

    public function areaTrend(Request $request, Response $response): Response
    {
        $q = new QueryParams($request->getQueryParams());
        [$lat, $lng, $radius] = self::position($q);
        [$fuel, $mode] = self::fuelAndMode($q);
        $days = self::days($q);
        $q->check();
        return self::json($response, $this->prices->areaTrend($lat, $lng, $radius, $fuel, $mode, $days));
    }
}
