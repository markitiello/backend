<?php

declare(strict_types=1);

namespace Benzina;

/**
 * Log degli errori imprevisti.
 *
 * Non si usa error_log() senza un file configurato: in quel caso PHP scrive
 * su stderr e IIS (Windows, es. Plesk) trasforma qualsiasi risposta in un 500
 * vuoto, perdendo anche il messaggio. Si scrive quindi nel file di log di PHP
 * se è impostato, altrimenti in var/log/errori.log.
 */
final class ErrorLog
{
    public const FILE = __DIR__ . '/../var/log/errori.log';

    public static function write(string $message): void
    {
        $line = date('Y-m-d H:i:s') . ' ' . $message;
        $phpLog = (string) ini_get('error_log');
        if (PHP_SAPI === 'cli' || ($phpLog !== '' && $phpLog !== 'syslog')) {
            error_log($line);
            return;
        }
        $dir = dirname(self::FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(self::FILE, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
