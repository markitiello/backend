<?php

declare(strict_types=1);

namespace Benzina\Tests;

use Benzina\Backfill;
use Benzina\Database;

final class BackfillTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/benzina-backfill-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /** @param list<string> $rows righe "id|carburante|prezzo|self|gg/mm/aaaa hh:mm:ss" */
    private static function priceFile(string $day, array $rows): string
    {
        return "Estrazione del $day\nidImpianto|descCarburante|prezzo|isSelf|dtComu\n" . implode("\n", $rows) . "\n";
    }

    /** Archivio trimestrale come quello del MIMIT: file giornalieri in un .tar.gz. */
    private function archive(): string
    {
        $files = [
            'prezzo_alle_8-20260920.csv' => self::priceFile('2026-09-20', [
                '1001|Benzina|1.800|1|19/09/2026 08:00:00',
                '1002|Benzina|1.820|1|18/09/2026 08:00:00',
                '9999|Benzina|1.700|1|19/09/2026 08:00:00',
            ]),
            'prezzo_alle_8-20260921.csv' => self::priceFile('2026-09-21', [
                '1001|Benzina|1.800|1|19/09/2026 08:00:00', // invariato
                '1002|Benzina|1.810|1|20/09/2026 09:00:00', // cambiato
                '9999|Benzina|1.690|1|20/09/2026 08:00:00',
            ]),
            // Già coperto dall'import giornaliero: va saltato.
            'prezzo_alle_8-20260924.csv' => self::priceFile('2026-09-24', [
                '1001|Benzina|9.000|1|24/09/2026 08:00:00',
            ]),
            'anagrafica_impianti_attivi-20260920.csv' => "Estrazione del 2026-09-20\nidImpianto|Gestore|Bandiera\n",
        ];
        $tar = $this->dir . '/2026-T3.tar';
        $archive = new \PharData($tar);
        foreach ($files as $name => $content) {
            $archive->addFromString($name, $content);
        }
        $archive->compress(\Phar::GZ);
        unset($archive);
        unlink($tar);
        return $tar . '.gz';
    }

    private static function rows(Database $db, string $table, string $where = '1 = 1'): int
    {
        return (int) $db->one("SELECT COUNT(*) AS n FROM $table WHERE $where")['n'];
    }

    public function testImportaLoStoricoDaUnArchivioTrimestrale(): void
    {
        $db = self::importedDatabase();
        $current = self::rows($db, 'current_prices');
        $changes = self::rows($db, 'price_changes');
        $archive = $this->archive();

        $result = (new Backfill($db, self::config()))->run([$archive]);

        self::assertSame(['days' => 2, 'skipped' => 1, 'changes' => 3], $result);
        // Solo le comunicazioni nuove dei distributori in anagrafica: 1001 una
        // volta, 1002 due; 9999 non è in anagrafica.
        self::assertSame($changes + 3, self::rows($db, 'price_changes'));
        self::assertSame(0, self::rows($db, 'price_changes', 'station_id = 9999'));
        // La media nazionale conta tutti i distributori del file.
        $avg = $db->one("SELECT price, stations FROM national_averages WHERE day = '2026-09-20' AND fuel = 'benzina' AND mode = 'self'");
        self::assertEqualsWithDelta((1.80 + 1.82 + 1.70) / 3, (float) $avg['price'], 1e-4);
        self::assertSame(3, (int) $avg['stations']);
        // Prezzi attuali, import giornalieri e giorno già importato intatti.
        self::assertSame($current, self::rows($db, 'current_prices'));
        self::assertSame(2, self::rows($db, 'imports'));
        self::assertSame(0, self::rows($db, 'price_changes', 'price > 5'));

        // Rilanciato: nessun doppione.
        self::assertSame(['days' => 0, 'skipped' => 3, 'changes' => 0], (new Backfill($db, self::config()))->run([$archive]));
        // --force rifà solo i giorni dello storico, mai quelli giornalieri.
        self::assertSame(['days' => 2, 'skipped' => 1, 'changes' => 0], (new Backfill($db, self::config()))->run([$archive], force: true));
    }

    public function testFiltroPerDateECartelle(): void
    {
        $db = self::importedDatabase();
        file_put_contents($this->dir . '/a.csv', self::priceFile('2026-09-20', ['1001|Benzina|1.800|1|19/09/2026 08:00:00']));
        file_put_contents($this->dir . '/b.csv', self::priceFile('2026-09-21', ['1001|Benzina|1.810|1|20/09/2026 08:00:00']));
        file_put_contents($this->dir . '/leggimi.txt', 'non è un file del MIMIT');

        $result = (new Backfill($db, self::config()))->run([$this->dir], from: '2026-09-21');

        self::assertSame(['days' => 1, 'skipped' => 0, 'changes' => 1], $result);
        self::assertSame(0, self::rows($db, 'national_averages', "day = '2026-09-20'"));
    }

    public function testFileInesistente(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Backfill(self::database(), self::config()))->run([$this->dir . '/manca.tar.gz']);
    }
}
