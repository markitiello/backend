"""Interrogazioni sui dati importati."""

from collections import defaultdict
from datetime import date, datetime, time, timedelta

from sqlalchemy import func, select, true
from sqlalchemy.orm import Session

from . import schemas
from .db import CurrentPrice, ImportRun, NationalAverage, PriceChange, Station
from .fuels import Fuel, Mode
from .geo import bounding_box, distance_km


def latest_import_day(session: Session) -> date | None:
    return session.scalar(select(func.max(ImportRun.day)))


def _mode_filter(mode: Mode):
    if mode == Mode.any:
        return true()
    return CurrentPrice.is_self.is_(mode == Mode.self_)


def _mode_of(is_self: bool) -> Mode:
    return Mode.self_ if is_self else Mode.servito


def _stations_in_radius(
    session: Session, lat: float, lng: float, radius_km: float
) -> dict[int, tuple[Station, float]]:
    min_lat, max_lat, min_lng, max_lng = bounding_box(lat, lng, radius_km)
    rows = session.scalars(
        select(Station).where(
            Station.lat.between(min_lat, max_lat), Station.lng.between(min_lng, max_lng)
        )
    )
    result = {}
    for s in rows:
        d = distance_km(lat, lng, s.lat, s.lng)
        if d <= radius_km:
            result[s.id] = (s, d)
    return result


def nearby(
    session: Session,
    lat: float,
    lng: float,
    radius_km: float,
    fuel: Fuel,
    mode: Mode,
    limit: int,
) -> schemas.NearbyResponse:
    in_radius = _stations_in_radius(session, lat, lng, radius_km)
    best: dict[int, CurrentPrice] = {}
    if in_radius:
        prices = session.scalars(
            select(CurrentPrice).where(
                CurrentPrice.station_id.in_(in_radius),
                CurrentPrice.fuel == fuel.value,
                _mode_filter(mode),
            )
        )
        for p in prices:
            # GPL/metano: se ci sono self e servito si tiene il più basso.
            if p.station_id not in best or p.price < best[p.station_id].price:
                best[p.station_id] = p

    offers = [
        schemas.Offer(
            station=schemas.StationSummary.model_validate(in_radius[sid][0]),
            price=p.price,
            mode=_mode_of(p.is_self),
            reported_at=p.reported_at,
            distance_km=round(in_radius[sid][1], 3),
        )
        for sid, p in best.items()
    ]
    offers.sort(key=lambda o: (o.price, o.distance_km))

    day = latest_import_day(session)
    average = None
    if day is not None:
        average = session.scalar(
            select(NationalAverage.price).where(
                NationalAverage.day == day,
                NationalAverage.fuel == fuel.value,
                NationalAverage.mode == mode.value,
            )
        )
    return schemas.NearbyResponse(
        fuel=fuel, mode=mode, data_date=day, national_average=average, offers=offers[:limit]
    )


def stations_by_id(session: Session, ids: list[int]) -> list[schemas.Station]:
    stations = {s.id: s for s in session.scalars(select(Station).where(Station.id.in_(ids)))}
    prices: dict[int, list[schemas.Price]] = defaultdict(list)
    for p in session.scalars(
        select(CurrentPrice)
        .where(CurrentPrice.station_id.in_(stations))
        .order_by(CurrentPrice.fuel, CurrentPrice.is_self.desc())
    ):
        prices[p.station_id].append(
            schemas.Price(
                fuel=Fuel(p.fuel),
                mode=_mode_of(p.is_self),
                price=p.price,
                reported_at=p.reported_at,
            )
        )
    return [
        schemas.Station(
            **schemas.StationSummary.model_validate(stations[i]).model_dump(),
            operator=stations[i].operator,
            kind=stations[i].kind,
            prices=prices[i],
        )
        for i in ids
        if i in stations
    ]


def _window(days: int, end: date) -> list[date]:
    return [end - timedelta(days=d) for d in range(days - 1, -1, -1)]


