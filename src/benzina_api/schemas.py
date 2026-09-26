from datetime import date, datetime

from pydantic import BaseModel, ConfigDict, Field

from .fuels import Fuel, Mode


class Problem(BaseModel):
    """Errore nel formato RFC 9457 (application/problem+json)."""

    type: str = "about:blank"
    title: str = Field(examples=["Non autorizzato"])
    status: int = Field(examples=[401])
    detail: str | None = Field(default=None, examples=["Token App Check mancante o non valido."])


class Price(BaseModel):
    fuel: Fuel
    mode: Mode = Field(description="`self` o `servito`.")
    price: float = Field(description="€/l (€/kg per il metano).", examples=[1.739])
    reported_at: datetime = Field(description="Quando il gestore ha comunicato il prezzo.")


class StationSummary(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: int = Field(description="`idImpianto` del MIMIT.", examples=[3464])
    brand: str = Field(examples=["Agip Eni"])
    name: str = Field(examples=["19829 AGRIGENTO"])
    address: str = Field(examples=["SS.189 KM. 64+649"])
    city: str = Field(examples=["AGRIGENTO"])
    province: str = Field(examples=["AG"])
    lat: float = Field(examples=[37.333935])
    lng: float = Field(examples=[13.595533])


class Station(StationSummary):
    operator: str = Field(description="Gestore.")
    kind: str = Field(description="`Stradale` o `Autostradale`.")
    prices: list[Price] = Field(description="Prezzi dell'ultimo aggiornamento.")


class Offer(BaseModel):
    station: StationSummary
    price: float = Field(examples=[1.739])
    mode: Mode
    reported_at: datetime
    distance_km: float = Field(examples=[1.2])


class NearbyResponse(BaseModel):
    fuel: Fuel
    mode: Mode = Field(description="Modalità effettiva: `any` per GPL e metano.")
    data_date: date | None = Field(description="Giorno dell'ultimo aggiornamento MIMIT.")
    national_average: float | None = Field(description="Media nazionale dello stesso giorno.")
    offers: list[Offer] = Field(description="Dal più economico; a parità di prezzo il più vicino.")


class StationList(BaseModel):
    stations: list[Station]


class TrendPoint(BaseModel):
    day: date
    price: float


class Trend(BaseModel):
    fuel: Fuel
    mode: Mode
    points: list[TrendPoint] = Field(description="Un punto per giorno, dal più vecchio.")


class Review(BaseModel):
    author: str
    author_uri: str | None = Field(description="Profilo Google dell'autore (da mostrare).")
    rating: int = Field(ge=1, le=5)
    relative_time: str = Field(examples=["2 settimane fa"])
    text: str


class GoogleRating(BaseModel):
    place_id: str
    rating: float | None = Field(examples=[4.3])
    rating_count: int = Field(examples=[212])
    maps_url: str | None = Field(description="Pagina del luogo su Google Maps.")
    reviews: list[Review] = Field(description="Al massimo 5, scelte da Google.")
    attribution: str = Field(
        default="Valutazioni e recensioni fornite da Google",
        description="Testo di attribuzione obbligatorio da mostrare nell'app.",
    )


class Health(BaseModel):
    status: str = "ok"
    data_date: date | None
