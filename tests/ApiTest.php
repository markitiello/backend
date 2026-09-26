<?php

declare(strict_types=1);

namespace Benzina\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

final class ApiTest extends TestCase
{
    public function testHealthNonRichiedeCredenziali(): void
    {
        [$status, $body] = self::get(self::app(), '/health', headers: []);
        self::assertSame(200, $status);
        self::assertSame(['status' => 'ok', 'data_date' => '2026-09-25'], $body);
    }

    public function testSenzaCredenziali401(): void
    {
        [$status, $body, $response] = self::get(self::app(), '/v1/stations/nearby', self::CENTER, headers: [], checkSpec: false);
        self::assertSame(401, $status);
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame(401, $body['status']);
    }

    public function testChiaveSbagliata401(): void
    {
        [$status] = self::get(self::app(), '/v1/stations/nearby', self::CENTER, ['X-API-Key' => 'sbagliata']);
        self::assertSame(401, $status);
    }

    public function testViciniDalPiuEconomico(): void
    {
        [$status, $body] = self::get(self::app(), '/v1/stations/nearby', self::CENTER + ['radius_km' => '5']);
        self::assertSame(200, $status);
        self::assertSame('2026-09-25', $body['data_date']);
        self::assertSame('self', $body['mode']);
        self::assertSame([1001, 1002, 1003, 1004], array_map(fn ($o) => $o['station']['id'], $body['offers']));
        self::assertSame([1.729, 1.759, 1.779, 1.788], array_map(fn ($o) => $o['price'], $body['offers']));
        foreach ($body['offers'] as $offer) {
            self::assertLessThanOrEqual(5, $offer['distance_km']);
        }
        self::assertEqualsWithDelta((1.729 + 1.759 + 1.779 + 1.701) / 4, $body['national_average'], 1e-4);
        self::assertSame('2026-09-24T19:00:00+02:00', $body['offers'][0]['reported_at']);
    }

    public function testViciniRaggioELimite(): void
    {
        [, $body] = self::get(self::app(), '/v1/stations/nearby', self::CENTER + ['radius_km' => '1', 'limit' => '1']);
        self::assertCount(1, $body['offers']);
        self::assertSame(1001, $body['offers'][0]['station']['id']);
    }

    public function testViciniGplIgnoraSelfServito(): void
    {
        [, $body] = self::get(self::app(), '/v1/stations/nearby', self::CENTER + ['fuel' => 'gpl', 'mode' => 'servito']);
        self::assertSame('any', $body['mode']);
        self::assertSame(
            [[1003, 'self'], [1001, 'servito']],
            array_map(fn ($o) => [$o['station']['id'], $o['mode']], $body['offers']),
        );
    }

    public function testDettaglioDistributore(): void
    {
        [$status, $body] = self::get(self::app(), '/v1/stations/1001');
        self::assertSame(200, $status);
        self::assertSame('Q8', $body['brand']);
        self::assertEqualsCanonicalizing(
            [['benzina', 'self'], ['benzina', 'servito'], ['diesel', 'self'], ['gpl', 'servito']],
            array_map(fn ($p) => [$p['fuel'], $p['mode']], $body['prices']),
        );
    }

    public function testDistributoreInesistente404(): void
    {
        [$status, $body] = self::get(self::app(), '/v1/stations/424242');
        self::assertSame(404, $status);
        self::assertSame('Non trovato', $body['title']);
    }

    public function testPiuDistributoriPerId(): void
    {
        [, $body] = self::get(self::app(), '/v1/stations', ['ids' => '1002,424242,1001,1002']);
        self::assertSame([1002, 1001], array_map(fn ($s) => $s['id'], $body['stations']));
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function parametriNonValidi(): iterable
    {
        yield 'raggio zero' => ['/v1/stations/nearby', self::CENTER + ['radius_km' => '0']];
        yield 'raggio troppo grande' => ['/v1/stations/nearby', self::CENTER + ['radius_km' => '100']];
        yield 'carburante sconosciuto' => ['/v1/stations/nearby', self::CENTER + ['fuel' => 'kerosene']];
        yield 'latitudine fuori scala' => ['/v1/stations/nearby', ['lat' => '200', 'lng' => '9']];
        yield 'posizione mancante' => ['/v1/stations/nearby', []];
        yield 'giorni fuori scala' => ['/v1/trends/national', ['days' => '1000']];
        yield 'id non numerici' => ['/v1/stations', ['ids' => '1,a']];
        yield 'troppi id' => ['/v1/stations', ['ids' => implode(',', range(1, 51))]];
    }

    /** @param array<string, string> $query */
    #[DataProvider('parametriNonValidi')]
    public function testParametriNonValidi422(string $path, array $query): void
    {
        // Richieste volutamente fuori specifica: si verifica solo la risposta.
        [$status, $body, $response] = self::get(self::app(), $path, $query, checkSpec: false);
        self::assertSame(422, $status);
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame('Parametri non validi', $body['title']);
    }

    public function testAndamentoNazionale(): void
    {
        [, $body] = self::get(self::app(), '/v1/trends/national', ['fuel' => 'benzina', 'days' => '30']);
        self::assertSame(['2026-09-24', '2026-09-25'], array_column($body['points'], 'day'));
    }

    public function testAndamentoDistributoreRicostruitoDalleVariazioni(): void
    {
        [, $body] = self::get(self::app(), '/v1/stations/1001/trend', ['days' => '3']);
        // Il 23 vale 1,739; il 24 sera il gestore comunica 1,729.
        self::assertSame(
            [['day' => '2026-09-23', 'price' => 1.739], ['day' => '2026-09-24', 'price' => 1.729], ['day' => '2026-09-25', 'price' => 1.729]],
            $body['points'],
        );
    }

    public function testAndamentoDistributoreInesistente404(): void
    {
        [$status] = self::get(self::app(), '/v1/stations/424242/trend');
        self::assertSame(404, $status);
    }

    public function testAndamentoZona(): void
    {
        [, $body] = self::get(self::app(), '/v1/trends/area', self::CENTER + ['radius_km' => '5', 'days' => '3']);
        $points = array_column($body['points'], 'price', 'day');
        self::assertEqualsWithDelta((1.739 + 1.759 + 1.772 + 1.788) / 4, $points['2026-09-23'], 1e-3);
        self::assertEqualsWithDelta((1.729 + 1.759 + 1.779 + 1.788) / 4, $points['2026-09-25'], 1e-3);
    }

    public function testRottaInesistente404(): void
    {
        [$status, , $response] = self::get(self::app(), '/v1/non-esiste', checkSpec: false);
        self::assertSame(404, $status);
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
    }

    public function testSpecificaEDocumentazione(): void
    {
        $app = self::app();
        [$status, , $response] = self::get($app, '/openapi.yaml', checkSpec: false);
        self::assertSame(200, $status);
        self::assertStringContainsString('openapi: 3.0.3', (string) $response->getBody());
        [$status] = self::get($app, '/docs', checkSpec: false);
        self::assertSame(200, $status);

        [$status] = self::get(self::app(config: self::config(docsEnabled: false)), '/docs', checkSpec: false);
        self::assertSame(404, $status);
    }
}
