"""Accesso riservato all'app Benzina.

Due modi, controllati in quest'ordine:

1. **Firebase App Check** (produzione): l'app ottiene da Firebase un token
   firmato che attesta che la richiesta arriva dall'app originale su un
   dispositivo reale (Play Integrity su Android, App Attest su iOS) e lo invia
   nell'header `X-Firebase-AppCheck`. Il backend ne verifica firma, emittente,
   destinatario e scadenza.
2. **Chiave API** nell'header `X-API-Key`: per sviluppo, test e chiamate da
   server. Una chiave inserita nell'app si può estrarre, quindi non basta da
   sola a proteggere le API pubblicate.
"""

import hmac
from collections.abc import Callable
from typing import Annotated, Any

import jwt
from fastapi import Depends, HTTPException, Request, status
from fastapi.security import APIKeyHeader

from .config import Settings

APPCHECK_HEADER = "X-Firebase-AppCheck"
API_KEY_HEADER = "X-API-Key"
APPCHECK_JWKS_URL = "https://firebaseappcheck.googleapis.com/v1/jwks"

appcheck_scheme = APIKeyHeader(
    name=APPCHECK_HEADER,
    scheme_name="AppCheck",
    description="Token Firebase App Check dell'app (produzione).",
    auto_error=False,
)
api_key_scheme = APIKeyHeader(
    name=API_KEY_HEADER,
    scheme_name="ApiKey",
    description="Chiave statica per sviluppo e chiamate da server.",
    auto_error=False,
)

# Restituisce la chiave pubblica con cui verificare un token.
KeyResolver = Callable[[str], Any]


class AppCheckVerifier:
    def __init__(self, project_number: str, app_ids: list[str], key_resolver: KeyResolver):
        self.project_number = project_number
        self.app_ids = set(app_ids)
        self._key_for = key_resolver

    def verify(self, token: str) -> dict[str, Any]:
        """Restituisce i claim del token o solleva `jwt.InvalidTokenError`."""
        header = jwt.get_unverified_header(token)
        if header.get("alg") != "RS256" or header.get("typ") != "JWT":
            raise jwt.InvalidTokenError("intestazione del token non valida")
        claims = jwt.decode(
            token,
            key=self._key_for(token),
            algorithms=["RS256"],
            audience=f"projects/{self.project_number}",
            issuer=f"https://firebaseappcheck.googleapis.com/{self.project_number}",
            options={"require": ["exp", "iat", "sub", "aud", "iss"]},
        )
        if self.app_ids and claims["sub"] not in self.app_ids:
            raise jwt.InvalidTokenError("app non autorizzata")
        return claims


def firebase_key_resolver() -> KeyResolver:
    client = jwt.PyJWKClient(APPCHECK_JWKS_URL, cache_keys=True, lifespan=6 * 3600)
    return lambda token: client.get_signing_key_from_jwt(token).key


class Authenticator:
    def __init__(self, settings: Settings, verifier: AppCheckVerifier | None = None):
        self.settings = settings
        self.api_keys = [k.encode() for k in settings.api_keys]
        if verifier is None and settings.appcheck_project_number:
            verifier = AppCheckVerifier(
                settings.appcheck_project_number,
                settings.appcheck_app_ids,
                firebase_key_resolver(),
            )
        self.verifier = verifier

    def check(self, appcheck_token: str | None, api_key: str | None) -> None:
        if self.settings.auth_disabled:
            return
        if appcheck_token and self.verifier is not None:
            try:
                self.verifier.verify(appcheck_token)
                return
            except (jwt.InvalidTokenError, jwt.PyJWKClientError):
                pass
        if api_key and any(hmac.compare_digest(api_key.encode(), k) for k in self.api_keys):
            return
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Credenziali mancanti o non valide: serve un token App Check o una chiave API.",
        )


def require_client(
    request: Request,
    appcheck_token: Annotated[str | None, Depends(appcheck_scheme)],
    api_key: Annotated[str | None, Depends(api_key_scheme)],
) -> None:
    request.app.state.authenticator.check(appcheck_token, api_key)
