<?php

declare(strict_types=1);

namespace Benzina\Http;

/** Eccezione trasformata in una risposta problem+json dal gestore degli errori. */
final class HttpProblem extends \RuntimeException
{
    public function __construct(public readonly int $status, string $detail)
    {
        parent::__construct($detail);
    }
}
