from enum import StrEnum


class Fuel(StrEnum):
    benzina = "benzina"
    diesel = "diesel"
    gpl = "gpl"
    metano = "metano"

    @property
    def has_service_modes(self) -> bool:
        """GPL e metano si confrontano senza distinguere self e servito."""
        return self in (Fuel.benzina, Fuel.diesel)


class Mode(StrEnum):
    self_ = "self"
    servito = "servito"
    # Usato per GPL e metano: self e servito insieme.
    any = "any"


def effective_mode(fuel: Fuel, mode: Mode) -> Mode:
    """Modalità con cui si confrontano i prezzi di [fuel]."""
    if not fuel.has_service_modes:
        return Mode.any
    return Mode.self_ if mode == Mode.any else mode


# Valori di `descCarburante` del MIMIT considerati. Le varianti "premium"
# (Blue Diesel, HVO, Benzina speciale, ...) sono escluse dai confronti.
MIMIT_FUELS: dict[str, Fuel] = {
    "Benzina": Fuel.benzina,
    "Gasolio": Fuel.diesel,
    "GPL": Fuel.gpl,
    "Metano": Fuel.metano,
}
