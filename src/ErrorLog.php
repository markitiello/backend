<?php

declare(strict_types=1);

namespace Benzina;

/**
 * Log degli errori imprevisti.
 *
 * Non si usa error_log() senza un file configurato: in quel caso PHP scrive
 * su stderr e IIS (Windows, es. Plesk) trasforma qualsiasi risposta in un 500
 * vuoto, perdendo anche il messaggio. Si scrive direttamente nel file di log
 * di PHP se è impostato, altrimenti in var/log/errori.log; se il file non è
 * scrivibile il messaggio si perde, ma la risposta resta corretta.
 */
final class ErrorLog
{
    public const FILE = __DIR__ . '/../var/log/errori.log';

    public static function write(string $message): void
    {
        $line = date('Y-m-d H:i:s') . ' ' . $message;
        if (PHP_SAPI === 'cli') {
            error_log($line);
            return;
        }
        $file = (string) ini_get('error_log');
        if ($file === '' || $file === 'syslog') {
            $file = self::FILE;
            if (!is_dir(dirname($file))) {
                @mkdir(dirname($file), 0775, true);
            }
        }
        // Mai error_log() su un file non scrivibile: PHP ripiegherebbe su stderr.
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
