<?php

declare(strict_types=1);

namespace Benzina;

enum Mode: string
{
    case Self = 'self';
    case Servito = 'servito';
    // GPL e metano: self e servito insieme.
    case Any = 'any';

    /** Modalità con cui si confrontano i prezzi di $fuel. */
    public static function effective(Fuel $fuel, self $mode): self
    {
        if (!$fuel->hasServiceModes()) {
            return self::Any;
        }
        return $mode === self::Any ? self::Self : $mode;
    }

    public static function fromIsSelf(bool|int|string $isSelf): self
    {
        return (int) $isSelf === 1 ? self::Self : self::Servito;
    }
}
