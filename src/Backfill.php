<?php

declare(strict_types=1);

namespace Benzina;

use Benzina\Mimit\MimitFormatException;
use Benzina\Mimit\Parser;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Import dello storico dall'archivio MIMIT (bin/backfill.php): i file
 * giornalieri "prezzo_alle_8" dei giorni passati, sciolti o in un .tar.gz
 * trimestrale.
 *
 * Diversamente dall'import giornaliero:
 * - aggiunge allo storico (price_changes) solo i prezzi dei distributori già
 *   in anagrafica, senza toccare prezzi attuali, anagrafica e imports;
 * - calcola la media nazionale di ogni giorno (su tutti i distributori del file);
 * - non genera notifiche;
 * - salta i giorni già presenti (import giornaliero o storico già fatto).
 */
final class Backfill
{
    private const PRICE_HEADER = 'idImpianto|descCarburante|prezzo|isSelf|dtComu';
    private const BATCH = 2000;

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly LoggerInterface $log = new NullLogger(),
    ) {
    }

    /**
     * @param list<string> $paths file .csv, archivi .tar.gz/.tgz/.tar o cartelle
     * @return array{days: int, skipped: int, changes: int}
     */
    public function run(array $paths, ?string $from = null, ?string $to = null, bool $force = false): array
    {
        $files = [];
        foreach ($paths as $path) {
            foreach (self::priceFiles($path) as $day => $file) {
                if (($from === null || $day >= $from) && ($to === null || $day <= $to)) {
                    $files[$day] = $file;
                }
            }
        }
        ksort($files);

        $imported = self::days($this->db->all('SELECT day FROM imports'));
        $done = $force ? [] : self::days($this->db->all('SELECT DISTINCT day FROM national_averages'));
        $known = [];
        foreach ($this->db->all('SELECT id FROM stations') as $row) {
            $known[(int) $row['id']] = true;
        }

        $days = $skipped = $changes = 0;
        // Ultima comunicazione vista per distributore/carburante/modalità: tra
        // un giorno e il successivo si salvano solo quelle nuove.
        $lastSeen = [];
        foreach ($files as $day => $file) {
            if (isset($imported[$day]) || isset($done[$day])) {
                $skipped++;
                continue;
            }
            try {
                [$fileDay, $prices] = Parser::parsePrices(self::readLines($file));
            } catch (MimitFormatException $e) {
                $this->log->warning("$file: " . $e->getMessage());
                continue;
            }
            $prices = Importer::latestPerKey($prices);
            $averages = Importer::nationalAverages($prices, $fileDay, $this->config->averageMaxAgeDays);
            $count = count($prices);

            $new = $this->db->transaction(function () use (&$prices, &$lastSeen, $known, $averages, $fileDay): int {
                // Inserite a blocchi mentre si preparano: il primo giorno sono
                // decine di migliaia di righe, tutte insieme pesano troppo.
                $new = 0;
                $batch = [];
                foreach ($prices as $i => $p) {
                    $key = $p->stationId . '|' . $p->fuel->value . '|' . (int) $p->isSelf;
                    if (isset($known[$p->stationId]) && ($lastSeen[$key] ?? null) !== $p->reportedAt) {
                        $batch[] = [$p->stationId, $p->fuel->value, (int) $p->isSelf, $p->price, $p->reportedAt];
                    }
                    $lastSeen[$key] = $p->reportedAt;
                    unset($prices[$i]);
                    if (count($batch) >= self::BATCH) {
                        $new += $this->insertChanges($batch);
                        $batch = [];
                    }
                }
                $new += $this->insertChanges($batch);
                $this->db->execute('DELETE FROM national_averages WHERE day = ?', [$fileDay]);
                $this->db->insertMany('national_averages', ['day', 'fuel', 'mode', 'price', 'stations'], $averages);
                return $new;
            });
            $days++;
            $changes += $new;
            $this->log->info('storico importato', ['day' => $fileDay, 'prices' => $count, 'new_changes' => $new]);
            unset($prices);
            gc_collect_cycles();
        }
        return ['days' => $days, 'skipped' => $skipped, 'changes' => $changes];
    }

    /**
     * File dei prezzi contenuti in $path, per giorno (dalla prima riga
     * "Estrazione del ..."). Gli altri file (es. anagrafica) sono ignorati.
     *
     * @return array<string, string> giorno => percorso leggibile con file()
     */
    public static function priceFiles(string $path): array
    {
        if (!file_exists($path)) {
            throw new \InvalidArgumentException("File non trovato: $path");
        }
        if (is_dir($path)) {
            $candidates = glob(rtrim($path, '/\\') . '/*') ?: [];
        } elseif (preg_match('/\.(tar\.gz|tgz|tar)$/i', $path)) {
            $candidates = [];
            $archive = new \PharData($path);
            foreach (new \RecursiveIteratorIterator($archive) as $entry) {
                $candidates[] = $entry->getPathname();
            }
        } else {
            $candidates = [$path];
        }

        $files = [];
        foreach ($candidates as $file) {
            $handle = @fopen($file, 'r');
            if ($handle === false) {
                continue;
            }
            $first = trim((string) fgets($handle));
            $header = trim((string) fgets($handle));
            fclose($handle);
            if ($header === self::PRICE_HEADER && preg_match('/(\d{4}-\d{2}-\d{2})/', $first, $m)) {
                $files[$m[1]] = $file;
            }
        }
        return $files;
    }

    /** @param list<list<mixed>> $rows */
    private function insertChanges(array $rows): int
    {
        return $rows === [] ? 0 : $this->db->insertMany(
            'price_changes',
            ['station_id', 'fuel', 'is_self', 'price', 'reported_at'],
            $rows,
            ignoreDuplicates: true,
        );
    }

    /**
     * Righe del file una alla volta: un file giornaliero intero in memoria
     * pesa decine di MB, troppo per i limiti degli hosting condivisi.
     *
     * @return \Generator<int, string>
     */
    private static function readLines(string $file): \Generator
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            throw new MimitFormatException("impossibile leggere $file");
        }
        try {
            while (($line = fgets($handle)) !== false) {
                yield rtrim($line, "\r\n");
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, true>
     */
    private static function days(array $rows): array
    {
        $days = [];
        foreach ($rows as $row) {
            $days[substr((string) $row['day'], 0, 10)] = true;
        }
        return $days;
    }
}
