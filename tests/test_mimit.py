from datetime import date, datetime

import pytest

from benzina_api.fuels import Fuel
from benzina_api.mimit import MimitFormatError, parse_prices, parse_stations
from tests.conftest import lines


def test_anagrafica_legge_data_e_righe_valide():
    day, stations = parse_stations(lines("stations_day1.csv"))
    assert day == date(2026, 9, 24)
    # 1006 non ha coordinate, 1007 è troncata.
    assert [s.id for s in stations] == [1001, 1002, 1003, 1004, 1005]


def test_anagrafica_nome_con_separatore_e_spazi():
    _, stations = parse_stations(lines("stations_day1.csv"))
    by_id = {s.id: s for s in stations}
    # Il "|" nel nome non sposta gli altri campi.
    assert by_id[1003].name == "STOIL SIMPLE | gestori.prezzibenzina.it"
    assert by_id[1003].address == "VIA PORPORA 110"
    assert by_id[1003].lat == pytest.approx(45.4821)
    # Tabulazioni e spazi doppi vengono ripuliti.
    assert by_id[1002].name == "19829 ARGONNE"
    assert by_id[1001].address == "VIA PACINI 12"


def test_prezzi_solo_carburanti_base_e_valori_plausibili():
    day, prices = parse_prices(lines("prices_day1.csv"))
    assert day == date(2026, 9, 24)
    assert {p.fuel for p in prices} == {Fuel.benzina, Fuel.diesel, Fuel.gpl, Fuel.metano}
    # Niente Blue Diesel, niente 9.999, niente righe rotte.
    assert all(p.price < 5 for p in prices)
    first = prices[0]
    assert (first.station_id, first.is_self, first.price) == (1001, True, 1.739)
    assert first.reported_at == datetime(2026, 9, 23, 20, 0, 21)


def test_formato_inatteso():
    with pytest.raises(MimitFormatError):
        parse_prices(["idImpianto|descCarburante|prezzo|isSelf|dtComu"])
    with pytest.raises(MimitFormatError):
        parse_prices(["Estrazione del 2026-09-24", "altro|header"])
    with pytest.raises(MimitFormatError):
        parse_prices([])
