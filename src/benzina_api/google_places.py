"""Valutazioni e recensioni da Google Places API (New).

- Il distributore MIMIT si abbina al luogo Google con una ricerca testuale
  vicino alle sue coordinate; si salva solo il `place_id`.
- Valutazione e recensioni si chiedono a Google a ogni richiesta (eventuale
  cache breve in memoria, disattivata di default).
- La chiave Google resta sul server: l'app non la vede mai.
"""

import time
from datetime import datetime, timedelta

import httpx
from sqlalchemy.orm import Session

from . import schemas
from .db import GooglePlace, Station
from .geo import distance_km

PLACES_URL = "https://places.googleapis.com/v1"
# Distanza massima tra il distributore MIMIT e il luogo Google.
MATCH_RADIUS_M = 250


class GooglePlacesError(Exception):
    pass


class GooglePlacesClient:
    def __init__(
        self,
        api_key: str,
        http: httpx.Client | None = None,
        cache_seconds: int = 0,
        rematch_days: int = 30,
    ):
        self.api_key = api_key
        self.http = http or httpx.Client(timeout=10)
        self.cache_seconds = cache_seconds
        self.rematch_days = rematch_days
        self._cache: dict[str, tuple[float, schemas.GoogleRating]] = {}

    def _headers(self, field_mask: str) -> dict[str, str]:
        return {"X-Goog-Api-Key": self.api_key, "X-Goog-FieldMask": field_mask}

    def find_place_id(self, station: Station) -> str | None:
        response = self.http.post(
            f"{PLACES_URL}/places:searchText",
            headers=self._headers("places.id,places.location"),
            json={
                "textQuery": f"{station.brand} {station.address} {station.city}",
                "includedType": "gas_station",
                "languageCode": "it",
                "regionCode": "IT",
                "maxResultCount": 5,
                "locationBias": {
                    "circle": {
                        "center": {"latitude": station.lat, "longitude": station.lng},
                        "radius": float(MATCH_RADIUS_M),
                    }
                },
            },
        )
        if response.status_code != 200:
            raise GooglePlacesError(f"searchText: HTTP {response.status_code}")
        best: tuple[float, str] | None = None
        for place in response.json().get("places", []):
            loc = place.get("location") or {}
            if "latitude" not in loc:
                continue
            d = distance_km(station.lat, station.lng, loc["latitude"], loc["longitude"]) * 1000
            if d <= MATCH_RADIUS_M and (best is None or d < best[0]):
                best = (d, place["id"])
        return best[1] if best else None

    def place_id_for(self, session: Session, station: Station) -> str | None:
        """place_id salvato, oppure cercato e salvato ora."""
        match = session.get(GooglePlace, station.id)
        now = datetime.now()
        if match is not None and (
            match.place_id is not None or now - match.checked_at < timedelta(days=self.rematch_days)
        ):
            return match.place_id
        place_id = self.find_place_id(station)
        session.merge(GooglePlace(station_id=station.id, place_id=place_id, checked_at=now))
        session.commit()
        return place_id

    def details(self, place_id: str) -> schemas.GoogleRating:
        cached = self._cache.get(place_id)
        if cached and cached[0] > time.monotonic():
            return cached[1]
        response = self.http.get(
            f"{PLACES_URL}/places/{place_id}",
            params={"languageCode": "it", "regionCode": "IT"},
            headers=self._headers("id,rating,userRatingCount,googleMapsUri,reviews"),
        )
        if response.status_code != 200:
            raise GooglePlacesError(f"details: HTTP {response.status_code}")
        data = response.json()
        rating = schemas.GoogleRating(
            place_id=place_id,
            rating=data.get("rating"),
            rating_count=data.get("userRatingCount", 0),
            maps_url=data.get("googleMapsUri"),
            reviews=[
                schemas.Review(
                    author=(r.get("authorAttribution") or {}).get("displayName", "Utente Google"),
                    author_uri=(r.get("authorAttribution") or {}).get("uri"),
                    rating=r.get("rating", 0) or 1,
                    relative_time=r.get("relativePublishTimeDescription", ""),
                    text=((r.get("text") or r.get("originalText") or {}).get("text", "")),
                )
                for r in data.get("reviews", [])
            ],
        )
        if self.cache_seconds > 0:
            self._cache[place_id] = (time.monotonic() + self.cache_seconds, rating)
        return rating
