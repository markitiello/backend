<?php

declare(strict_types=1);

namespace Benzina\Security;

use Benzina\Config;

/**
 * Accesso riservato all'app Benzina. Serve uno dei due:
 *
 * 1. token Firebase App Check (X-Firebase-AppCheck) — produzione;
 * 2. chiave statica (X-API-Key) — sviluppo, test, chiamate da server.
 *    Una chiave inserita nell'app si può estrarre: da sola non basta.
 *
 * Senza configurazione tutte le richieste vengono rifiutate.
 */
final class Authenticator
{
    public const APPCHECK_HEADER = 'X-Firebase-AppCheck';
    public const API_KEY_HEADER = 'X-API-Key';

    public function __construct(
        private readonly Config $config,
        private readonly ?AppCheckVerifier $verifier = null,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        $verifier = $config->appCheckProjectNumber === null ? null : new AppCheckVerifier(
            $config->appCheckProjectNumber,
            $config->appCheckAppIds,
            new FirebaseJwks(sys_get_temp_dir() . '/benzina-appcheck-jwks.json'),
        );
        return new self($config, $verifier);
    }

    public function allows(string $appCheckToken, string $apiKey): bool
    {
        if ($this->config->authDisabled) {
            return true;
        }
        if ($appCheckToken !== '' && $this->verifier !== null) {
            try {
                $this->verifier->verify($appCheckToken);
                return true;
            } catch (\UnexpectedValueException) {
                // prova con la chiave API
            }
        }
        if ($apiKey !== '') {
            foreach ($this->config->apiKeys as $key) {
                if (hash_equals($key, $apiKey)) {
                    return true;
                }
            }
        }
        return false;
    }
}
