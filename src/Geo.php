<?php

declare(strict_types=1);

namespace Benzina;

final class Geo
{
    public const EARTH_RADIUS_KM = 6371.0088;

    /** Distanza in linea d'aria (formula dell'haversine). */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = $p2 - $p1;
        $dl = deg2rad($lng2 - $lng1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return 2 * self::EARTH_RADIUS_KM * asin(sqrt($a));
    }

    /**
     * Rettangolo che contiene il cerchio, per filtrare con l'indice su (lat, lng)
     * prima di calcolare le distanze.
     *
     * @return array{float, float, float, float} min_lat, max_lat, min_lng, max_lng
     */
    public static function boundingBox(float $lat, float $lng, float $radiusKm): array
    {
        $dLat = rad2deg($radiusKm / self::EARTH_RADIUS_KM);
        $cosLat = max(cos(deg2rad($lat)), 1e-6);
        $dLng = rad2deg($radiusKm / (self::EARTH_RADIUS_KM * $cosLat));
        return [$lat - $dLat, $lat + $dLat, $lng - $dLng, $lng + $dLng];
    }
}
