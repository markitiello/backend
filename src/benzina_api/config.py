from functools import lru_cache
from typing import Annotated

from pydantic import Field, field_validator
from pydantic_settings import BaseSettings, NoDecode, SettingsConfigDict

# Liste nelle variabili d'ambiente separate da virgole: "a,b,c".
CommaList = Annotated[list[str], NoDecode]


class Settings(BaseSettings):
    """Configurazione letta dalle variabili d'ambiente con prefisso `BENZINA_`."""

    model_config = SettingsConfigDict(env_prefix="BENZINA_", env_file=".env", extra="ignore")

    database_url: str = "sqlite:///./benzina.db"

    # --- Autenticazione (vedi docs/API.md, "Autenticazione") -----------------
    # Numero del progetto Firebase: abilita la verifica dei token App Check.
    appcheck_project_number: str | None = None
    # ID delle app Firebase accettate (Android e iOS). Vuoto = tutte quelle del progetto.
    appcheck_app_ids: CommaList = Field(default_factory=list)
    # Chiavi statiche per sviluppo, test e chiamate da server.
    api_keys: CommaList = Field(default_factory=list)
    # Solo per sviluppo locale: accetta richieste senza credenziali.
    auth_disabled: bool = False

    # --- Google Places ----------------------------------------------------------
    google_places_api_key: str | None = None
    # Secondi di cache in memoria dei dettagli Google (0 = nessuna cache).
    # Verificare i termini di Google Maps Platform prima di aumentarla.
    google_cache_seconds: int = 0
    # Dopo quanti giorni riprovare l'abbinamento di un distributore non trovato.
    google_rematch_days: int = 30

    # --- Import MIMIT -----------------------------------------------------------
    mimit_stations_url: str = (
        "https://www.mimit.gov.it/images/exportCSV/anagrafica_impianti_attivi.csv"
    )
    mimit_prices_url: str = "https://www.mimit.gov.it/images/exportCSV/prezzo_alle_8.csv"
    # I prezzi comunicati da più giorni di così non entrano nelle medie.
    average_max_age_days: int = 30

    docs_enabled: bool = True

    @field_validator("appcheck_app_ids", "api_keys", mode="before")
    @classmethod
    def _split_commas(cls, value: object) -> object:
        if isinstance(value, str):
            return [v.strip() for v in value.split(",") if v.strip()]
        return value


@lru_cache
def get_settings() -> Settings:
    return Settings()
