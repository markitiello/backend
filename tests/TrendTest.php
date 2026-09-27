<?php

declare(strict_types=1);

namespace Benzina\Tests;

use Benzina\Database;
use Benzina\Fuel;
use Benzina\Mode;
use Benzina\Trend\PushSender;
use Benzina\Trend\Topics;
use Benzina\Trend\TrendAlerts;
use Benzina\Trend\TrendDetector;
use PHPUnit\Framework\Attributes\DataProvider;

final class TrendTest extends TestCase
{
    /** @return iterable<string, array{list<float>, array{direction: string, days: int}|null}> */
    public static function serie(): iterable
    {
        yield 'tre giorni di calo: inizia oggi' => [[1.85, 1.84, 1.83, 1.82], ['direction' => 'down', 'days' => 3]];
        yield 'tre giorni di aumento' => [[1.80, 1.80, 1.81, 1.82, 1.83], ['direction' => 'up', 'days' => 3]];
        yield 'il quarto giorno non si ripete' => [[1.85, 1.84, 1.83, 1.82, 1.81], null];
        yield 'solo due giorni' => [[1.85, 1.85, 1.84, 1.83], null];
        yield 'variazione troppo piccola' => [[1.800, 1.801, 1.802, 1.803], null];
        yield 'inversione' => [[1.80, 1.81, 1.82, 1.81], null];
        yield 'prezzo fermo oggi' => [[1.85, 1.84, 1.83, 1.83], null];
        yield 'dati insufficienti' => [[1.85, 1.84], null];
    }

    /**
     * @param list<float> $prices
     * @param array{direction: string, days: int}|null $expected
     */
    #[DataProvider('serie')]
    public function testRilevaLInizioDiUnaTendenza(array $prices, ?array $expected): void
    {
        $alert = (new TrendDetector(minDays: 3, minChange: 0.005))->detect($prices);
        if ($expected === null) {
            self::assertNull($alert);
            return;
        }
        self::assertNotNull($alert);
        self::assertSame($expected, ['direction' => $alert['direction'], 'days' => $alert['days']]);
    }

    public function testTopicPerCarburante(): void
    {
        self::assertSame('trend_benzina_self', Topics::forTrend(Fuel::Benzina, Mode::Self));
        self::assertSame('trend_diesel_servito', Topics::forTrend(Fuel::Diesel, Mode::Servito));
        self::assertSame('trend_gpl', Topics::forTrend(Fuel::Gpl, Mode::Any));
        self::assertCount(6, Topics::all());
    }

    public function testTestoDellaNotifica(): void
    {
        self::assertSame(
            ['Benzina self in calo', 'Media nazionale 1,819 €/l: −1,2% in 3 giorni.'],
            TrendAlerts::message(Fuel::Benzina, Mode::Self, 'down', 3, -0.012, 1.819),
        );
        [$title, $body] = TrendAlerts::message(Fuel::Metano, Mode::Any, 'up', 3, 0.021, 1.459);
        self::assertSame('Metano in aumento', $title);
        self::assertStringStartsWith('Media nazionale 1,459 €/kg: +2,1% in 3 giorni.', $body);
    }

    /**
     * Medie nazionali giornaliere dal 20 settembre 2026 in poi.
     *
     * @param array<string, list<float>> $series "fuel|mode" => prezzi
     */
    private static function seed(Database $db, array $series): void
    {
        $rows = [];
        foreach ($series as $key => $prices) {
            [$fuel, $mode] = explode('|', $key);
            foreach ($prices as $i => $price) {
                $rows[] = [date('Y-m-d', strtotime("2026-09-20 +$i days")), $fuel, $mode, $price, 1000];
            }
        }
        $db->insertMany('national_averages', ['day', 'fuel', 'mode', 'price', 'stations'], $rows);
    }

    private static function sender(): PushSender
    {
        return new class implements PushSender {
            /** @var list<array{string, string, string, array<string, string>}> */
            public array $sent = [];
            public bool $fail = false;

            public function sendToTopic(string $topic, string $title, string $body, array $data): void
            {
                if ($this->fail) {
                    throw new \RuntimeException('FCM non raggiungibile');
                }
                $this->sent[] = [$topic, $title, $body, $data];
            }
        };
    }

