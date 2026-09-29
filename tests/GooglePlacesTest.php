<?php

declare(strict_types=1);

namespace Benzina\Tests;

use Benzina\Database;
use Benzina\Google\GoogleBudget;
use Benzina\Google\PlacesClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

final class GooglePlacesTest extends TestCase
{
    // Distributore 1001 delle fixture: 45.4841, 9.2310
    private const NEAR = ['latitude' => 45.4842, 'longitude' => 9.2311]; // ~15 m
    private const FAR = ['latitude' => 45.4900, 'longitude' => 9.2400];  // ~1 km

    private const DETAILS = [
        'id' => 'place-q8',
        'rating' => 4.3,
        'userRatingCount' => 212,
        'googleMapsUri' => 'https://maps.google.com/?cid=1',
        'reviews' => [
            [
                'rating' => 5,
                'relativePublishTimeDescription' => '2 settimane fa',
                'text' => ['text' => 'Personale gentile.', 'languageCode' => 'it'],
                'authorAttribution' => ['displayName' => 'Mario R.', 'uri' => 'https://maps.google.com/u/1'],
            ],
            ['rating' => 2, 'relativePublishTimeDescription' => '1 mese fa'],
        ],
    ];

    /** @var list<array{request: RequestInterface}> */
    private array $calls = [];
    private Database $db;

    protected function setUp(): void
    {
        $this->db = self::importedDatabase();
        $this->calls = [];
    }

    /**
     * Google finto: risponde a searchText con $places e ai dettagli con $detailsStatus.
     *
     * @param list<array<string, mixed>>|null $places
     * @return \Slim\App<null>
     */
    private function appWithGoogle(?array $places = null, int $detailsStatus = 200, ?GoogleBudget $budget = null): \Slim\App
    {
        $places ??= [['id' => 'place-lontano', 'location' => self::FAR], ['id' => 'place-q8', 'location' => self::NEAR]];
        $handler = static function (RequestInterface $request) use ($places, $detailsStatus): Response {
            self::assertSame('google-key', $request->getHeaderLine('X-Goog-Api-Key'));
            if (str_ends_with($request->getUri()->getPath(), ':searchText')) {
                $body = json_decode((string) $request->getBody(), true);
                self::assertSame('gas_station', $body['includedType']);
                return new Response(200, [], json_encode(['places' => $places]));
            }
            return new Response($detailsStatus, [], json_encode(self::DETAILS));
        };
        $stack = HandlerStack::create(new MockHandler(array_fill(0, 10, $handler)));
        $stack->push(Middleware::history($this->calls));
        $google = new PlacesClient('google-key', new Client(['handler' => $stack]), budget: $budget);
        return self::app($this->db, google: $google);
    }

    /** @return list<bool> per ogni chiamata a Google: era una ricerca? */
    private function searches(): array
    {
        return array_map(
            static fn (array $c): bool => str_ends_with($c['request']->getUri()->getPath(), ':searchText'),
            $this->calls,
        );
    }

    public function testAbbinaIlLuogoPiuVicinoERestituisceLaValutazione(): void
    {
        $app = $this->appWithGoogle();
        [$status, $body] = self::get($app, '/v1/stations/1001/google-rating');
        self::assertSame(200, $status);
        self::assertSame('place-q8', $body['place_id']);
        self::assertSame([4.3, 212], [$body['rating'], $body['rating_count']]);
        self::assertSame('https://maps.google.com/?cid=1', $body['maps_url']);
        // Le recensioni (tariffa più cara) non si chiedono.
        self::assertSame([], $body['reviews']);
        self::assertSame('id,rating,userRatingCount,googleMapsUri', $this->calls[1]['request']->getHeaderLine('X-Goog-FieldMask'));
        self::assertSame('Valutazioni fornite da Google', $body['attribution']);

        // La seconda volta il place_id è già salvato: niente nuova ricerca.
        self::get($app, '/v1/stations/1001/google-rating');
        self::assertSame([true, false, false], $this->searches());
        self::assertSame('place-q8', $this->db->one('SELECT place_id FROM google_places WHERE station_id = 1001')['place_id']);
    }

    public function testNessunLuogoVicino404ENonRiprovaSubito(): void
    {
        $app = $this->appWithGoogle([['id' => 'place-lontano', 'location' => self::FAR]]);
        self::assertSame(404, self::get($app, '/v1/stations/1001/google-rating')[0]);
        self::assertSame(404, self::get($app, '/v1/stations/1001/google-rating')[0]);
        self::assertCount(1, $this->calls);
    }

    public function testErroreGoogle502(): void
    {
        [$status] = self::get($this->appWithGoogle(detailsStatus: 500), '/v1/stations/1001/google-rating');
        self::assertSame(502, $status);
    }

    public function testGoogleNonConfigurato503(): void
    {
        [$status] = self::get(self::app($this->db), '/v1/stations/1001/google-rating');
        self::assertSame(503, $status);
    }

    public function testLimiteGiornalieroDiRichieste(): void
    {
        $day = '2026-09-29';
        $budget = new GoogleBudget($this->db, 3, today: static function () use (&$day): string {
            return $day;
        });
        $app = $this->appWithGoogle(budget: $budget);

        // Ricerca + valutazione: 2 richieste. Poi solo la valutazione: 3.
        self::assertSame(200, self::get($app, '/v1/stations/1001/google-rating')[0]);
        self::assertSame(200, self::get($app, '/v1/stations/1001/google-rating')[0]);
        self::assertSame(3, $budget->used());

        // Limite raggiunto: 503 senza chiamare Google.
        [$status, $body] = self::get($app, '/v1/stations/1001/google-rating');
        self::assertSame(503, $status);
        self::assertStringContainsString('Limite giornaliero', $body['detail']);
        self::assertCount(3, $this->calls);

        // Il giorno dopo si riparte.
        $day = '2026-09-30';
        self::assertSame(200, self::get($app, '/v1/stations/1001/google-rating')[0]);
        self::assertSame(1, $budget->used());
    }

    public function testLimiteZeroVuolDireSenzaLimite(): void
    {
        $budget = new GoogleBudget($this->db, 0);
        $app = $this->appWithGoogle(budget: $budget);
        for ($i = 0; $i < 4; $i++) {
            self::assertSame(200, self::get($app, '/v1/stations/1001/google-rating')[0]);
        }
        self::assertSame(0, $budget->used());
    }

    public function testDistributoreInesistente404(): void
    {
        [$status] = self::get($this->appWithGoogle(), '/v1/stations/424242/google-rating');
        self::assertSame(404, $status);
        self::assertSame([], $this->calls);
    }
}
