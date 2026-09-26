<?php

declare(strict_types=1);

namespace Benzina\Mimit;

use Benzina\Fuel;
use DateTimeImmutable;

/**
 * Lettura dei file open data del MIMIT (Osservaprezzi Carburanti).
 *
 * Formato (dal 10/02/2026): UTF-8, separatore "|", prima riga
 * "Estrazione del AAAA-MM-GG", seconda riga con le intestazioni.
 */
final class Parser
{
    public const SEPARATOR = '|';
    private const STATION_COLUMNS = 10;
    private const PRICE_COLUMNS = 5;

    public static function extractionDate(string $firstLine): string
    {
        if (!preg_match('/^\x{FEFF}?Estrazione del (\d{4}-\d{2}-\d{2})\s*$/u', $firstLine, $m)) {
            throw new MimitFormatException('prima riga inattesa: ' . $firstLine);
        }
        return $m[1];
    }

    /**
     * Anagrafica. Le righe senza coordinate valide vengono scartate.
     *
     * @param iterable<string> $lines
     * @return array{string, list<StationRow>} giorno di estrazione e distributori
     */
    public static function parseStations(iterable $lines): array
    {
        [$day, $body] = self::split($lines);
        $rows = [];
        foreach ($body as $line) {
            $parts = explode(self::SEPARATOR, $line);
            if (count($parts) < self::STATION_COLUMNS) {
                continue;
            }
            // Il nome dell'impianto può contenere "|": i primi 4 campi si leggono
            // da sinistra, gli ultimi 5 da destra e il resto è il nome.
            $head = array_slice($parts, 0, 4);
            $tail = array_slice($parts, -5);
            $name = implode(self::SEPARATOR, array_slice($parts, 4, count($parts) - 9));
            if (!is_numeric($head[0]) || !is_numeric(trim($tail[3])) || !is_numeric(trim($tail[4]))) {
                continue;
            }
            $lat = (float) $tail[3];
            $lng = (float) $tail[4];
            if ($lat < 35 || $lat > 48 || $lng < 6 || $lng > 19) { // fuori dall'Italia
                continue;
            }
            $rows[] = new StationRow(
                id: (int) $head[0],
                operator: self::clean($head[1]),
                brand: self::clean($head[2]),
                kind: self::clean($head[3]),
                name: self::clean($name),
                address: self::clean($tail[0]),
                city: self::clean($tail[1]),
                province: self::clean($tail[2]),
                lat: $lat,
                lng: $lng,
            );
        }
        return [$day, $rows];
    }

    /**
     * Prezzi. Si tengono solo benzina, gasolio, GPL e metano "base".
     *
     * @param iterable<string> $lines
     * @return array{string, list<PriceRow>}
     */
    public static function parsePrices(iterable $lines): array
    {
        [$day, $body] = self::split($lines);
        $rows = [];
        foreach ($body as $line) {
            $parts = explode(self::SEPARATOR, $line);
            if (count($parts) !== self::PRICE_COLUMNS) {
                continue;
            }
            $fuel = Fuel::fromMimit(trim($parts[1]));
            if ($fuel === null || !is_numeric($parts[0]) || !is_numeric(trim($parts[2]))) {
                continue;
            }
            $price = (float) $parts[2];
            $reportedAt = DateTimeImmutable::createFromFormat('!d/m/Y H:i:s', trim($parts[4]));
            if ($reportedAt === false || $price < 0.3 || $price > 5.0) { // errori evidenti
                continue;
            }
            $rows[] = new PriceRow(
                stationId: (int) $parts[0],
                fuel: $fuel,
                isSelf: trim($parts[3]) === '1',
                price: $price,
                reportedAt: $reportedAt->format('Y-m-d H:i:s'),
            );
        }
        return [$day, $rows];
    }

    /**
     * @param iterable<string> $lines
     * @return array{string, \Generator<string>}
     */
    private static function split(iterable $lines): array
    {
        $iterator = (static fn () => yield from $lines)();
        if (!$iterator->valid()) {
            throw new MimitFormatException('file vuoto');
        }
        $day = self::extractionDate($iterator->current());
        $iterator->next();
        if (!$iterator->valid() || !str_starts_with(trim($iterator->current()), 'idImpianto' . self::SEPARATOR)) {
            throw new MimitFormatException('intestazione mancante o inattesa');
        }
        $iterator->next();
        $body = (static function () use ($iterator) {
            for (; $iterator->valid(); $iterator->next()) {
                $line = rtrim($iterator->current(), "\r\n");
                if (trim($line) !== '') {
                    yield $line;
                }
            }
        })();
        return [$day, $body];
    }

    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
