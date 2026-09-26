<?php

declare(strict_types=1);

namespace Benzina\Security;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use stdClass;
use UnexpectedValueException;

/**
 * Verifica i token Firebase App Check (header X-Firebase-AppCheck).
 *
 * Il token è un JWT firmato da Firebase (RS256) che attesta che la richiesta
 * arriva dall'app originale su un dispositivo reale. Si controllano firma,
 * emittente, progetto, scadenza e, se configurato, l'ID dell'app.
 * https://firebase.google.com/docs/app-check/custom-resource-backend
 */
final class AppCheckVerifier
{
    /** @var \Closure(): array<string, Key> */
    private readonly \Closure $keys;

    /**
     * @param list<string> $appIds ID delle app Firebase ammesse (vuoto = tutte)
     * @param callable(): array<string, Key> $keys chiavi pubbliche per kid
     */
    public function __construct(
        private readonly string $projectNumber,
        private readonly array $appIds,
        callable $keys,
    ) {
        $this->keys = \Closure::fromCallable($keys);
    }

    /** @throws UnexpectedValueException se il token non è valido */
    public function verify(string $token): stdClass
    {
        $headers = new stdClass();
        try {
            $claims = JWT::decode($token, ($this->keys)(), $headers);
        } catch (\Throwable $e) {
            throw new UnexpectedValueException('token non valido: ' . $e->getMessage(), 0, $e);
        }
        if (($headers->alg ?? null) !== 'RS256' || ($headers->typ ?? null) !== 'JWT') {
            throw new UnexpectedValueException('intestazione del token non valida');
        }
        if (($claims->iss ?? null) !== 'https://firebaseappcheck.googleapis.com/' . $this->projectNumber) {
            throw new UnexpectedValueException('emittente non valido');
        }
        $audience = (array) ($claims->aud ?? []);
        if (!in_array('projects/' . $this->projectNumber, $audience, true)) {
            throw new UnexpectedValueException('progetto non valido');
        }
        if (!isset($claims->exp, $claims->sub)) {
            throw new UnexpectedValueException('claim obbligatori mancanti');
        }
        if ($this->appIds !== [] && !in_array($claims->sub, $this->appIds, true)) {
            throw new UnexpectedValueException('app non autorizzata');
        }
        return $claims;
    }
}
