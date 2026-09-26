<?php

declare(strict_types=1);

namespace Benzina;

enum Fuel: string
{
    case Benzina = 'benzina';
    case Diesel = 'diesel';
    case Gpl = 'gpl';
    case Metano = 'metano';

    /** GPL e metano si confrontano senza distinguere self e servito. */
    public function hasServiceModes(): bool
    {
        return $this === self::Benzina || $this === self::Diesel;
    }

    /**
     * Valori di `descCarburante` del MIMIT considerati. Le varianti "premium"
     * (Blue Diesel, HVO, Benzina speciale, ...) sono escluse dai confronti.
     */
    public static function fromMimit(string $description): ?self
    {
        return match ($description) {
            'Benzina' => self::Benzina,
            'Gasolio' => self::Diesel,
            'GPL' => self::Gpl,
            'Metano' => self::Metano,
            default => null,
        };
    }
}