def national_trend(
    session: Session, fuel: Fuel, mode: Mode, days: int, end: date | None = None
) -> schemas.Trend:
    end = end or latest_import_day(session) or date.today()
    start = end - timedelta(days=days - 1)
    rows = session.execute(
        select(NationalAverage.day, NationalAverage.price)
        .where(
            NationalAverage.fuel == fuel.value,
            NationalAverage.mode == mode.value,
            NationalAverage.day.between(start, end),
        )
        .order_by(NationalAverage.day)
    )
    return schemas.Trend(
        fuel=fuel,
        mode=mode,
        points=[schemas.TrendPoint(day=d, price=round(p, 3)) for d, p in rows],
    )


def daily_prices(
    session: Session,
    station_ids: list[int],
    fuel: Fuel,
    mode: Mode,
    days: list[date],
) -> dict[int, dict[date, float]]:
    """Prezzo di ogni distributore a fine giornata, ricostruito dallo storico
    delle variazioni (l'ultimo prezzo comunicato fino a quel giorno)."""
    if not station_ids or not days:
        return {}
    start = datetime.combine(days[0], time.min)
    end = datetime.combine(days[-1], time.max)
    mode_filter = true() if mode == Mode.any else PriceChange.is_self.is_(mode == Mode.self_)
    base = select(PriceChange).where(
        PriceChange.station_id.in_(station_ids), PriceChange.fuel == fuel.value, mode_filter
    )

    # Ultimo prezzo prima della finestra, per ogni distributore e modalità.
    last_before = (
        select(
            PriceChange.station_id,
            PriceChange.is_self,
            func.max(PriceChange.reported_at).label("at"),
        )
        .where(
            PriceChange.station_id.in_(station_ids),
            PriceChange.fuel == fuel.value,
            mode_filter,
            PriceChange.reported_at < start,
        )
        .group_by(PriceChange.station_id, PriceChange.is_self)
        .subquery()
    )
    before = session.scalars(
        select(PriceChange).join(
            last_before,
            (PriceChange.station_id == last_before.c.station_id)
            & (PriceChange.is_self == last_before.c.is_self)
            & (PriceChange.reported_at == last_before.c.at)
            & (PriceChange.fuel == fuel.value),
        )
    ).all()
    inside = session.scalars(
        base.where(PriceChange.reported_at.between(start, end)).order_by(PriceChange.reported_at)
    ).all()

    # (station, is_self) -> variazioni in ordine di tempo
    changes: dict[tuple[int, bool], list[PriceChange]] = defaultdict(list)
    for c in sorted([*before, *inside], key=lambda c: c.reported_at):
        changes[(c.station_id, c.is_self)].append(c)

    result: dict[int, dict[date, float]] = defaultdict(dict)
    for (station_id, _), series in changes.items():
        i, current = 0, None
        for day in days:
            day_end = datetime.combine(day, time.max)
            while i < len(series) and series[i].reported_at <= day_end:
                current = series[i].price
                i += 1
            if current is None:
                continue
            prev = result[station_id].get(day)
            result[station_id][day] = current if prev is None else min(prev, current)
    return result


def station_trend(
    session: Session, station_id: int, fuel: Fuel, mode: Mode, days: int
) -> schemas.Trend:
    end = latest_import_day(session) or date.today()
    window = _window(days, end)
    prices = daily_prices(session, [station_id], fuel, mode, window).get(station_id, {})
    return schemas.Trend(
        fuel=fuel,
        mode=mode,
        points=[schemas.TrendPoint(day=d, price=prices[d]) for d in window if d in prices],
    )


def area_trend(
    session: Session,
    lat: float,
    lng: float,
    radius_km: float,
    fuel: Fuel,
    mode: Mode,
    days: int,
) -> schemas.Trend:
    end = latest_import_day(session) or date.today()
    window = _window(days, end)
    ids = list(_stations_in_radius(session, lat, lng, radius_km))
    per_station = daily_prices(session, ids, fuel, mode, window)
    points = []
    for d in window:
        values = [p[d] for p in per_station.values() if d in p]
        if values:
            points.append(schemas.TrendPoint(day=d, price=round(sum(values) / len(values), 3)))
    return schemas.Trend(fuel=fuel, mode=mode, points=points)
