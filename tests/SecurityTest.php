<?php

declare(strict_types=1);

namespace Benzina\Tests;

use Benzina\Config;
use Benzina\Security\AppCheckVerifier;
use Benzina\Security\Authenticator;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\Attributes\DataProvider;

final class SecurityTest extends TestCase
{
    private const PROJECT = '123456789';
    private const APP_ANDROID = '1:123456789:android:abc';
    private const APP_IOS = '1:123456789:ios:def';

    /** @var array{private: string, public: string}|null */
    private static ?array $key = null;
    /** @var array{private: string, public: string}|null */
    private static ?array $otherKey = null;

    /** @return array{private: string, public: string} */
    private static function newKey(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $private);
        return ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
    }

    protected function setUp(): void
    {
        self::$key ??= self::newKey();
        self::$otherKey ??= self::newKey();
    }

    /** @param array<string, mixed> $overrides */
    private static function token(array $overrides = [], ?string $privateKey = null, ?string $kid = 'k1'): string
    {
        $now = time();
        $claims = array_merge([
            'sub' => self::APP_ANDROID,
            'aud' => ['projects/' . self::PROJECT, 'projects/benzina-app'],
            'iss' => 'https://firebaseappcheck.googleapis.com/' . self::PROJECT,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $overrides);
        return JWT::encode($claims, $privateKey ?? self::$key['private'], 'RS256', $kid);
    }

    /** @return \Slim\App<null> */
    private static function appCheckApp(): \Slim\App
    {
        $config = new Config();
        $verifier = new AppCheckVerifier(
            self::PROJECT,
            [self::APP_ANDROID, self::APP_IOS],
            static fn (): array => ['k1' => new Key(self::$key['public'], 'RS256')],
        );
        return self::app(config: $config, auth: new Authenticator($config, $verifier));
    }

    private static function statusFor(string $token): int
    {
        return self::get(self::appCheckApp(), '/v1/stations/nearby', self::CENTER, ['X-Firebase-AppCheck' => $token])[0];
    }

    public function testTokenAppCheckValido(): void
    {
        self::assertSame(200, self::statusFor(self::token()));
        self::assertSame(200, self::statusFor(self::token(['sub' => self::APP_IOS])));
    }

    /** @return iterable<string, array{\Closure(): string}> */
    public static function tokenNonValidi(): iterable
    {
        yield "firma di un'altra chiave" => [fn () => self::token([], self::$otherKey['private'])];
        yield 'scaduto' => [fn () => self::token(['exp' => time() - 10])];
        yield 'altro progetto' => [fn () => self::token(['aud' => ['projects/999']])];
        yield 'emittente' => [fn () => self::token(['iss' => 'https://evil.example/123456789'])];
        yield 'app non autorizzata' => [fn () => self::token(['sub' => '1:123456789:web:zzz'])];
        yield 'kid sconosciuto' => [fn () => self::token([], null, 'altro')];
        yield 'malformato' => [fn () => 'non-un-token'];
        yield 'senza firma' => [fn () => rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=')
            . '.' . rtrim(strtr(base64_encode(json_encode(['sub' => self::APP_ANDROID, 'exp' => time() + 60])), '+/', '-_'), '=') . '.'];
    }

    /** @param \Closure(): string $token */
    #[DataProvider('tokenNonValidi')]
    public function testTokenAppCheckRifiutato(\Closure $token): void
    {
        self::assertSame(401, self::statusFor($token()));
    }

    public function testSenzaConfigurazioneTuttoRifiutato(): void
    {
        $app = self::app(config: new Config());
        [$status] = self::get($app, '/v1/stations/nearby', self::CENTER, ['X-API-Key' => 'qualsiasi']);
        self::assertSame(401, $status);
    }

    public function testAuthDisattivataSoloSeEsplicito(): void
    {
        $app = self::app(config: new Config(authDisabled: true));
        [$status] = self::get($app, '/v1/stations/nearby', self::CENTER, [], checkSpec: false);
        self::assertSame(200, $status);
    }

    public function testConfigurazioneDaVariabiliDAmbiente(): void
    {
        $config = Config::fromEnv([
            'BENZINA_API_KEYS' => 'uno, due',
            'BENZINA_APPCHECK_APP_IDS' => self::APP_ANDROID,
            'BENZINA_AUTH_DISABLED' => 'false',
            'BENZINA_DB_DSN' => 'sqlite::memory:',
        ]);
        self::assertSame(['uno', 'due'], $config->apiKeys);
        self::assertSame([self::APP_ANDROID], $config->appCheckAppIds);
        self::assertFalse($config->authDisabled);
        self::assertSame('sqlite::memory:', $config->dbDsn);

        // I percorsi SQLite relativi partono dalla cartella del progetto.
        $default = Config::fromEnv([]);
        self::assertSame('sqlite:' . dirname(__DIR__) . '/var/benzina.db', $default->dbDsn);

        // Credenziali FCM: percorso relativo alla cartella del progetto, oppure il JSON.
        self::assertSame(
            dirname(__DIR__) . '/var/firebase.json',
            Config::fromEnv(['BENZINA_FCM_CREDENTIALS' => 'var/firebase.json'])->fcmCredentials,
        );
        self::assertSame(
            'E:\\sito\\var\\firebase.json',
            Config::fromEnv(['BENZINA_FCM_CREDENTIALS' => 'E:\\sito\\var\\firebase.json'])->fcmCredentials,
        );
        self::assertSame('{"type":"service_account"}', Config::fromEnv(['BENZINA_FCM_CREDENTIALS' => '{"type":"service_account"}'])->fcmCredentials);

        // I percorsi assoluti restano com'erano, anche quelli di Windows.
        foreach (['/srv/benzina.db', 'C:\\dati\\benzina.db', 'D:/dati/benzina.db', '\\\\nas\\benzina.db'] as $path) {
            self::assertSame('sqlite:' . $path, Config::fromEnv(['BENZINA_DB_DSN' => 'sqlite:' . $path])->dbDsn);
        }
    }
}
