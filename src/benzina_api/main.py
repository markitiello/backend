from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.openapi.utils import get_openapi
from fastapi.responses import JSONResponse
from sqlalchemy.engine import Engine
from starlette.exceptions import HTTPException as StarletteHTTPException

from . import __version__
from .api import public, router
from .config import Settings, get_settings
from .db import Database, create_schema, make_engine
from .google_places import GooglePlacesClient
from .security import AppCheckVerifier, Authenticator

DESCRIPTION = """
API dell'app **Benzina**: distributori vicini con il prezzo più basso, media
nazionale e andamento dei prezzi dei carburanti in Italia.

I prezzi sono gli open data del MIMIT (Osservaprezzi Carburanti), aggiornati una
volta al giorno; valutazioni e recensioni vengono da Google Places.

**Accesso**: tutte le rotte `/v1` richiedono l'header `X-Firebase-AppCheck`
(token Firebase App Check dell'app) oppure `X-API-Key`. Vedi `docs/API.md`.
"""

TITLES = {
    400: "Richiesta non valida",
    401: "Non autorizzato",
    404: "Non trovato",
    422: "Parametri non validi",
    502: "Servizio esterno non disponibile",
    503: "Servizio non disponibile",
}


def _problem(status: int, detail: str | None) -> JSONResponse:
    return JSONResponse(
        {
            "type": "about:blank",
            "title": TITLES.get(status, "Errore"),
            "status": status,
            "detail": detail,
        },
        status_code=status,
        media_type="application/problem+json",
    )


PROBLEM_REF = "#/components/schemas/Problem"


def _openapi_with_problem_json(app: FastAPI) -> dict:
    """Gli errori si documentano come application/problem+json con titoli italiani."""
    if app.openapi_schema:
        return app.openapi_schema
    spec = get_openapi(
        title=app.title,
        version=app.version,
        description=app.description,
        routes=app.routes,
        license_info=app.license_info,
    )
    for operations in spec["paths"].values():
        for op in operations.values():
            for code, response in op.get("responses", {}).items():
                content = response.get("content", {})
                if content.get("application/json", {}).get("schema", {}).get("$ref") == PROBLEM_REF:
                    response["content"] = {"application/problem+json": content["application/json"]}
                    response["description"] = TITLES.get(int(code), response["description"])
                elif code == "200":
                    response["description"] = "OK"
    app.openapi_schema = spec
    return spec


def create_app(
    settings: Settings | None = None,
    *,
    engine: Engine | None = None,
    appcheck_verifier: AppCheckVerifier | None = None,
    google_client: GooglePlacesClient | None = None,
) -> FastAPI:
    settings = settings or get_settings()
    engine = engine or make_engine(settings.database_url)
    create_schema(engine)

    app = FastAPI(
        title="Benzina API",
        version=__version__,
        description=DESCRIPTION,
        docs_url="/docs" if settings.docs_enabled else None,
        redoc_url="/redoc" if settings.docs_enabled else None,
        openapi_url="/openapi.json" if settings.docs_enabled else None,
        license_info={"name": "Dati prezzi: MIMIT, licenza IODL 2.0"},
    )
    app.state.db = Database(engine)
    app.state.authenticator = Authenticator(settings, appcheck_verifier)
    if google_client is None and settings.google_places_api_key:
        google_client = GooglePlacesClient(
            settings.google_places_api_key,
            cache_seconds=settings.google_cache_seconds,
            rematch_days=settings.google_rematch_days,
        )
    app.state.google = google_client

    @app.exception_handler(StarletteHTTPException)
    async def http_error(_: Request, exc: StarletteHTTPException) -> JSONResponse:
        return _problem(exc.status_code, str(exc.detail) if exc.detail else None)

    @app.exception_handler(RequestValidationError)
    async def validation_error(_: Request, exc: RequestValidationError) -> JSONResponse:
        details = "; ".join(
            f"{'.'.join(str(p) for p in e['loc'][1:])}: {e['msg']}" for e in exc.errors()
        )
        return _problem(422, details)

    app.include_router(public)
    app.include_router(router)
    app.openapi = lambda: _openapi_with_problem_json(app)
    return app


def app() -> FastAPI:
    """Factory per uvicorn: `uvicorn --factory benzina_api.main:app`."""
    return create_app()
