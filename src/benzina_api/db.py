from collections.abc import Iterator
from datetime import date, datetime

from sqlalchemy import (
    Boolean,
    Date,
    DateTime,
    Float,
    Index,
    Integer,
    String,
    create_engine,
)
from sqlalchemy.engine import Engine
from sqlalchemy.orm import DeclarativeBase, Mapped, Session, mapped_column, sessionmaker


class Base(DeclarativeBase):
    pass


class Station(Base):
    """Distributore dall'anagrafica MIMIT (`idImpianto`)."""

    __tablename__ = "stations"

    id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=False)
    operator: Mapped[str] = mapped_column(String(200))
    brand: Mapped[str] = mapped_column(String(100))
    kind: Mapped[str] = mapped_column(String(30))
    name: Mapped[str] = mapped_column(String(200))
    address: Mapped[str] = mapped_column(String(300))
    city: Mapped[str] = mapped_column(String(100))
    province: Mapped[str] = mapped_column(String(4))
    lat: Mapped[float] = mapped_column(Float)
    lng: Mapped[float] = mapped_column(Float)
    # Ultimo import in cui compariva: quelli chiusi smettono di comparire.
    last_seen: Mapped[date] = mapped_column(Date, index=True)

    __table_args__ = (Index("ix_stations_lat_lng", "lat", "lng"),)


class CurrentPrice(Base):
    """Prezzi dell'ultimo import. Sostituiti per intero a ogni import."""

    __tablename__ = "current_prices"

    station_id: Mapped[int] = mapped_column(Integer, primary_key=True)
    fuel: Mapped[str] = mapped_column(String(10), primary_key=True)
    is_self: Mapped[bool] = mapped_column(Boolean, primary_key=True)
    price: Mapped[float] = mapped_column(Float)
    reported_at: Mapped[datetime] = mapped_column(DateTime)

    __table_args__ = (Index("ix_current_prices_fuel", "fuel", "is_self"),)


class PriceChange(Base):
    """Storico: una riga per ogni prezzo comunicato (non una per giorno).

    Il prezzo di un giorno è l'ultimo comunicato fino a quel giorno.
    """

    __tablename__ = "price_changes"

    station_id: Mapped[int] = mapped_column(Integer, primary_key=True)
    fuel: Mapped[str] = mapped_column(String(10), primary_key=True)
    is_self: Mapped[bool] = mapped_column(Boolean, primary_key=True)
    reported_at: Mapped[datetime] = mapped_column(DateTime, primary_key=True)
    price: Mapped[float] = mapped_column(Float)


class NationalAverage(Base):
    """Media nazionale giornaliera, calcolata a ogni import."""

    __tablename__ = "national_averages"

    day: Mapped[date] = mapped_column(Date, primary_key=True)
    fuel: Mapped[str] = mapped_column(String(10), primary_key=True)
    # "self", "servito" oppure "any" (GPL e metano).
    mode: Mapped[str] = mapped_column(String(10), primary_key=True)
    price: Mapped[float] = mapped_column(Float)
    stations: Mapped[int] = mapped_column(Integer)


class ImportRun(Base):
    __tablename__ = "imports"

    day: Mapped[date] = mapped_column(Date, primary_key=True)
    stations: Mapped[int] = mapped_column(Integer)
    prices: Mapped[int] = mapped_column(Integer)
    finished_at: Mapped[datetime] = mapped_column(DateTime)


class GooglePlace(Base):
    """Abbinamento distributore → luogo Google. Si salva solo il place_id,
    l'unico dato di Google che i termini permettono di conservare."""

    __tablename__ = "google_places"

    station_id: Mapped[int] = mapped_column(Integer, primary_key=True, autoincrement=False)
    # None = cercato ma non trovato.
    place_id: Mapped[str | None] = mapped_column(String(300), nullable=True)
    checked_at: Mapped[datetime] = mapped_column(DateTime)


def make_engine(url: str) -> Engine:
    if url.startswith("postgresql://"):
        url = url.replace("postgresql://", "postgresql+psycopg://", 1)
    connect_args = {"check_same_thread": False} if url.startswith("sqlite") else {}
    return create_engine(url, connect_args=connect_args, pool_pre_ping=True)


def create_schema(engine: Engine) -> None:
    Base.metadata.create_all(engine)


class Database:
    def __init__(self, engine: Engine):
        self.engine = engine
        self.sessions = sessionmaker(engine, expire_on_commit=False)

    def session(self) -> Iterator[Session]:
        with self.sessions() as s:
            yield s
