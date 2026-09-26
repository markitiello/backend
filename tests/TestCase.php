<?php

declare(strict_types=1);

namespace Benzina\Tests;

use Benzina\App;
use Benzina\Config;
use Benzina\Database;
use Benzina\Google\PlacesClient;
use Benzina\Importer;
use Benzina\Security\Authenticator;
use League\OpenAPIValidation\PSR7\ResponseValidator;
use League\OpenAPIValidation\PSR7\ServerRequestValidator;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Slim\App as SlimApp;
use Slim\Psr7\Factory\ServerRequestFactory;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    public const API_KEY = 'chiave-di-test';
    // Milano, Città Studi: i distributori 1001-1004 delle fixture sono entro 2 km.
    public const CENTER = ['lat' => '45.4781', 'lng' => '9.2270'];

    private static ?ServerRequestValidator $requestValidator = null;
    private static ?ResponseValidator $responseValidator = null;

    /** @return list<string> */
    protected static function lines(string $fixture): array
    {
        return file(__DIR__ . '/fixtures/' . $fixture, FILE_IGNORE_NEW_LINES) ?: [];
    }

    protected static function config(mixed ...$overrides): Config
    {
        return new Config(...['apiKeys' => [self::API_KEY], ...$overrides]);
    }

    /**
     * Con BENZINA_TEST_DB_DSN (es. pgsql:host=...;dbname=benzina_test) i test girano
     * su quel database, svuotato a ogni test. Altrimenti SQLite in memoria.
     */
    protected static function database(): Database
    {
        $dsn = getenv('BENZINA_TEST_DB_DSN');
        if ($dsn === false || $dsn === '') {
            $db = new Database(new PDO('sqlite::memory:'));
        } else {
            $db = new Database(new PDO(
                $dsn,
                getenv('BENZINA_TEST_DB_USER') ?: null,
                getenv('BENZINA_TEST_DB_PASSWORD') ?: null,
            ));
            $db->dropSchema();
        }
        $db->createSchema();
        return $db;
    }

    /** Database con due giorni di import (24 e 25 settembre 2026). */
    protected static function importedDatabase(): Database
    {
        $db = self::database();
        $importer = new Importer($db, self::config());
        $importer->run(self::lines('stations_day1.csv'), self::lines('prices_day1.csv'));
        $importer->run(self::lines('stations_day2.csv'), self::lines('prices_day2.csv'));
        return $db;
    }

    /** @return SlimApp<null> */
    protected static function app(
        ?Database $db = null,
        ?Config $config = null,
        ?Authenticator $auth = null,
        ?PlacesClient $google = null,
    ): SlimApp {
        $config ??= self::config();
        return App::create($config, $db ?? self::importedDatabase(), $auth, $google);
    }

    /**
     * Esegue una GET sull'app. Se $checkSpec è vero, richiesta e risposta
     * devono rispettare docs/openapi.yaml.
     *
     * @param SlimApp<null> $app
     * @param array<string, string> $query
     * @param array<string, string>|null $headers null = chiave API di test
     * @return array{int, mixed, ResponseInterface}
     */
    protected static function get(
        SlimApp $app,
        string $path,
        array $query = [],
        ?array $headers = null,
        bool $checkSpec = true,
    ): array {
        $uri = $path . ($query === [] ? '' : '?' . http_build_query($query));
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost' . $uri)
            ->withQueryParams($query);
        foreach ($headers ?? ['X-API-Key' => self::API_KEY] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $response = $app->handle($request);

        if ($checkSpec) {
            self::$requestValidator ??= (new ValidatorBuilder())->fromYamlFile(App::SPEC_FILE)->getServerRequestValidator();
            self::$responseValidator ??= (new ValidatorBuilder())->fromYamlFile(App::SPEC_FILE)->getResponseValidator();
            $operation = self::$requestValidator->validate($request);
            self::$responseValidator->validate($operation, $response);
        }

        $body = (string) $response->getBody();
        return [$response->getStatusCode(), json_decode($body, true), $response];
    }
}
