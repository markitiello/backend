<?php

declare(strict_types=1);

/**
 * Import dello storico dall'archivio MIMIT (file giornalieri "prezzo_alle_8"
 * dei giorni passati, ad esempio i .tar.gz trimestrali).
 *
 *   php bin/backfill.php var/storico/2026-T2.tar.gz
 *   php bin/backfill.php var/storico/ --from=2026-04-01 --to=2026-06-30
 *
 * I percorsi relativi partono dalla cartella corrente o, se non esistono lì,
 * dalla cartella del progetto. I giorni già presenti vengono saltati
 * (--force rifà quelli importati dallo storico, mai quelli dell'import
 * giornaliero). Non tocca i prezzi attuali e non invia notifiche.
 */

use Benzina\Backfill;
use Benzina\Config;
use Benzina\Database;
use Psr\Log\AbstractLogger;

require __DIR__ . '/../vendor/autoload.php';

// Opzioni e percorsi in qualsiasi ordine (getopt si ferma al primo percorso).
$options = [];
$paths = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(from|to)=(.*)$/', $arg, $m)) {
        $options[$m[1]] = $m[2];
    } elseif ($arg === '--force' || $arg === '--help') {
        $options[substr($arg, 2)] = true;
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "Opzione sconosciuta: $arg\n");
        exit(1);
    } else {
        $paths[] = $arg;
    }
}
if (isset($options['help']) || $paths === []) {
    fwrite(STDOUT, "Uso: php bin/backfill.php FILE_O_CARTELLA... [--from=AAAA-MM-GG] [--to=AAAA-MM-GG] [--force]\n");
    exit($paths === [] && !isset($options['help']) ? 1 : 0);
}
foreach (['from', 'to'] as $name) {
    if (isset($options[$name]) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $options[$name])) {
        fwrite(STDERR, "--$name deve essere una data AAAA-MM-GG\n");
        exit(1);
    }
}
$paths = array_map(
    static fn (string $p): string => file_exists($p) ? $p : dirname(__DIR__) . '/' . $p,
    $paths,
);

$config = Config::fromEnv(Config::loadEnv(__DIR__ . '/../.env'));
$db = Database::connect($config);
$db->createSchema();

$log = new class extends AbstractLogger {
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        fwrite(STDERR, strtoupper((string) $level) . " $message " . ($context ? json_encode($context) : '') . "\n");
    }
};

try {
    $result = (new Backfill($db, $config, $log))->run(
        $paths,
        $options['from'] ?? null,
        $options['to'] ?? null,
        isset($options['force']),
    );
} catch (\Throwable $e) {
    fwrite(STDERR, 'ERRORE ' . $e->getMessage() . "\n");
    exit(1);
}
fwrite(STDOUT, sprintf(
    "Storico: %d giorni importati, %d già presenti, %d prezzi aggiunti.\n",
    $result['days'],
    $result['skipped'],
    $result['changes'],
));
