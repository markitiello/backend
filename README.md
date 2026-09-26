# Benzina — backend (PHP)

API per l'app [Benzina](https://github.com/markitiello/benzina): distributori vicini dal più economico, media nazionale, andamento dei prezzi e recensioni Google.

- **Documentazione API:** [`docs/API.md`](docs/API.md)
- **Specifica OpenAPI:** [`docs/openapi.yaml`](docs/openapi.yaml), oppure `/docs` con il server avviato

> Una prima versione in Python (FastAPI) è nel branch `backend-python`.

## Come funziona

```
MIMIT (CSV giornalieri) ──bin/import.php──▶ database ──▶ API (Slim 4) ──▶ app Benzina
                                                            │   ▲
                                       Google Places ◀──────┘   └── Firebase App Check
```

- **Import** (`src/Importer.php`): ogni mattina scarica anagrafica e prezzi dal MIMIT, aggiorna i distributori, sostituisce i prezzi attuali, aggiunge allo storico **solo i prezzi cambiati** e calcola le medie nazionali del giorno. Con i file reali (~24.000 distributori, ~75.000 prezzi) impiega pochi secondi.
- **API** (`src/App.php`, `src/Http/ApiController.php`): rotte e parametri come descritti in `docs/openapi.yaml`.
- **Accesso**: solo l'app, con Firebase App Check; chiavi statiche per sviluppo (vedi [Autenticazione](docs/API.md#autenticazione)).
- **Google Places** (`src/Google/PlacesClient.php`): la chiave Google resta sul server; si salva solo il `place_id`.
- **Database**: SQLite, MySQL/MariaDB o PostgreSQL, tramite PDO. Le tabelle si creano da sole.

| Tabella | Contenuto |
|---|---|
| `stations` | Anagrafica dei distributori (`id` = `idImpianto` MIMIT) |
| `current_prices` | Prezzi dell'ultimo import |
| `price_changes` | Storico: una riga per prezzo comunicato |
| `national_averages` | Media nazionale per giorno, carburante e modalità |
| `imports` | Esito di ogni import |
| `google_places` | Abbinamento distributore → luogo Google |

## Sviluppo

Servono PHP 8.2 o successivo (estensioni `pdo_sqlite`, oppure `pdo_mysql`/`pdo_pgsql`) e Composer.

```sh
composer install
cp .env.example .env

php bin/import.php          # scarica i dati MIMIT di oggi
composer serve              # http://localhost:8000/docs
```

Per importare file già scaricati:

```sh
php bin/import.php --stations-file=anagrafica_impianti_attivi.csv --prices-file=prezzo_alle_8.csv
```

### Test

```sh
composer lint               # sintassi PHP
composer test               # PHPUnit su SQLite in memoria

# Stessi test su PostgreSQL o MySQL/MariaDB (database svuotato a ogni test):
BENZINA_TEST_DB_DSN="pgsql:host=localhost;dbname=benzina_test" BENZINA_TEST_DB_USER=... BENZINA_TEST_DB_PASSWORD=... composer test
BENZINA_TEST_DB_DSN="mysql:host=localhost;dbname=benzina_test" BENZINA_TEST_DB_USER=... BENZINA_TEST_DB_PASSWORD=... composer test
```

I test coprono:

- lettura dei CSV MIMIT, anche con righe malformate;
- import e storico;
- tutte le rotte e gli errori;
- verifica dei token App Check: firma, scadenza, progetto, app;
- integrazione Google, con risposte simulate.

Ogni richiesta e risposta dei test viene validata contro `docs/openapi.yaml`, e un test controlla che le rotte del codice e quelle della specifica coincidano. La CI (`.github/workflows/ci.yml`) li esegue con PHP 8.2 e 8.4 su SQLite, PostgreSQL e MariaDB.

## Configurazione

Variabili d'ambiente o file `.env` (vedi `.env.example` e `src/Config.php`):

| Variabile | Default | |
|---|---|---|
| `BENZINA_DB_DSN` | `sqlite:var/benzina.db` | DSN PDO: `mysql:host=...;dbname=...` o `pgsql:...` |
| `BENZINA_DB_USER`, `BENZINA_DB_PASSWORD` | — | |
| `BENZINA_APPCHECK_PROJECT_NUMBER` | — | Attiva la verifica App Check |
| `BENZINA_APPCHECK_APP_IDS` | — | ID app Firebase ammessi, separati da virgola |
| `BENZINA_API_KEYS` | — | Chiavi statiche, separate da virgola |
| `BENZINA_AUTH_DISABLED` | `false` | Solo sviluppo: nessun controllo di accesso |
| `BENZINA_GOOGLE_PLACES_API_KEY` | — | Attiva le recensioni Google |
| `BENZINA_DOCS_ENABLED` | `true` | `/docs` e `/openapi.yaml` |

Senza `BENZINA_APPCHECK_PROJECT_NUMBER` né `BENZINA_API_KEYS` il server rifiuta tutte le richieste `/v1`.

## Deploy

**Hosting PHP condiviso (Apache + MySQL):**

1. `composer install --no-dev --optimize-autoloader` e caricare i file.
2. La cartella pubblica del dominio deve essere `public/`; il `.htaccess` inoltra le richieste a `index.php`.
3. Creare `.env` con i dati del database MySQL.
4. Cron giornaliero, dopo le 8: `php /percorso/bin/import.php`.

**Container:** il `Dockerfile` (PHP 8.4 + Apache) serve `public/`, adatto ad esempio a Cloud Run, Render o Fly.io. In alternativa al cron, `.github/workflows/import.yml` esegue l'import da GitHub con i secret `BENZINA_DB_DSN`, `BENZINA_DB_USER` e `BENZINA_DB_PASSWORD`.

## Da fare

- [ ] Backfill dello storico dall'[archivio MIMIT](https://www.mimit.gov.it/it/open-data/elenco-dataset/carburanti-archivio-prezzi).
- [ ] Notifiche push (soglie e preferiti) con Firebase Cloud Messaging.
- [ ] Limite di richieste per client.

Dati prezzi: MIMIT — Osservaprezzi Carburanti, licenza IODL 2.0.
