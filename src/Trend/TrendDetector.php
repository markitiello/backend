<?php

declare(strict_types=1);

namespace Benzina\Trend;

/**
 * Riconosce l'inizio di una tendenza nella media nazionale giornaliera.
 *
 * Una tendenza inizia il giorno in cui la media sale (o scende) per $minDays
 * giorni di fila e la variazione complessiva è almeno $minChange. Si segnala
 * solo quel giorno, non i successivi della stessa serie: una notifica per
 * tendenza, non una al giorno.
 */
final class TrendDetector
{
    // Variazioni più piccole (in €) contano come prezzo invariato.
    public const NOISE = 0.0005;

    public function __construct(
        private readonly int $minDays = 3,
        private readonly float $minChange = 0.005,
    ) {
    }

    /**
     * @param list<float> $prices medie giornaliere dalla più vecchia; l'ultima è oggi
     * @return array{direction: 'up'|'down', days: int, change: float}|null
     */
    public function detect(array $prices): ?array
    {
        $n = count($prices);
        if ($n < $this->minDays + 1) {
            return null;
        }
        $direction = self::move($prices[$n - 2], $prices[$n - 1]);
        if ($direction === null) {
            return null;
        }
        $days = 0;
        for ($i = $n - 1; $i > 0 && self::move($prices[$i - 1], $prices[$i]) === $direction; $i--) {
            $days++;
        }
        // Esattamente $minDays: la tendenza inizia oggi (se fosse più lunga
        // sarebbe già stata segnalata nei giorni scorsi).
        if ($days !== $this->minDays) {
            return null;
        }
        $start = $prices[$n - 1 - $days];
        $change = $prices[$n - 1] / $start - 1;
        if (abs($change) < $this->minChange) {
            return null;
        }
        return ['direction' => $direction, 'days' => $days, 'change' => $change];
    }

    /** @return 'up'|'down'|null */
    private static function move(float $from, float $to): ?string
    {
        if (abs($to - $from) < self::NOISE) {
            return null;
        }
        return $to > $from ? 'up' : 'down';
    }
}
