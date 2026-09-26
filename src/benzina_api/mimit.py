"""Lettura dei file open data del MIMIT (Osservaprezzi Carburanti).

Formato (dal 10/02/2026): UTF-8, separatore `|`, prima riga
`Estrazione del AAAA-MM-GG`, seconda riga con le intestazioni.
"""

import logging
from collections.abc import Iterable, Iterator
from dataclasses import dataclass
from datetime import date, datetime

from .fuels import MIMIT_FUELS, Fuel

log = logging.getLogger(__name__)

SEPARATOR = "|"
STATION_COLUMNS = 10
PRICE_COLUMNS = 5


class MimitFormatError(ValueError):
    pass


@dataclass(frozen=True)
class StationRow:
    id: int
    operator: str
    brand: str
    kind: str
    name: str
    address: str
    city: str
    province: str
    lat: float
    lng: float


@dataclass(frozen=True)
class PriceRow:
    station_id: int
    fuel: Fuel
    is_self: bool
    price: float
    reported_at: datetime


def extraction_date(first_line: str) -> date:
    prefix = "Estrazione del "
    if not first_line.startswith(prefix):
        raise MimitFormatError(f"prima riga inattesa: {first_line!r}")
    return date.fromisoformat(first_line[len(prefix) :].strip())


def _split_body(lines: Iterable[str], expected_header: str) -> tuple[date, Iterator[str]]:
    it = iter(lines)
    try:
        day = extraction_date(next(it))
        header = next(it).strip()
    except StopIteration as e:
        raise MimitFormatError("file vuoto o troncato") from e
    if header.split(SEPARATOR)[0] != expected_header:
        raise MimitFormatError(f"intestazione inattesa: {header!r}")
    return day, (line.rstrip("\r\n") for line in it if line.strip())


def _clean(text: str) -> str:
    return " ".join(text.replace("\t", " ").split())


def parse_stations(lines: Iterable[str]) -> tuple[date, list[StationRow]]:
    """Anagrafica. Le righe senza coordinate valide vengono scartate."""
    day, body = _split_body(lines, "idImpianto")
    rows: list[StationRow] = []
    skipped = 0
    for line in body:
        parts = line.split(SEPARATOR)
        if len(parts) < STATION_COLUMNS:
            skipped += 1
            continue
        # Il nome dell'impianto può contenere "|": i primi 4 campi si leggono da
        # sinistra, gli ultimi 5 da destra e il resto è il nome.
        head, tail = parts[:4], parts[-5:]
        name = SEPARATOR.join(parts[4:-5])
        try:
            lat, lng = float(tail[3]), float(tail[4])
            station_id = int(head[0])
        except ValueError:
            skipped += 1
            continue
        if not (35 <= lat <= 48 and 6 <= lng <= 19):  # fuori dall'Italia
            skipped += 1
            continue
        rows.append(
            StationRow(
                id=station_id,
                operator=_clean(head[1]),
                brand=_clean(head[2]),
                kind=_clean(head[3]),
                name=_clean(name),
                address=_clean(tail[0]),
                city=_clean(tail[1]),
                province=_clean(tail[2]),
                lat=lat,
                lng=lng,
            )
        )
    if skipped:
        log.info("anagrafica: %d righe scartate", skipped)
    return day, rows


def parse_prices(lines: Iterable[str]) -> tuple[date, list[PriceRow]]:
    """Prezzi. Si tengono solo benzina, gasolio, GPL e metano "base"."""
    day, body = _split_body(lines, "idImpianto")
    rows: list[PriceRow] = []
    skipped = 0
    for line in body:
        parts = line.split(SEPARATOR)
        if len(parts) != PRICE_COLUMNS:
            skipped += 1
            continue
        fuel = MIMIT_FUELS.get(parts[1].strip())
        if fuel is None:
            continue
        try:
            price = float(parts[2])
            reported_at = datetime.strptime(parts[4].strip(), "%d/%m/%Y %H:%M:%S")
            row = PriceRow(
                station_id=int(parts[0]),
                fuel=fuel,
                is_self=parts[3].strip() == "1",
                price=price,
                reported_at=reported_at,
            )
        except ValueError:
            skipped += 1
            continue
        if not 0.3 <= price <= 5.0:  # errori di inserimento evidenti
            skipped += 1
            continue
        rows.append(row)
    if skipped:
        log.info("prezzi: %d righe scartate", skipped)
    return day, rows
