# Benzina — backend

API per l'app [Benzina](https://github.com/markitiello/benzina): distributori vicini dal più economico, media nazionale, andamento dei prezzi e recensioni Google.

- **Documentazione API:** [`docs/API.md`](docs/API.md)
- **Specifica OpenAPI 3.1:** [`docs/openapi.yaml`](docs/openapi.yaml) · [`docs/openapi.json`](docs/openapi.json), oppure `/docs` con il server avviato

## Come funziona

```
MIMIT (CSV giornalieri) ──benzina-import──▶ PostgreSQL ──▶ API FastAPI ──▶ app Benzina
                                                              │   ▲
                                         Google Places ◀──────┘   └── Firebase App Check
```

- **Import** (`src/benzina_api/importer.py`): ogni mattina scarica anagrafica e prezzi dal MIMIT, aggiorna i distributori, sostituisce i prezzi attuali, aggiunge allo storico **solo i prezzi cambiati** e calcola le medie nazionali del giorno.
- **API** (`src/benzina_api/api.py`): FastAPI genera la specifica OpenAPI dal codice.
- **Accesso**: solo l'app, con Firebase App Check; chiavi statiche per sviluppo (vedi [Autenticazione](docs/API.md#autenticazione)).
- **Google Places**: la chiave Google resta sul server; si salva solo il `place_id`.

| Tabella | Contenuto |
|---|---|
| `stations` | Anagrafica dei distributori (`id` = `idImpianto` MIMIT) |
| `current_prices` | Prezzi dell'ultimo import |
| `price_changes` | Storico: una riga per prezzo comunicato |
| `national_averages` | Media nazionale per giorno, carburante e modalità |
| `imports` | Esito di ogni import |
| `google_places` | Abbinamento distributore → luogo Google |

Lo storico salva le variazioni e non una copia al giorno: circa 75.000 righe al primo import, poi solo i prezzi cambiati.

## Sviluppo

Serve Python 3.11 o successivo.

```sh
python -m venv .venv && . .venv/bin/activate
pip install -e ".[dev]"
cp .env.example .env

benzina-import                         # scarica i dati MIMIT di oggi
uvicorn --factory benzina_api.main:app --reload
# http://localhost:8000/docs
```

Senza Postgres usa SQLite (`benzina.db`). Per importare file già scaricati:

```sh
benzina-import --stations-file anagrafica_impianti_attivi.csv --prices-file prezzo_alle_8.csv
```

### Test

```sh
pytest                     # SQLite in memoria
BENZINA_TEST_DATABASE_URL=postgresql://user:pass@localhost/benzina_test pytest   # PostgreSQL
ruff check src tests scripts && ruff format --check src tests scripts
```

I test coprono lettura dei CSV MIMIT (anche righe malformate), import e storico, tutte le rotte, verifica dei token App Check (firma, scadenza, progetto, app) e integrazione Google (con risposte simulate). Un test verifica che `docs/openapi.*` sia aggiornato: dopo aver cambiato le API

```sh
python scripts/export_openapi.py
```

La CI (`.github/workflows/ci.yml`) esegue lint e test su SQLite e PostgreSQL.

## Configurazione

Variabili d'ambiente (prefisso `BENZINA_`, vedi `src/benzina_api/config.py`):

| Variabile | Default | |
|---|---|---|
| `DATABASE_URL` | `sqlite:///./benzina.db` | In produzione `postgresql://...` |
| `APPCHECK_PROJECT_NUMBER` | — | Attiva la verifica App Check |
| `APPCHECK_APP_IDS` | — | ID app Firebase ammessi, separati da virgola |
| `API_KEYS` | — | Chiavi statiche, separate da virgola |
| `AUTH_DISABLED` | `false` | Solo sviluppo: nessun controllo di accesso |
| `GOOGLE_PLACES_API_KEY` | — | Attiva le recensioni Google |
| `GOOGLE_CACHE_SECONDS` | `0` | Cache in memoria dei dettagli Google |
| `DOCS_ENABLED` | `true` | `/docs`, `/redoc`, `/openapi.json` |

Senza `APPCHECK_PROJECT_NUMBER` né `API_KEYS` il server rifiuta tutte le richieste `/v1`.

## Deploy

- **Database:** qualunque PostgreSQL gestito (es. Supabase, Neon). Le tabelle si creano all'avvio.
- **API:** il `Dockerfile` avvia uvicorn sulla porta `$PORT` (es. Cloud Run, Fly.io, Render).
- **Import giornaliero:** `.github/workflows/import.yml` gira ogni mattina; serve il secret `BENZINA_DATABASE_URL` nel repository.

## Da fare

- [ ] Migrazioni dello schema con Alembic (oggi `create_all`).
- [ ] Backfill dello storico dall'[archivio MIMIT](https://www.mimit.gov.it/it/open-data/elenco-dataset/carburanti-archivio-prezzi).
- [ ] Notifiche push (soglie e preferiti) con Firebase Cloud Messaging.
- [ ] Limite di richieste per client.

Dati prezzi: MIMIT — Osservaprezzi Carburanti, licenza IODL 2.0.
