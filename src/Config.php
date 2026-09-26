<?php

declare(strict_types=1);

namespace Benzina;

/**
 * Configurazione letta dalle variabili d'ambiente con prefisso BENZINA_
 * (vedi .env.example).
 */
final class Config
{
    public const MIMIT_STATIONS_URL = 'https://www.mimit.gov.it/images/exportCSV/anagrafica_impianti_attivi.csv';
    public const MIMIT_PRICES_URL = 'https://www.mimit.gov.it/images/exportCSV/prezzo_alle_8.csv';

    /**
     * @param list<string> $appCheckAppIds
     * @param list<string> $apiKeys
     */
    public function __construct(
        // DSN PDO: sqlite:/percorso/file.db, pgsql:host=...;dbname=..., mysql:host=...;dbname=...
        public readonly string $dbDsn = 'sqlite::memory:',
        public readonly ?string $dbUser = null,
        public readonly ?string $dbPassword = null,
        // Numero del progetto Firebase: attiva la verifica dei token App Check.
        public readonly ?string $appCheckProjectNumber = null,
        // ID delle app Firebase ammesse (Android e iOS). Vuoto = tutte quelle del progetto.
        public readonly array $appCheckAppIds = [],
        // Chiavi statiche per sviluppo, test e chiamate da server.
        public readonly array $apiKeys = [],
        // Solo sviluppo locale: nessun controllo di accesso.
        public readonly bool $authDisabled = false,
        public readonly ?string $googlePlacesApiKey = null,
        // Dopo quanti giorni riprovare l'abbinamento di un distributore non trovato su Google.
        public readonly int $googleRematchDays = 30,
        public readonly string $mimitStationsUrl = self::MIMIT_STATIONS_URL,
        public readonly string $mimitPricesUrl = self::MIMIT_PRICES_URL,
        // I prezzi comunicati da più giorni di così non entrano nelle medie.
        public readonly int $averageMaxAgeDays = 30,
        public readonly bool $docsEnabled = true,
        // --- Notifiche di tendenza (vedi src/Trend) ---------------------------
        // Service account Firebase (JSON) per inviare le notifiche push con FCM:
        // percorso del file oppure il contenuto stesso.
        public readonly ?string $fcmCredentials = null,
        // Giorni consecutivi di salita o discesa della media nazionale.
        public readonly int $trendMinDays = 3,
        // Variazione minima complessiva nei giorni della tendenza (0.005 = 0,5%).
        public readonly float $trendMinChange = 0.005,
        // Dopo un avviso, giorni senza un altro avviso nella stessa direzione.
        public readonly int $trendCooldownDays = 7,
    ) {
    }

    /** @param array<string, string|false> $env */
    public static function fromEnv(array $env): self
    {
        $get = static fn (string $name): ?string => isset($env['BENZINA_' . $name])
            && $env['BENZINA_' . $name] !== false && $env['BENZINA_' . $name] !== ''
            ? (string) $env['BENZINA_' . $name] : null;
        $list = static fn (string $name): array => array_values(array_filter(
            array_map('trim', explode(',', $get($name) ?? '')),
            static fn (string $v): bool => $v !== '',
        ));
        $bool = static fn (string $name, bool $default): bool => $get($name) === null
            ? $default : in_array(strtolower((string) $get($name)), ['1', 'true', 'yes', 'on'], true);

        return new self(
            dbDsn: self::resolveSqlitePath($get('DB_DSN') ?? 'sqlite:var/benzina.db'),
            dbUser: $get('DB_USER'),
            dbPassword: $get('DB_PASSWORD'),
            appCheckProjectNumber: $get('APPCHECK_PROJECT_NUMBER'),
            appCheckAppIds: $list('APPCHECK_APP_IDS'),
            apiKeys: $list('API_KEYS'),
            authDisabled: $bool('AUTH_DISABLED', false),
            googlePlacesApiKey: $get('GOOGLE_PLACES_API_KEY'),
            googleRematchDays: (int) ($get('GOOGLE_REMATCH_DAYS') ?? 30),
            mimitStationsUrl: $get('MIMIT_STATIONS_URL') ?? self::MIMIT_STATIONS_URL,
            mimitPricesUrl: $get('MIMIT_PRICES_URL') ?? self::MIMIT_PRICES_URL,
            averageMaxAgeDays: (int) ($get('AVERAGE_MAX_AGE_DAYS') ?? 30),
            docsEnabled: $bool('DOCS_ENABLED', true),
            fcmCredentials: $get('FCM_CREDENTIALS'),
            trendMinDays: (int) ($get('TREND_MIN_DAYS') ?? 3),
            trendMinChange: (float) ($get('TREND_MIN_CHANGE') ?? 0.005),
            trendCooldownDays: (int) ($get('TREND_COOLDOWN_DAYS') ?? 7),
        );
    }

    /** "sqlite:var/x.db" è relativo alla cartella del progetto, non a quella corrente. */
    private static function resolveSqlitePath(string $dsn): string
    {
        if (!str_starts_with($dsn, 'sqlite:') || $dsn === 'sqlite::memory:') {
            return $dsn;
        }
        $path = substr($dsn, strlen('sqlite:'));
        // Assoluto: /percorso (Linux, macOS) oppure C:\percorso o \\server (Windows).
        $absolute = preg_match('#^(/|\\\\|[A-Za-z]:[\\\\/])#', $path) === 1;
        return $absolute ? $dsn : 'sqlite:' . dirname(__DIR__) . '/' . $path;
    }

    /**
     * Legge un file .env (righe CHIAVE=valore) senza sovrascrivere le variabili
     * già presenti nell'ambiente.
     *
     * @return array<string, string>
     */
    public static function loadEnv(string $file): array
    {
        $env = getenv();
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = array_map('trim', explode('=', $line, 2));
                $env[$key] ??= trim($value, "\"'");
            }
        }
        return $env;
    }
}
