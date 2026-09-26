<?php

declare(strict_types=1);

namespace Benzina\Tests;

use Benzina\Fuel;
use Benzina\Mimit\MimitFormatException;
use Benzina\Mimit\Parser;
use PHPUnit\Framework\Attributes\DataProvider;

final class ParserTest extends TestCase
{
    public function testAnagraficaLeggeDataERigheValide(): void
    {
        [$day, $stations] = Parser::parseStations(self::lines('stations_day1.csv'));
        self::assertSame('2026-09-24', $day);
        // 1006 non ha coordinate, 1007 è troncata.
        self::assertSame([1001, 1002, 1003, 1004, 1005], array_map(fn ($s) => $s->id, $stations));
    }

    public function testAnagraficaNomeConSeparatoreESpazi(): void
    {
        [, $stations] = Parser::parseStations(self::lines('stations_day1.csv'));
        $byId = array_column(array_map(fn ($s) => ['id' => $s->id, 's' => $s], $stations), 's', 'id');
        // Il "|" nel nome non sposta gli altri campi.
        self::assertSame('STOIL SIMPLE | gestori.prezzibenzina.it', $byId[1003]->name);
        self::assertSame('VIA PORPORA 110', $byId[1003]->address);
        self::assertEqualsWithDelta(45.4821, $byId[1003]->lat, 1e-9);
        // Tabulazioni e spazi doppi vengono ripuliti.
        self::assertSame('19829 ARGONNE', $byId[1002]->name);
        self::assertSame('VIA PACINI 12', $byId[1001]->address);
    }

    public function testPrezziSoloCarburantiBaseEValoriPlausibili(): void
    {
        [$day, $prices] = Parser::parsePrices(self::lines('prices_day1.csv'));
        self::assertSame('2026-09-24', $day);
        $fuels = array_unique(array_map(fn ($p) => $p->fuel, $prices), SORT_REGULAR);
        self::assertEqualsCanonicalizing(Fuel::cases(), array_values($fuels));
        // Niente Blue Diesel, niente 9.999, niente righe rotte.
        foreach ($prices as $p) {
            self::assertLessThan(5, $p->price);
        }
        self::assertSame([1001, true, 1.739, '2026-09-23 20:00:21'], [
            $prices[0]->stationId, $prices[0]->isSelf, $prices[0]->price, $prices[0]->reportedAt,
        ]);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function fileMalformati(): iterable
    {
        yield 'vuoto' => [[]];
        yield 'senza riga di estrazione' => [['idImpianto|descCarburante|prezzo|isSelf|dtComu']];
        yield 'intestazione sbagliata' => [['Estrazione del 2026-09-24', 'altro|header']];
        yield 'troncato' => [['Estrazione del 2026-09-24']];
    }

    /** @param list<string> $lines */
    #[DataProvider('fileMalformati')]
    public function testFormatoInatteso(array $lines): void
    {
        $this->expectException(MimitFormatException::class);
        Parser::parsePrices($lines);
    }
}
