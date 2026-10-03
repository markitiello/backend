<?php

declare(strict_types=1);

namespace Benzina;

use Benzina\Google\GoogleBudget;
use Benzina\Google\PlacesClient;
use Benzina\Http\ApiController;
use Benzina\Http\HttpProblem;
use Benzina\Http\Problem;
use Benzina\Live\LivePrices;
use Benzina\Live\OsservaprezziClient;
use Benzina\Security\Authenticator;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\App as SlimApp;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;

final class App
{
    public const SPEC_FILE = __DIR__ . '/../docs/openapi.yaml';

    /** @return SlimApp<null> */
    public static function create(
        Config $config,
        ?Database $db = null,
        ?Authenticator $authenticator = null,
        ?PlacesClient $google = null,
        ?LivePrices $live = null,
    ): SlimApp {
        $db ??= Database::connect($config);
        $db->createSchema();
        $authenticator ??= Authenticator::fromConfig($config);
        if ($google === null && $config->googlePlacesApiKey !== null) {
            $google = new PlacesClient(
                $config->googlePlacesApiKey,
                rematchDays: $config->googleRematchDays,
                budget: new GoogleBudget($db, $config->googleDailyLimit),
            );
        }
        if ($live === null && $config->livePrices) {
            $live = new LivePrices($db, new OsservaprezziClient(), $config->liveTtlMinutes);
        }
        $api = new ApiController($db, new PriceService($db), $google, $live);

        $app = AppFactory::create();
        $app->addRoutingMiddleware();

        $app->get('/health', [$api, 'health']);
        if ($config->docsEnabled) {
            $app->get('/openapi.yaml', static function (Request $request, Response $response): Response {
                $response->getBody()->write((string) file_get_contents(self::SPEC_FILE));
                return $response->withHeader('Content-Type', 'application/yaml; charset=utf-8');
            });
            $app->get('/docs', static function (Request $request, Response $response): Response {
                $response->getBody()->write(self::swaggerUi());
                return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
            });
        }

        $app->group('/v1', static function (RouteCollectorProxy $v1) use ($api): void {
            $v1->get('/stations/nearby', [$api, 'nearby']);
            $v1->get('/stations', [$api, 'stations']);
            $v1->get('/stations/{id:[0-9]+}', [$api, 'station']);
            $v1->get('/stations/{id:[0-9]+}/trend', [$api, 'stationTrend']);
            $v1->get('/stations/{id:[0-9]+}/google-rating', [$api, 'googleRating']);
            $v1->get('/trends/national', [$api, 'nationalTrend']);
            $v1->get('/trends/area', [$api, 'areaTrend']);
            $v1->get('/trends/alerts', [$api, 'trendAlerts']);
        })->add(static function (Request $request, Handler $handler) use ($authenticator): Response {
            $denial = $authenticator->denial(
                $request->getHeaderLine(Authenticator::APPCHECK_HEADER),
                $request->getHeaderLine(Authenticator::API_KEY_HEADER),
            );
            if ($denial !== null) {
                ErrorLog::write('benzina: 401 ' . $request->getUri()->getPath() . ': ' . $denial);
                throw new HttpProblem(401, "Credenziali mancanti o non valide ($denial).");
            }
            return $handler->handle($request);
        });

        $errors = $app->addErrorMiddleware(false, true, false);
        $errors->setDefaultErrorHandler(static function (Request $request, \Throwable $e) use ($app): Response {
            $response = $app->getResponseFactory()->createResponse();
            return match (true) {
                $e instanceof HttpProblem => Problem::write($response, $e->status, $e->getMessage()),
                $e instanceof HttpNotFoundException => Problem::write($response, 404, 'Risorsa inesistente.'),
                $e instanceof HttpMethodNotAllowedException => Problem::write($response, 405),
                default => (static function () use ($response, $e): Response {
                    ErrorLog::write('benzina: ' . $e);
                    return Problem::write($response, 500);
                })(),
            };
        });

        return $app;
    }

    private static function swaggerUi(): string
    {
        return <<<'HTML'
            <!doctype html>
            <html lang="it">
            <head>
              <meta charset="utf-8">
              <title>Benzina API</title>
              <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
            </head>
            <body>
              <div id="ui"></div>
              <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
              <script>SwaggerUIBundle({ url: 'openapi.yaml', dom_id: '#ui' });</script>
            </body>
            </html>
            HTML;
    }
}
