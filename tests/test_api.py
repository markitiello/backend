from datetime import date

import pytest
from fastapi.testclient import TestClient

from tests.conftest import CENTER


def test_health_non_richiede_credenziali(client):
    r = TestClient(client.app).get("/health")
    assert r.status_code == 200
    assert r.json() == {"status": "ok", "data_date": "2026-09-25"}


def test_senza_credenziali_401(client):
    r = TestClient(client.app).get("/v1/stations/nearby", params=CENTER)
    assert r.status_code == 401
    assert r.headers["content-type"] == "application/problem+json"
    assert r.json()["status"] == 401


def test_chiave_sbagliata_401(client):
    r = client.get("/v1/stations/nearby", params=CENTER, headers={"X-API-Key": "sbagliata"})
    assert r.status_code == 401


def test_vicini_dal_piu_economico(client):
    r = client.get("/v1/stations/nearby", params={**CENTER, "radius_km": 5})
    assert r.status_code == 200
    body = r.json()
    assert body["data_date"] == "2026-09-25"
    assert body["mode"] == "self"
    assert [o["station"]["id"] for o in body["offers"]] == [1001, 1002, 1003, 1004]
    assert [o["price"] for o in body["offers"]] == [1.729, 1.759, 1.779, 1.788]
    assert all(o["distance_km"] <= 5 for o in body["offers"])
    assert body["national_average"] == pytest.approx((1.729 + 1.759 + 1.779 + 1.701) / 4, abs=1e-4)


def test_vicini_raggio_e_limite(client):
    r = client.get("/v1/stations/nearby", params={**CENTER, "radius_km": 1, "limit": 1})
    offers = r.json()["offers"]
    assert len(offers) == 1
    assert offers[0]["station"]["id"] == 1001


def test_vicini_gpl_ignora_self_servito(client):
    r = client.get("/v1/stations/nearby", params={**CENTER, "fuel": "gpl", "mode": "servito"})
    body = r.json()
    assert body["mode"] == "any"
    assert [(o["station"]["id"], o["mode"]) for o in body["offers"]] == [
        (1003, "self"),
        (1001, "servito"),
    ]


def test_dettaglio_distributore(client):
    r = client.get("/v1/stations/1001")
    assert r.status_code == 200
    body = r.json()
    assert body["brand"] == "Q8"
    assert {(p["fuel"], p["mode"]) for p in body["prices"]} == {
        ("benzina", "self"),
        ("benzina", "servito"),
        ("diesel", "self"),
        ("gpl", "servito"),
    }


def test_distributore_inesistente_404(client):
    r = client.get("/v1/stations/424242")
    assert r.status_code == 404
    assert r.json()["title"] == "Non trovato"


def test_piu_distributori_per_id(client):
    r = client.get("/v1/stations", params={"ids": "1002,424242,1001,1002"})
    assert [s["id"] for s in r.json()["stations"]] == [1002, 1001]


@pytest.mark.parametrize(
    "params",
    [
        {**CENTER, "radius_km": 0},
        {**CENTER, "radius_km": 100},
        {**CENTER, "fuel": "kerosene"},
        {"lat": 200, "lng": 9},
    ],
)
def test_parametri_non_validi_422(client, params):
    r = client.get("/v1/stations/nearby", params=params)
    assert r.status_code == 422
    assert r.headers["content-type"] == "application/problem+json"


def test_ids_non_validi_422(client):
    assert client.get("/v1/stations", params={"ids": "1,a"}).status_code == 422
    too_many = ",".join(str(i) for i in range(51))
    assert client.get("/v1/stations", params={"ids": too_many}).status_code == 422


def test_andamento_nazionale(client):
    r = client.get("/v1/trends/national", params={"fuel": "benzina", "days": 30})
    points = r.json()["points"]
    assert [p["day"] for p in points] == ["2026-09-24", "2026-09-25"]


def test_andamento_distributore_ricostruito_dalle_variazioni(client):
    r = client.get("/v1/stations/1001/trend", params={"days": 3})
    points = r.json()["points"]
    # Il 23 vale 1,739; il 24 sera il gestore comunica 1,729.
    assert [(p["day"], p["price"]) for p in points] == [
        ("2026-09-23", 1.739),
        ("2026-09-24", 1.729),
        ("2026-09-25", 1.729),
    ]


def test_andamento_distributore_inesistente_404(client):
    assert client.get("/v1/stations/424242/trend").status_code == 404


def test_andamento_zona(client):
    r = client.get("/v1/trends/area", params={**CENTER, "radius_km": 5, "days": 3})
    points = {p["day"]: p["price"] for p in r.json()["points"]}
    assert points[str(date(2026, 9, 23))] == pytest.approx(
        (1.739 + 1.759 + 1.772 + 1.788) / 4, abs=1e-3
    )
    assert points[str(date(2026, 9, 25))] == pytest.approx(
        (1.729 + 1.759 + 1.779 + 1.788) / 4, abs=1e-3
    )
