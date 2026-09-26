<?php

declare(strict_types=1);

namespace Benzina\Http;

use Psr\Http\Message\ResponseInterface;

/** Errori nel formato RFC 9457 (application/problem+json). */
final class Problem
{
    public const TITLES = [
        400 => 'Richiesta non valida',
        401 => 'Non autorizzato',
        404 => 'Non trovato',
        405 => 'Metodo non consentito',
        422 => 'Parametri non validi',
        500 => 'Errore interno',
        502 => 'Servizio esterno non disponibile',
        503 => 'Servizio non disponibile',
    ];

    public static function write(ResponseInterface $response, int $status, ?string $detail = null): ResponseInterface
    {
        $response->getBody()->write(json_encode([
            'type' => 'about:blank',
            'title' => self::TITLES[$status] ?? 'Errore',
            'status' => $status,
            'detail' => $detail,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/problem+json');
    }
}
