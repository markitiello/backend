import os
from pathlib import Path

import pytest
from fastapi.testclient import TestClient
from sqlalchemy import create_engine
from sqlalchemy.pool import StaticPool

from benzina_api.config import Settings
from benzina_api.db import Base, Database, create_schema, make_engine
from benzina_api.importer import run_import
from benzina_api.main import create_app

FIXTURES = Path(__file__).parent / "fixtures"
API_KEY = "chiave-di-test"
# Milano, Città Studi: i distributori 1001-1004 delle fixture sono entro 2 km.
CENTER = {"lat": 45.4781, "lng": 9.2270}


def lines(name: str) -> list[str]:
    return (FIXTURES / name).read_text(encoding="utf-8").splitlines()


@pytest.fixture
def settings() -> Settings:
    return Settings(database_url="sqlite://", api_keys=[API_KEY], _env_file=None)


# Con BENZINA_TEST_DATABASE_URL (es. postgresql://...) i test girano su quel
# database, che viene svuotato a ogni test. Altrimenti SQLite in memoria.
TEST_DATABASE_URL = os.environ.get("BENZINA_TEST_DATABASE_URL")


@pytest.fixture
def engine():
    if TEST_DATABASE_URL:
        engine = make_engine(TEST_DATABASE_URL)
        Base.metadata.drop_all(engine)
    else:
        engine = create_engine(
            "sqlite://", connect_args={"check_same_thread": False}, poolclass=StaticPool
        )
    create_schema(engine)
    yield engine
    engine.dispose()


@pytest.fixture
def db(engine) -> Database:
    return Database(engine)


@pytest.fixture
def imported(db, settings) -> Database:
    """Database con due giorni di import (24 e 25 settembre 2026)."""
    run_import(db, lines("stations_day1.csv"), lines("prices_day1.csv"), settings)
    run_import(db, lines("stations_day2.csv"), lines("prices_day2.csv"), settings)
    return db


@pytest.fixture
def client(settings, engine, imported) -> TestClient:
    app = create_app(settings, engine=engine)
    return TestClient(app, headers={"X-API-Key": API_KEY})
