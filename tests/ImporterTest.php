<?php

declare(strict_types=1);

namespace Benzina\Tests;

use Benzina\Database;
use Benzina\Importer;

final class ImporterTest extends TestCase
{
    private static function average(Database $db, string $day, string $fuel, string $mode): ?float
    {
        $row = $db->one(
            'SELECT price FROM national_averages WHERE day = ? AND fuel = ? AND mode = ?',
            [$day, $fuel, $mode],
        );
        return $row === null ? null : (float) $row['price'];
    }

    private static function rows(Database $db, string $table): int
    {
        return (int) $db->one("SELECT COUNT(*) AS n FROM $table")['n'];
    }

    public function testPrimoImport(): void
    {
        $db = self::database();
        $result = (new Importer($db, self::config()))->run(
            self::lines('stations_day1.csv'),
            self::lines('prices_day1.csv'),
        );
        // 9999 non è in anagrafica, 1006 non ha coordinate: esclusi.
        self::assertSame(['day' => '2026-09-24', 'stations' => 5, 'prices' => 11, 'new_changes' => 11], $result);
        self::assertSame(5, self::rows($db, 'stations'));

        // Il prezzo del 1004 è di giugno: resta tra i prezzi attuali ma non
        // entra nella media nazionale.
        self::assertEqualsWithDelta((1.739 + 1.759 + 1.772 + 1.701) / 4, self::average($db, '2026-09-24', 'benzina', 'self'), 1e-4);
        self::assertEqualsWithDelta(1.899, self::average($db, '2026-09-24', 'benzina', 'servito'), 1e-9);
        // GPL e metano: un prezzo per distributore, il più basso.
        self::assertEqualsWithDelta((0.749 + 0.719) / 2, self::average($db, '2026-09-24', 'gpl', 'any'), 1e-9);
        self::assertEqualsWithDelta(1.459, self::average($db, '2026-09-24', 'metano', 'any'), 1e-9);
    }

    public function testSecondoImportAggiornaESalvaSoloLeVariazioni(): void
    {
        $db = self::importedDatabase();
        self::assertSame(13, self::rows($db, 'price_changes'));
        self::assertSame(11, self::rows($db, 'current_prices'));
        $current = $db->one("SELECT price FROM current_prices WHERE station_id = 1001 AND fuel = 'benzina' AND is_self = 1");
        self::assertEqualsWithDelta(1.729, (float) $current['price'], 1e-9);
        $station = $db->one('SELECT name, last_seen FROM stations WHERE id = 1001');
        self::assertSame('Q8 EASY PACINI', $station['name']);
        self::assertSame('2026-09-25', substr((string) $station['last_seen'], 0, 10));
        self::assertSame(2, self::rows($db, 'imports'));
    }

    public function testReimportDelloStessoGiornoEIdempotente(): void
    {
        $db = self::importedDatabase();
        $result = (new Importer($db, self::config()))->run(
            self::lines('stations_day2.csv'),
            self::lines('prices_day2.csv'),
        );
        self::assertSame(0, $result['new_changes']);
        $n = $db->one("SELECT COUNT(*) AS n FROM national_averages WHERE day = '2026-09-25'")['n'];
        self::assertSame(5, (int) $n); // benzina self/servito, gasolio self, gpl, metano
    }

    public function testMediaSenzaValoriAnomali(): void
    {
        self::assertEqualsWithDelta((1.8 + 1.82 + 1.79) / 3, Importer::trimmedMean([1.8, 1.82, 1.79, 0.18]), 1e-9);
        self::assertNull(Importer::trimmedMean([]));
    }
}
