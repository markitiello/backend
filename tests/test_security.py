import time

import jwt
import pytest
from cryptography.hazmat.primitives.asymmetric import rsa
from fastapi.testclient import TestClient

from benzina_api.config import Settings
from benzina_api.main import create_app
from benzina_api.security import AppCheckVerifier
from tests.conftest import CENTER

PROJECT = "123456789"
APP_ANDROID = "1:123456789:android:abc"
APP_IOS = "1:123456789:ios:def"

_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
_other_key = rsa.generate_private_key(public_exponent=65537, key_size=2048)


def token(
    *,
    key=_key,
    sub=APP_ANDROID,
    aud=None,
    iss=f"https://firebaseappcheck.googleapis.com/{PROJECT}",
    exp_in=3600,
    alg="RS256",
):
    now = int(time.time())
    claims = {
        "sub": sub,
        "aud": aud or [f"projects/{PROJECT}", "projects/benzina-app"],
        "iss": iss,
        "iat": now,
        "exp": now + exp_in,
    }
    return jwt.encode(claims, key, algorithm=alg, headers={"kid": "k1", "typ": "JWT"})


@pytest.fixture
def appcheck_client(engine, imported):
    settings = Settings(database_url="sqlite://", _env_file=None)
    verifier = AppCheckVerifier(PROJECT, [APP_ANDROID, APP_IOS], lambda _: _key.public_key())
    app = create_app(settings, engine=engine, appcheck_verifier=verifier)
    return TestClient(app)


def _get(client, tok):
    return client.get("/v1/stations/nearby", params=CENTER, headers={"X-Firebase-AppCheck": tok})


def test_token_app_check_valido(appcheck_client):
    assert _get(appcheck_client, token()).status_code == 200
    assert _get(appcheck_client, token(sub=APP_IOS)).status_code == 200


@pytest.mark.parametrize(
    "bad",
    [
        pytest.param(lambda: token(key=_other_key), id="firma di un'altra chiave"),
        pytest.param(lambda: token(exp_in=-10), id="scaduto"),
        pytest.param(lambda: token(aud=["projects/999"]), id="altro progetto"),
        pytest.param(lambda: token(iss="https://evil.example/123456789"), id="emittente"),
        pytest.param(lambda: token(sub="1:123456789:web:zzz"), id="app non autorizzata"),
        pytest.param(lambda: "non-un-token", id="malformato"),
    ],
)
def test_token_app_check_rifiutato(appcheck_client, bad):
    assert _get(appcheck_client, bad()).status_code == 401


def test_token_senza_firma_rifiutato(appcheck_client):
    unsigned = jwt.encode(
        {"sub": APP_ANDROID, "aud": [f"projects/{PROJECT}"], "exp": time.time() + 60},
        key=None,
        algorithm="none",
    )
    assert _get(appcheck_client, unsigned).status_code == 401


def test_senza_configurazione_tutto_rifiutato(engine, imported):
    app = create_app(Settings(database_url="sqlite://", _env_file=None), engine=engine)
    r = TestClient(app).get("/v1/stations/nearby", params=CENTER, headers={"X-API-Key": ""})
    assert r.status_code == 401


def test_auth_disattivata_solo_se_esplicito(engine, imported):
    settings = Settings(database_url="sqlite://", auth_disabled=True, _env_file=None)
    app = create_app(settings, engine=engine)
    assert TestClient(app).get("/v1/stations/nearby", params=CENTER).status_code == 200


def test_liste_da_variabili_d_ambiente(monkeypatch):
    monkeypatch.setenv("BENZINA_API_KEYS", "uno, due")
    monkeypatch.setenv("BENZINA_APPCHECK_APP_IDS", APP_ANDROID)
    s = Settings(_env_file=None)
    assert s.api_keys == ["uno", "due"]
    assert s.appcheck_app_ids == [APP_ANDROID]
