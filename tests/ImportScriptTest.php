<?php

declare(strict_types=1);

namespace Benzina\Tests;

/** bin/import.php eseguito davvero, come dal cron. */
final class ImportScriptTest extends TestCase
{
    private string $dbFile;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/benzina-import-' . bin2hex(random_bytes(4)) . '.db';
    }

    protected function tearDown(): void
    {
        @unlink($this->dbFile);
    }

    /**
     * @param list<string> $args
     * @return array{int, string} codice di uscita e stderr
     */
    private function import(array $args): array
    {
        $command = [PHP_BINARY, '-d', 'memory_limit=32M', dirname(__DIR__) . '/bin/import.php', ...$args];
        $env = ['BENZINA_DB_DSN' => 'sqlite:' . $this->dbFile, 'PATH' => (string) getenv('PATH')];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stderr];
    }

    public function testImportDaFileLocali(): void
    {
        $fixtures = __DIR__ . '/fixtures';
        [$code, $stderr] = $this->import([
            '--no-alerts',
            "--stations-file=$fixtures/stations_day1.csv",
            "--prices-file=$fixtures/prices_day1.csv",
        ]);
        self::assertSame(0, $code, $stderr);
        self::assertStringContainsString('INFO import completato {"day":"2026-09-24","stations":5,"prices":11,"new_changes":11}', $stderr);
    }

    public function testFileMancante(): void
    {
        [$code, $stderr] = $this->import(['--no-alerts', '--stations-file=/non/esiste.csv', '--prices-file=/non/esiste.csv']);
        self::assertSame(1, $code);
        self::assertStringContainsString('Impossibile leggere /non/esiste.csv', $stderr);
    }
}
