<?php

declare(strict_types=1);

namespace Benzina\Trend;

use Benzina\Fuel;
use Benzina\Mode;

/**
 * Topic FCM delle notifiche di tendenza. L'app si iscrive al topic del
 * carburante scelto nelle impostazioni (Flutter: lib/push/push_topics.dart):
 * il server non conserva token né dati dei dispositivi.
 */
final class Topics
{
    public static function forTrend(Fuel $fuel, Mode $mode): string
    {
        return $fuel->hasServiceModes() ? "trend_{$fuel->value}_{$mode->value}" : "trend_{$fuel->value}";
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [
            'trend_benzina_self', 'trend_benzina_servito',
            'trend_diesel_self', 'trend_diesel_servito',
            'trend_gpl', 'trend_metano',
        ];
    }
}
