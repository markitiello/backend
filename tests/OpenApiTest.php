<?php

declare(strict_types=1);

namespace Benzina\Tests;

use Benzina\App;
use cebe\openapi\Reader;
use Slim\Routing\Route;

/** La specifica è valida e coincide con le rotte realmente esposte. */
final class OpenApiTest extends TestCase
{
    public function testSpecificaValida(): void
    {
        $spec = Reader::readFromYamlFile(App::SPEC_FILE);
        self::assertTrue($spec->validate(), implode("\n", $spec->getErrors()));
        self::assertSame(['AppCheck', 'ApiKey'], array_keys($spec->components->securitySchemes));
    }

    public function testOgniRottaEDocumentataEViceversa(): void
    {
        $routes = array_map(
            // "/v1/stations/{id:[0-9]+}" -> "/v1/stations/{station_id}"
            static fn (Route $r): string => (string) preg_replace('/\{id:[^}]+\}/', '{station_id}', $r->getPattern()),
            self::app()->getRouteCollector()->getRoutes(),
        );
        $routes = array_values(array_diff($routes, ['/openapi.yaml', '/docs']));
        $documented = array_keys(Reader::readFromYamlFile(App::SPEC_FILE)->paths->getPaths());
        self::assertEqualsCanonicalizing($documented, $routes);
    }

    public function testRotteV1ProtetteEHealthPubblica(): void
    {
        $spec = Reader::readFromYamlFile(App::SPEC_FILE);
        $global = array_map(static fn ($r) => array_keys((array) $r->getSerializableData()), $spec->security);
        self::assertSame([['AppCheck'], ['ApiKey']], $global);
        foreach ($spec->paths as $path => $item) {
            $operation = $item->get;
            if (str_starts_with($path, '/v1/')) {
                self::assertNull($operation->security, "$path deve usare la sicurezza globale");
                self::assertArrayHasKey('401', $operation->responses->getResponses(), $path);
            } else {
                self::assertSame([], $operation->security, $path);
            }
        }
    }
}
