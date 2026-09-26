<?php

declare(strict_types=1);

namespace Benzina\Mimit;

use Benzina\Fuel;

final class PriceRow
{
    public function __construct(
        public readonly int $stationId,
        public readonly Fuel $fuel,
        public readonly bool $isSelf,
        public readonly float $price,
        // 'Y-m-d H:i:s', ora italiana come nel file MIMIT.
        public readonly string $reportedAt,
    ) {
    }
}
