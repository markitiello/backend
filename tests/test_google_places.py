import json

import httpx
import pytest
from fastapi.testclient import TestClient

from benzina_api.db import GooglePlace
from benzina_api.google_places import GooglePlacesClient
from benzina_api.main import create_app
from tests.conftest import API_KEY

# Distributore 1001 delle fixture: 45.4841, 9.2310
NEAR = {"latitude": 45.4842, "longitude": 9.2311}  # ~15 m
FAR = {"latitude": 45.4900, "longitude": 9.2400}  # ~1 km

DETAILS = {
    "id": "place-q8",
    "rating": 4.3,
    "userRatingCount": 212,
    "googleMapsUri": "https://maps.google.com/?cid=1",
    "reviews": [
        {
            "rating": 5,
            "relativePublishTimeDescription": "2 settimane fa",
            "text": {"text": "Personale gentile.", "languageCode": "it"},
            "authorAttribution": {"displayName": "Mario R.", "uri": "https://maps.google.com/u/1"},
        },
        {"rating": 2, "relativePublishTimeDescription": "1 mese fa"},
    ],
}


class FakeGoogle:
    def __init__(self, places=None, details_status=200):
        self.places = (
            places
            if places is not None
            else [
                {"id": "place-lontano", "location": FAR},
                {"id": "place-q8", "location": NEAR},
            ]
        )
        self.details_status = details_status
        self.calls: list[str] = []

    def __call__(self, request: httpx.Request) -> httpx.Response:
        assert request.headers["X-Goog-Api-Key"] == "google-key"
        self.calls.append(request.url.path)
        if request.url.path.endswith(":searchText"):
            body = json.loads(request.content)
            assert body["includedType"] == "gas_station"
            return httpx.Response(200, json={"places": self.places})
        return httpx.Response(self.details_status, json=DETAILS)


def make_client(engine, settings, fake, cache_seconds=0):
    google = GooglePlacesClient(
        "google-key",
        http=httpx.Client(transport=httpx.MockTransport(fake)),
        cache_seconds=cache_seconds,
    )
    app = create_app(settings, engine=engine, google_client=google)
    return TestClient(app, headers={"X-API-Key": API_KEY})


def test_abbina_il_luogo_piu_vicino_e_restituisce_le_recensioni(engine, settings, imported):
    fake = FakeGoogle()
    client = make_client(engine, settings, fake)

    r = client.get("/v1/stations/1001/google-rating")
    assert r.status_code == 200
    body = r.json()
    assert body["place_id"] == "place-q8"
    assert (body["rating"], body["rating_count"]) == (4.3, 212)
    assert body["reviews"][0] == {
        "author": "Mario R.",
        "author_uri": "https://maps.google.com/u/1",
        "rating": 5,
        "relative_time": "2 settimane fa",
        "text": "Personale gentile.",
    }
    assert body["reviews"][1]["author"] == "Utente Google"
    assert body["attribution"] == "Valutazioni e recensioni fornite da Google"

    # La seconda volta il place_id è già salvato: niente nuova ricerca.
    client.get("/v1/stations/1001/google-rating")
    assert [c.endswith(":searchText") for c in fake.calls] == [True, False, False]
    with imported.sessions() as s:
        assert s.get(GooglePlace, 1001).place_id == "place-q8"


def test_nessun_luogo_vicino_404_e_non_riprova_subito(engine, settings, imported):
    fake = FakeGoogle(places=[{"id": "place-lontano", "location": FAR}])
    client = make_client(engine, settings, fake)
    assert client.get("/v1/stations/1001/google-rating").status_code == 404
    assert client.get("/v1/stations/1001/google-rating").status_code == 404
    assert len(fake.calls) == 1


def test_errore_google_502(engine, settings, imported):
    client = make_client(engine, settings, FakeGoogle(details_status=500))
    assert client.get("/v1/stations/1001/google-rating").status_code == 502


def test_cache_facoltativa(engine, settings, imported):
    fake = FakeGoogle()
    client = make_client(engine, settings, fake, cache_seconds=60)
    client.get("/v1/stations/1001/google-rating")
    client.get("/v1/stations/1001/google-rating")
    assert len(fake.calls) == 2  # una ricerca + un dettaglio


def test_google_non_configurato_503(client):
    assert client.get("/v1/stations/1001/google-rating").status_code == 503


@pytest.mark.parametrize("station", [424242])
def test_distributore_inesistente_404(engine, settings, imported, station):
    client = make_client(engine, settings, FakeGoogle())
    assert client.get(f"/v1/stations/{station}/google-rating").status_code == 404