    public function testDopoLImportInviaUnaNotificaPerTendenza(): void
    {
        $db = self::database();
        // 20..23 settembre: benzina self in calo per 3 giorni, diesel stabile, GPL in aumento.
        self::seed($db, [
            'benzina|self' => [1.850, 1.840, 1.830, 1.820],
            'diesel|self' => [1.750, 1.750, 1.751, 1.750],
            'gpl|any' => [0.700, 0.705, 0.710, 0.716],
        ]);
        $push = self::sender();
        $alerts = new TrendAlerts($db, self::config(), $push);

        self::assertSame(['detected' => 2, 'sent' => 2], $alerts->run('2026-09-23'));
        self::assertSame(['trend_benzina_self', 'trend_gpl'], array_column($push->sent, 0));
        self::assertSame('Benzina self in calo', $push->sent[0][1]);
        self::assertSame(
            ['type' => 'trend', 'fuel' => 'benzina', 'mode' => 'self', 'direction' => 'down', 'day' => '2026-09-23'],
            $push->sent[0][3],
        );

        // Rilanciare l'import dello stesso giorno non manda doppioni.
        self::assertSame(['detected' => 0, 'sent' => 0], $alerts->run('2026-09-23'));
        self::assertCount(2, $push->sent);
    }

    public function testDopoUnAvvisoPausaNellaStessaDirezione(): void
    {
        $db = self::database();
        // Calo di 3 giorni (avviso il 23), risalita di un giorno, di nuovo calo di 3 giorni il 27.
        self::seed($db, ['benzina|self' => [1.85, 1.84, 1.83, 1.82, 1.83, 1.82, 1.81, 1.80]]);
        $push = self::sender();
        $alerts = new TrendAlerts($db, self::config(), $push);
        foreach (['2026-09-23', '2026-09-24', '2026-09-25', '2026-09-26', '2026-09-27'] as $day) {
            $alerts->run($day);
        }
        self::assertCount(1, $push->sent, 'il secondo calo cade nei 7 giorni di pausa');
    }

    public function testNotificaNonInviataVieneRitentata(): void
    {
        $db = self::database();
        self::seed($db, ['benzina|self' => [1.85, 1.84, 1.83, 1.82]]);
        $push = self::sender();
        $push->fail = true;
        $alerts = new TrendAlerts($db, self::config(), $push);
        self::assertSame(['detected' => 1, 'sent' => 0], $alerts->run('2026-09-23'));

        $push->fail = false;
        self::assertSame(['detected' => 0, 'sent' => 1], $alerts->run('2026-09-23'));
        $sentAt = TrendAlerts::recent($db, '2026-09-23', 1)[0]['sent_at'];
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', (string) $sentAt);
    }

    public function testSenzaFcmGliAvvisiRestanoConsultabili(): void
    {
        $db = self::database();
        self::seed($db, ['benzina|self' => [1.85, 1.84, 1.83, 1.82]]);
        self::assertSame(['detected' => 1, 'sent' => 0], (new TrendAlerts($db, self::config()))->run('2026-09-23'));
        $db->insertMany('imports', ['day', 'stations', 'prices', 'finished_at'], [['2026-09-23', 1, 1, '2026-09-23 09:00:00']]);

        $app = self::app($db);
        [$status, $body] = self::get($app, '/v1/trends/alerts', ['days' => '7']);
        self::assertSame(200, $status);
        self::assertCount(1, $body['alerts']);
        self::assertSame(
            ['fuel' => 'benzina', 'mode' => 'self', 'day' => '2026-09-23', 'direction' => 'down', 'days' => 3, 'topic' => 'trend_benzina_self'],
            array_intersect_key($body['alerts'][0], array_flip(['fuel', 'mode', 'day', 'direction', 'days', 'topic'])),
        );
        self::assertEqualsWithDelta(1.82 / 1.85 - 1, $body['alerts'][0]['change'], 1e-4);
        self::assertNull($body['alerts'][0]['sent_at'], 'senza FCM la notifica non è partita');

        [, $body] = self::get($app, '/v1/trends/alerts', ['fuel' => 'diesel']);
        self::assertSame([], $body['alerts']);
        [$status] = self::get($app, '/v1/trends/alerts', ['days' => '0'], checkSpec: false);
        self::assertSame(422, $status);
    }
}
