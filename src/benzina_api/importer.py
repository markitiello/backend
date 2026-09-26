"""Import giornaliero degli open data MIMIT.

Uso: `benzina-import` (scarica i file) oppure
`benzina-import --stations-file a.csv --prices-file p.csv`.
Va lanciato una volta al giorno dopo le 8 (vedi .github/workflows/import.yml).
"""

import argparse
import logging
import statistics
from collections import defaultdict
from collections.abc import Iterable
from dataclasses import dataclass
from datetime import date, datetime, timedelta

import httpx
from sqlalchemy import delete, insert, select, update
from sqlalchemy.dialects import postgresql, sqlite
from sqlalchemy.orm import Session

from .config import Settings, get_settings
from .db import (
    CurrentPrice,
    Database,
    ImportRun,
    NationalAverage,
    PriceChange,
    Station,
    create_schema,
    make_engine,
)
from .fuels import Fuel, Mode
from .mimit import PriceRow, parse_prices, parse_stations

log = logging.getLogger(__name__)

BATCH = 5000


@dataclass(frozen=True)
class ImportResult:
    day: date
    stations: int
    prices: int
    new_changes: int


def _batches(rows: list[dict], size: int = BATCH) -> Iterable[list[dict]]:
    for i in range(0, len(rows), size):
        yield rows[i : i + size]


def _insert_ignore(session: Session, table, rows: list[dict]) -> int:
    """INSERT che ignora le righe già presenti (PostgreSQL e SQLite)."""
    dialect = session.bind.dialect.name
    module = postgresql if dialect == "postgresql" else sqlite
    inserted = 0
    for chunk in _batches(rows):
        stmt = (
            module.insert(table)
            .values(chunk)
            .on_conflict_do_nothing()
            .returning(*table.primary_key.columns)
        )
        # rowcount non è affidabile con psycopg: si contano le righe restituite.
        inserted += len(session.execute(stmt).all())
    return inserted


def _dedupe_latest(prices: list[PriceRow]) -> list[PriceRow]:
    latest: dict[tuple, PriceRow] = {}
    for p in prices:
        key = (p.station_id, p.fuel, p.is_self)
        if key not in latest or p.reported_at > latest[key].reported_at:
            latest[key] = p
    return list(latest.values())


def trimmed_mean(values: list[float], tolerance: float = 0.3) -> float | None:
    """Media escludendo i valori lontani più del 30% dalla mediana."""
    if not values:
        return None
    median = statistics.median(values)
    kept = [v for v in values if abs(v - median) <= median * tolerance]
    return statistics.fmean(kept) if kept else None


def national_averages(prices: list[PriceRow], day: date, max_age_days: int) -> list[dict]:
    """Medie nazionali del giorno per carburante e modalità."""
    oldest = datetime.combine(day - timedelta(days=max_age_days), datetime.min.time())
    groups: dict[tuple[Fuel, Mode], list[float]] = defaultdict(list)
    # Per GPL e metano un solo prezzo per distributore: il più basso.
    any_mode: dict[tuple[Fuel, int], float] = {}
    for p in prices:
        if p.reported_at < oldest:
            continue
        if p.fuel.has_service_modes:
            groups[(p.fuel, Mode.self_ if p.is_self else Mode.servito)].append(p.price)
        else:
            key = (p.fuel, p.station_id)
            any_mode[key] = min(p.price, any_mode.get(key, p.price))
    for (fuel, _), price in any_mode.items():
        groups[(fuel, Mode.any)].append(price)

    rows = []
    for (fuel, mode), values in sorted(groups.items()):
        avg = trimmed_mean(values)
        if avg is not None:
            rows.append(
                {
                    "day": day,
                    "fuel": fuel.value,
                    "mode": mode.value,
                    "price": round(avg, 4),
                    "stations": len(values),
                }
            )
    return rows


def run_import(
    db: Database,
    stations_lines: Iterable[str],
    prices_lines: Iterable[str],
    settings: Settings,
) -> ImportResult:
    stations_day, stations = parse_stations(stations_lines)
    prices_day, prices = parse_prices(prices_lines)
    if stations_day != prices_day:
        log.warning("date diverse: anagrafica %s, prezzi %s", stations_day, prices_day)
    day = prices_day

    station_ids = {s.id for s in stations}
    prices = [p for p in _dedupe_latest(prices) if p.station_id in station_ids]

    with db.sessions() as session, session.begin():
        existing = set(session.scalars(select(Station.id)))
        rows = [{**s.__dict__, "last_seen": day} for s in stations]
        new = [r for r in rows if r["id"] not in existing]
        old = [r for r in rows if r["id"] in existing]
        for chunk in _batches(new):
            session.execute(insert(Station), chunk)
        for chunk in _batches(old):
            session.execute(update(Station), chunk)

        price_rows = [
            {
                "station_id": p.station_id,
                "fuel": p.fuel.value,
                "is_self": p.is_self,
                "price": p.price,
                "reported_at": p.reported_at,
            }
            for p in prices
        ]
        session.execute(delete(CurrentPrice))
        for chunk in _batches(price_rows):
            session.execute(insert(CurrentPrice), chunk)
        new_changes = _insert_ignore(session, PriceChange.__table__, price_rows)

        averages = national_averages(prices, day, settings.average_max_age_days)
        session.execute(delete(NationalAverage).where(NationalAverage.day == day))
        if averages:
            session.execute(insert(NationalAverage), averages)

        session.merge(
            ImportRun(
                day=day,
                stations=len(stations),
                prices=len(prices),
                finished_at=datetime.now(),
            )
        )

    result = ImportResult(day, len(stations), len(prices), new_changes)
    log.info("import %s", result)
    return result


def download(url: str) -> list[str]:
    response = httpx.get(url, timeout=120, follow_redirects=True)
    response.raise_for_status()
    return response.content.decode("utf-8-sig").splitlines()


def _read(path: str) -> list[str]:
    with open(path, encoding="utf-8-sig") as f:
        return f.read().splitlines()


def main(argv: list[str] | None = None) -> None:
    parser = argparse.ArgumentParser(description="Importa i prezzi dei carburanti dal MIMIT.")
    parser.add_argument("--stations-file", help="anagrafica_impianti_attivi.csv locale")
    parser.add_argument("--prices-file", help="prezzo_alle_8.csv locale")
    args = parser.parse_args(argv)
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(name)s: %(message)s")

    settings = get_settings()
    engine = make_engine(settings.database_url)
    create_schema(engine)
    stations = (
        _read(args.stations_file) if args.stations_file else download(settings.mimit_stations_url)
    )
    prices = _read(args.prices_file) if args.prices_file else download(settings.mimit_prices_url)
    run_import(Database(engine), stations, prices, settings)


if __name__ == "__main__":
    main()
