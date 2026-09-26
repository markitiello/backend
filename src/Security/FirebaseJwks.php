<?php

declare(strict_types=1);

namespace Benzina\Security;

use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use GuzzleHttp\Client;

/**
 * Chiavi pubbliche di Firebase App Check, con cache su file (6 ore, come
 * consigliato da Firebase) per non scaricarle a ogni richiesta.
 */
final class FirebaseJwks
{
    public const URL = 'https://firebaseappcheck.googleapis.com/v1/jwks';
    private const TTL = 6 * 3600;

    public function __construct(
        private readonly string $cacheFile,
        private readonly Client $http = new Client(['timeout' => 10]),
    ) {
    }

    /** @return array<string, Key> */
    public function __invoke(): array
    {
        $cached = is_file($this->cacheFile) && filemtime($this->cacheFile) > time() - self::TTL
            ? file_get_contents($this->cacheFile) : false;
        if ($cached === false) {
            $cached = (string) $this->http->get(self::URL)->getBody();
            @file_put_contents($this->cacheFile, $cached, LOCK_EX);
        }
        return JWK::parseKeySet(json_decode($cached, true, flags: JSON_THROW_ON_ERROR), 'RS256');
    }
}
