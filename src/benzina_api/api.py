from collections.abc import Iterator
from typing import Annotated

from fastapi import APIRouter, Depends, HTTPException, Path, Query, Request, status
from sqlalchemy.orm import Session

from . import schemas, services
from .db import Station
from .fuels import Fuel, Mode, effective_mode
from .google_places import GooglePlacesError
from .security import require_client

# Nello schema OpenAPI diventano application/problem+json (vedi main.py).
PROBLEM = {"model": schemas.Problem}
ERRORS = {401: PROBLEM, 422: PROBLEM}


def get_session(request: Request) -> Iterator[Session]:
    with request.app.state.db.sessions() as session:
        yield session


SessionDep = Annotated[Session, Depends(get_session)]

Lat = Annotated[float, Query(ge=-90, le=90, description="Latitudine (WGS84).", examples=[45.4781])]
Lng = Annotated[float, Query(ge=-180, le=180, description="Longitudine (WGS84).", examples=[9.227])]
RadiusKm = Annotated[float, Query(gt=0, le=50, description="Raggio di ricerca in km.")]
FuelQ = Annotated[Fuel, Query(description="Carburante.")]
ModeQ = Annotated[
    Mode,
    Query(description="`self` o `servito`. Ignorato per GPL e metano (si usa `any`)."),
]
Days = Annotated[int, Query(ge=2, le=366, description="Numero di giorni, oggi incluso.")]
StationId = Annotated[int, Path(description="`idImpianto` del MIMIT.", examples=[3464])]

public = APIRouter(tags=["Servizio"])
router = APIRouter(prefix="/v1", dependencies=[Depends(require_client)], responses=ERRORS)


@public.get("/health", summary="Stato del servizio", response_model=schemas.Health)
def health(session: SessionDep) -> schemas.Health:
    """Non richiede autenticazione. `data_date` è il giorno dell'ultimo import MIMIT."""
    return schemas.Health(data_date=services.latest_import_day(session))


@router.get(
    "/stations/nearby",
    tags=["Distributori"],
    summary="Distributori vicini, dal più economico",
    response_model=schemas.NearbyResponse,
)
def stations_nearby(
    session: SessionDep,
    lat: Lat,
    lng: Lng,
    radius_km: RadiusKm = 5,
    fuel: FuelQ = Fuel.benzina,
    mode: ModeQ = Mode.self_,
    limit: Annotated[int, Query(ge=1, le=200, description="Numero massimo di risultati.")] = 50,
) -> schemas.NearbyResponse:
    """Distributori entro `radius_km` che vendono `fuel`, con il prezzo dell'ultimo
    aggiornamento MIMIT e la media nazionale per il confronto."""
    return services.nearby(session, lat, lng, radius_km, fuel, effective_mode(fuel, mode), limit)


@router.get(
    "/stations",
    tags=["Distributori"],
    summary="Più distributori per id",
    response_model=schemas.StationList,
)
def stations_list(
    session: SessionDep,
    ids: Annotated[
        str,
        Query(
            pattern=r"^\d+(,\d+){0,49}$",
            description="Fino a 50 id separati da virgola (es. i preferiti).",
            examples=["3464,3468"],
        ),
    ],
) -> schemas.StationList:
    """Gli id che non esistono (es. distributori chiusi) vengono omessi."""
    wanted = list(dict.fromkeys(int(i) for i in ids.split(",")))
    return schemas.StationList(stations=services.stations_by_id(session, wanted))


@router.get(
    "/stations/{station_id}",
    tags=["Distributori"],
    summary="Dettaglio di un distributore",
    response_model=schemas.Station,
    responses={404: PROBLEM},
)
def station_detail(session: SessionDep, station_id: StationId) -> schemas.Station:
    found = services.stations_by_id(session, [station_id])
    if not found:
        raise HTTPException(status.HTTP_404_NOT_FOUND, "Distributore non trovato.")
    return found[0]


@router.get(
    "/stations/{station_id}/trend",
    tags=["Andamento prezzi"],
    summary="Storico prezzi di un distributore",
    response_model=schemas.Trend,
    responses={404: PROBLEM},
)
def station_trend(
    session: SessionDep,
    station_id: StationId,
    fuel: FuelQ = Fuel.benzina,
    mode: ModeQ = Mode.self_,
    days: Days = 30,
) -> schemas.Trend:
    """Prezzo a fine giornata. Mancano i giorni prima del primo prezzo noto."""
    if session.get(Station, station_id) is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, "Distributore non trovato.")
    return services.station_trend(session, station_id, fuel, effective_mode(fuel, mode), days)


@router.get(
    "/stations/{station_id}/google-rating",
    tags=["Distributori"],
    summary="Valutazione e recensioni Google",
    response_model=schemas.GoogleRating,
    responses={404: PROBLEM, 502: PROBLEM, 503: PROBLEM},
)
def station_google_rating(
    request: Request, session: SessionDep, station_id: StationId
) -> schemas.GoogleRating:
    """Dati di Google Places. Nell'app vanno mostrati con il testo di `attribution`
    e con il nome (e link) dell'autore di ogni recensione.

    - 404: distributore inesistente o nessun luogo Google abbinato.
    - 503: integrazione Google non configurata sul server.
    """
    google = request.app.state.google
    if google is None:
        raise HTTPException(status.HTTP_503_SERVICE_UNAVAILABLE, "Recensioni Google non attive.")
    station = session.get(Station, station_id)
    if station is None:
        raise HTTPException(status.HTTP_404_NOT_FOUND, "Distributore non trovato.")
    try:
        place_id = google.place_id_for(session, station)
        if place_id is None:
            raise HTTPException(status.HTTP_404_NOT_FOUND, "Nessun luogo Google abbinato.")
        return google.details(place_id)
    except GooglePlacesError as e:
        raise HTTPException(status.HTTP_502_BAD_GATEWAY, "Google Places non disponibile.") from e


@router.get(
    "/trends/national",
    tags=["Andamento prezzi"],
    summary="Media nazionale giornaliera",
    response_model=schemas.Trend,
)
def trend_national(
    session: SessionDep,
    fuel: FuelQ = Fuel.benzina,
    mode: ModeQ = Mode.self_,
    days: Days = 30,
) -> schemas.Trend:
    """Media dei prezzi di tutti i distributori d'Italia, un punto per giorno di
    import. Esclusi i prezzi comunicati da oltre 30 giorni e i valori anomali."""
    return services.national_trend(session, fuel, effective_mode(fuel, mode), days)


@router.get(
    "/trends/area",
    tags=["Andamento prezzi"],
    summary="Media giornaliera della zona",
    response_model=schemas.Trend,
)
def trend_area(
    session: SessionDep,
    lat: Lat,
    lng: Lng,
    radius_km: RadiusKm = 5,
    fuel: FuelQ = Fuel.benzina,
    mode: ModeQ = Mode.self_,
    days: Days = 30,
) -> schemas.Trend:
    """Media dei distributori entro `radius_km`, per confrontarla con quella nazionale."""
    return services.area_trend(session, lat, lng, radius_km, fuel, effective_mode(fuel, mode), days)
