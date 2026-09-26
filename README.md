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
- **Notifiche push** (`src/Trend/`): dopo l'import rileva quando la media nazionale inizia a salire o scendere e lo notifica con Firebase Cloud Messaging (Android e iOS). Vedi [Notifiche push di tendenza](docs/API.md#notifiche-push-di-tendenza).
- **Google Places** (`src/Google/PlacesClient.php`): la chiave Google resta sul server; si salva solo il `place_id`.
- **Database**: SQLite, MySQL/MariaDB o PostgreSQL, tramite PDO. Le tabelle si creano da sole.

| Tabella | Contenuto |
|---|---|
| `stations` | Anagrafica dei distributori (`id` = `idImpianto` MIMIT) |
| `current_prices` | Prezzi dell'ultimo import |
| `price_changes` | Storico: una riga per prezzo comunicato |
| `national_averages` | Media nazionale per giorno, carburante e modalità |
| `imports` | Esito di ogni import |
| `trend_alerts` | Tendenze rilevate e notifiche inviate |
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
- rilevamento delle tendenze, pausa tra avvisi, reinvio e messaggi FCM (con Google simulato);
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
| `BENZINA_FCM_CREDENTIALS` | — | Service account Firebase: attiva le notifiche push |
| `BENZINA_TREND_MIN_DAYS` | `3` | Giorni consecutivi per una tendenza |
| `BENZINA_TREND_MIN_CHANGE` | `0.005` | Variazione minima (0,5%) |
| `BENZINA_TREND_COOLDOWN_DAYS` | `7` | Pausa tra avvisi nella stessa direzione |
| `BENZINA_DOCS_ENABLED` | `true` | `/docs` e `/openapi.yaml` |

Senza `BENZINA_APPCHECK_PROJECT_NUMBER` né `BENZINA_API_KEYS` il server rifiuta tutte le richieste `/v1`.

## Deploy

Gli script sono in `deploy/`. La configurazione va in `deploy/deploy.env`, da creare partendo da `deploy/deploy.env.example`; il file non va nel repository. Ogni script esegue prima i test (`--skip-tests` per saltarli) e alla fine controlla che `GET /health` risponda.

Sul computer da cui si fa il deploy servono PHP 8.2 o successivo, Composer, git e curl, più `rsync` per `deploy.sh` o `lftp` per `deploy-ftp.sh`. Se mancano le dipendenze di sviluppo (PHPUnit), gli script le installano da soli con `composer install`.

### Server con SSH (VPS o hosting con SSH): `deploy/deploy.sh`

```sh
deploy/deploy.sh              # test, build, upload, migrazione, messa online
deploy/rollback.sh --list     # versioni sul server
deploy/rollback.sh            # torna alla versione precedente
```

Sul server ogni versione ha la sua cartella; `current` punta a quella online e viene spostato in modo atomico, senza interruzioni:

```
/var/www/benzina/
  releases/20260926-110445/   ultime DEPLOY_KEEP versioni (default 5)
  shared/.env                 configurazione (creata a mano la prima volta)
  shared/var/                 database SQLite, cache, log: scrivibile dal web server
  current -> releases/...     il web server serve current/public
```

Se dopo il cambio di versione `/health` non risponde, il deploy torna da solo alla versione precedente e cancella quella guasta.

**Prima configurazione del server:**
1. PHP 8.2 o successivo, con l'estensione PDO del database scelto.
2. Creare `DEPLOY_PATH/shared/.env` (da `.env.example`). La cartella `shared/var/` deve essere scrivibile dall'utente del web server.
3. Web server che serve `DEPLOY_PATH/current/public`: esempi in `deploy/nginx.conf.example` e `deploy/apache.conf.example`.
4. Cron dell'import giornaliero: `deploy/crontab.example`.

**Da GitHub:** `.github/workflows/deploy.yml` esegue test e `deploy.sh` a ogni tag `v*` (es. `git tag v1.0.0 && git push --tags`) o a mano da Actions. I secret necessari sono elencati all'inizio del file.

### Hosting condiviso con solo FTP: `deploy/deploy-ftp.sh`

```sh
FTP_PASSWORD=... deploy/deploy-ftp.sh
```

Carica il progetto con le dipendenze di produzione via FTP/FTPS (serve `lftp`). Elimina i file non più presenti ma non tocca mai `.env` e `var/` sul server. Non è atomico e non ha rollback: se l'hosting offre SSH, meglio `deploy.sh`.

Il `.env` non fa parte della build: contiene le password, quindi non va nel repository, e un deploy non deve sovrascrivere la configurazione del server. Per caricarlo si passa esplicitamente il file:

```sh
FTP_PASSWORD=... deploy/deploy-ftp.sh --env .env.production
```

`.env`, `.env.*` (tranne `.env.example`) e `deploy/deploy.env` sono esclusi da git.

**Prima volta:**
1. Caricare il `.env` di produzione con `--env`, oppure a mano nella cartella del progetto.
2. Nel pannello dell'hosting, puntare il dominio su `public/`; se non si può, basta la cartella del progetto, grazie al `.htaccess` nella radice.
3. Impostare il cron giornaliero `php .../bin/import.php`.

**Hosting Windows (IIS, es. Plesk per Windows):** IIS non legge i file `.htaccess`: al loro posto ci sono i `web.config`, nella radice e in `public/`, con le stesse regole. Servono:
- il modulo URL Rewrite di IIS, già presente su Plesk;
- PHP 8.2 o successivo, da Plesk → Siti web e domini → Impostazioni PHP;
- se si usa SQLite, i permessi di scrittura sulla cartella `var/` per l'utente del sito, da Plesk → File → Modifica permessi;
- l'import giornaliero come "Operazione pianificata" di Plesk, che esegue `php.exe` con il percorso di `bin\import.php`.

Gli errori PHP sono in Plesk → Log. Il backend scrive gli errori nel file di log di PHP, se configurato, altrimenti in `var/log/errori.log`. Non usa lo standard error, che IIS trasformerebbe in un 500 vuoto. Durante la configurazione, `BENZINA_DEBUG=true` nel `.env` mostra nella risposta il motivo di un errore all'avvio; poi va tolto.

### Container

Il `Dockerfile` (PHP 8.4 + Apache) serve `public/` ed è adatto ad esempio a Cloud Run, Render o Fly.io. In alternativa al cron, `.github/workflows/import.yml` esegue l'import da GitHub; servono i secret del database, più `BENZINA_FCM_CREDENTIALS` per le notifiche.

## Da fare

- [ ] Backfill dello storico dall'[archivio MIMIT](https://www.mimit.gov.it/it/open-data/elenco-dataset/carburanti-archivio-prezzi).
- [ ] Notifiche personali (soglia di prezzo, preferiti, tendenza della propria zona): richiedono di registrare i dispositivi.
- [ ] Limite di richieste per client.

Dati prezzi: MIMIT — Osservaprezzi Carburanti, licenza IODL 2.0.
