from datetime import date

import pytest
from sqlalchemy import func, select

from benzina_api.db import CurrentPrice, ImportRun, NationalAverage, PriceChange, Station
from benzina_api.importer import run_import, trimmed_mean
from tests.conftest import lines


def _avg(session, day, fuel, mode):
    return session.scalar(
        select(NationalAverage.price).where(
            NationalAverage.day == day, NationalAverage.fuel == fuel, NationalAverage.mode == mode
        )
    )


def test_primo_import(db, settings):
    result = run_import(db, lines("stations_day1.csv"), lines("prices_day1.csv"), settings)
    assert result.day == date(2026, 9, 24)
    assert result.stations == 5
    # 9999 non è in anagrafica, 1006 non ha coordinate: esclusi.
    assert result.prices == 11
    assert result.new_changes == 11

    with db.sessions() as s:
        assert s.scalar(select(func.count()).select_from(Station)) == 5
        # Il prezzo del 1004 è di giugno: resta tra i prezzi attuali ma non
        # entra nella media nazionale.
        assert _avg(s, date(2026, 9, 24), "benzina", "self") == pytest.approx(
            (1.739 + 1.759 + 1.772 + 1.701) / 4, abs=1e-4
        )
        assert _avg(s, date(2026, 9, 24), "benzina", "servito") == pytest.approx(1.899)
        # GPL e metano: un prezzo per distributore, il più basso.
        assert _avg(s, date(2026, 9, 24), "gpl", "any") == pytest.approx((0.749 + 0.719) / 2)
        assert _avg(s, date(2026, 9, 24), "metano", "any") == pytest.approx(1.459)


def test_secondo_import_aggiorna_e_salva_solo_le_variazioni(imported):
    with imported.sessions() as s:
        assert s.scalar(select(func.count()).select_from(PriceChange)) == 13
        current = s.get(CurrentPrice, (1001, "benzina", True))
        assert current.price == 1.729
        assert s.get(Station, 1001).name == "Q8 EASY PACINI"
        assert s.get(Station, 1001).last_seen == date(2026, 9, 25)
        # Il Blue Diesel non viene mai importato.
        assert s.scalar(select(func.count()).select_from(CurrentPrice)) == 11
        assert sorted(s.scalars(select(ImportRun.day))) == [date(2026, 9, 24), date(2026, 9, 25)]


def test_reimport_dello_stesso_giorno_e_idempotente(imported, settings):
    result = run_import(imported, lines("stations_day2.csv"), lines("prices_day2.csv"), settings)
    assert result.new_changes == 0
    with imported.sessions() as s:
        n = s.scalar(
            select(func.count())
            .select_from(NationalAverage)
            .where(NationalAverage.day == date(2026, 9, 25))
        )
        assert n == 5  # benzina self/servito, gasolio self, gpl, metano


def test_media_senza_valori_anomali():
    assert trimmed_mean([1.8, 1.82, 1.79, 0.18]) == pytest.approx((1.8 + 1.82 + 1.79) / 3)
    assert trimmed_mean([]) is None
