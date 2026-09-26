import math

EARTH_RADIUS_KM = 6371.0088


def distance_km(lat1: float, lng1: float, lat2: float, lng2: float) -> float:
    """Distanza in linea d'aria (formula dell'haversine)."""
    p1, p2 = math.radians(lat1), math.radians(lat2)
    dp = p2 - p1
    dl = math.radians(lng2 - lng1)
    a = math.sin(dp / 2) ** 2 + math.cos(p1) * math.cos(p2) * math.sin(dl / 2) ** 2
    return 2 * EARTH_RADIUS_KM * math.asin(math.sqrt(a))


def bounding_box(lat: float, lng: float, radius_km: float) -> tuple[float, float, float, float]:
    """Rettangolo (min_lat, max_lat, min_lng, max_lng) che contiene il cerchio.

    Serve a filtrare con l'indice su (lat, lng) prima di calcolare le distanze.
    """
    dlat = math.degrees(radius_km / EARTH_RADIUS_KM)
    cos_lat = max(math.cos(math.radians(lat)), 1e-6)
    dlng = math.degrees(radius_km / (EARTH_RADIUS_KM * cos_lat))
    return lat - dlat, lat + dlat, lng - dlng, lng + dlng
