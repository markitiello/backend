<?php

declare(strict_types=1);

namespace Benzina\Tests;

use Benzina\Database;

final class DatabaseTest extends TestCase
{
    private static function withPrices(): Database
    {
        $db = self::database();
        $db->insertMany('import_latest', ['station_id', 'fuel', 'is_self', 'price', 'reported_at'], [
            [1, 'benzina', 1, 1.7, '2026-09-24 08:00:00'],
            [2, 'benzina', 1, 1.8, '2026-09-24 09:00:00'],
            [3, 'diesel', 0, 1.6, '2026-09-24 10:00:00'],
        ]);
        return $db;
    }

    public function testEachUnaRigaAllaVolta(): void
    {
        $db = self::withPrices();
        $rows = $db->each('SELECT station_id FROM import_latest WHERE fuel = ? ORDER BY station_id', ['benzina']);
        self::assertInstanceOf(\Generator::class, $rows);
        self::assertSame([1, 2], array_map(static fn (array $r): int => (int) $r['station_id'], iterator_to_array($rows, false)));
    }

    public function testEachInterrottoLasciaIlDatabaseUsabile(): void
    {
        $db = self::withPrices();
        foreach ($db->each('SELECT station_id FROM import_latest ORDER BY station_id') as $row) {
            self::assertSame(1, (int) $row['station_id']);
            break;
        }
        // Il cursore è chiuso: le altre query funzionano (MySQL non ne vuole due aperte).
        $db->execute('DELETE FROM import_latest WHERE station_id = 3');
        self::assertSame(2, (int) $db->one('SELECT COUNT(*) AS n FROM import_latest')['n']);
    }

    public function testInsertFrom(): void
    {
        $db = self::withPrices();
        $columns = ['station_id', 'fuel', 'is_self', 'price', 'reported_at'];
        $select = 'SELECT station_id, fuel, is_self, price, reported_at FROM import_latest';

        self::assertSame(2, $db->insertFrom('current_prices', $columns, "$select WHERE fuel = ?", ['benzina']));
        self::assertSame(2, (int) $db->one('SELECT COUNT(*) AS n FROM current_prices')['n']);

        // Con $ignoreDuplicates le righe già presenti si saltano e non si contano.
        self::assertSame(1, $db->insertFrom('current_prices', $columns, $select, ignoreDuplicates: true));
        self::assertSame(0, $db->insertFrom('current_prices', $columns, $select, ignoreDuplicates: true));
        self::assertSame(3, (int) $db->one('SELECT COUNT(*) AS n FROM current_prices')['n']);
    }

    public function testInsertFromSenzaIgnoreFallisceSuiDuplicati(): void
    {
        $db = self::withPrices();
        $columns = ['station_id', 'fuel', 'is_self', 'price', 'reported_at'];
        $select = 'SELECT station_id, fuel, is_self, price, reported_at FROM import_latest';
        $db->insertFrom('current_prices', $columns, $select);
        $this->expectException(\PDOException::class);
        $db->insertFrom('current_prices', $columns, $select);
    }

    public function testSchemaConTabelleDiAppoggioRipetibile(): void
    {
        $db = self::database();
        $db->createSchema(); // seconda volta: nessun errore (indici compresi)
        self::assertSame(0, (int) $db->one('SELECT COUNT(*) AS n FROM import_prices')['n']);
        self::assertSame(0, (int) $db->one('SELECT COUNT(*) AS n FROM import_latest')['n']);
    }
}
