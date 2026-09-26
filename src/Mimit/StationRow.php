<?php

declare(strict_types=1);

namespace Benzina\Mimit;

final class StationRow
{
    public function __construct(
        public readonly int $id,
        public readonly string $operator,
        public readonly string $brand,
        public readonly string $kind,
        public readonly string $name,
        public readonly string $address,
        public readonly string $city,
        public readonly string $province,
        public readonly float $lat,
        public readonly float $lng,
    ) {
    }
}
