# Benzina API — documentazione

La specifica completa è in [`openapi.yaml`](openapi.yaml) / [`openapi.json`](openapi.json) (OpenAPI 3.1). Con il server avviato è consultabile anche in modo interattivo su `/docs` (Swagger UI) e `/redoc`.

Questa pagina spiega **come usarla**: autenticazione, convenzioni, rotte ed esempi.

- URL base: da definire al deploy (es. `https://api.benzina.app`)
- Formato: JSON, UTF-8. Date `AAAA-MM-GG`, orari ISO 8601 senza fuso (ora italiana, come li pubblica il MIMIT).
- Prezzi: euro al litro (euro al kg per il metano), con 3 decimali come nei dati MIMIT.

## Autenticazione

Tutte le rotte sotto `/v1` accettano solo richieste dell'app Benzina. Serve **uno** dei due header:

| Header | Quando | Come funziona |
|---|---|---|
| `X-Firebase-AppCheck` | **Produzione** (l'app) | Token [Firebase App Check](https://firebase.google.com/docs/app-check). L'app lo ottiene da Firebase, che prima verifica che sia l'app originale su un dispositivo reale (Play Integrity su Android, App Attest su iOS). Il backend controlla firma, emittente, progetto, scadenza e (se configurato) l'ID dell'app. |
| `X-API-Key` | Sviluppo, test, chiamate da server | Chiave statica configurata con `BENZINA_API_KEYS`. |

Senza credenziali valide la risposta è `401`. `/health` è pubblica.

Perché non basta una chiave nell'app: qualunque segreto incluso nell'app si può estrarre dal pacchetto installato. App Check invece si basa su un'attestazione del sistema operativo che un client falso non può ottenere; i token durano poco e vengono rinnovati automaticamente dall'SDK Firebase.

Configurazione del server:

```sh
BENZINA_APPCHECK_PROJECT_NUMBER=123456789012        # numero del progetto Firebase
BENZINA_APPCHECK_APP_IDS=1:123...:android:abc,1:123...:ios:def   # facoltativo
BENZINA_API_KEYS=chiave-sviluppo                    # facoltativo, separate da virgola
```

Nell'app Flutter: pacchetti `firebase_core` e `firebase_app_check`, poi a ogni richiesta `FirebaseAppCheck.instance.getToken()` nell'header `X-Firebase-AppCheck`.

## Errori

Gli errori seguono l'[RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) (`Content-Type: application/problem+json`):

```json
{
  "type": "about:blank",
  "title": "Parametri non validi",
  "status": 422,
  "detail": "radius_km: Input should be less than or equal to 50"
}
```

| Codice | Significato |
|---|---|
| 401 | Credenziali mancanti o non valide |
| 404 | Distributore inesistente (o, per le recensioni, non abbinato a Google) |
| 422 | Parametri non validi |
| 502 | Google Places non ha risposto |
| 503 | Recensioni Google non attive sul server |

## Carburanti e modalità

| `fuel` | Dati MIMIT | `mode` |
|---|---|---|
| `benzina` | `Benzina` | `self` o `servito` |
| `diesel` | `Gasolio` | `self` o `servito` |
| `gpl` | `GPL` | ignorato: si usa `any` |
| `metano` | `Metano` | ignorato: si usa `any` |

Le varianti "premium" (Blue Diesel, HVO, Benzina speciale, ...) non sono incluse. Per GPL e metano, se un distributore ha sia self sia servito, conta il prezzo più basso; la risposta indica quale nel campo `mode` di ogni offerta.

## Rotte

### `GET /health`

Stato del servizio e giorno dell'ultimo aggiornamento dei dati.

```json
{ "status": "ok", "data_date": "2026-09-25" }
```

### `GET /v1/stations/nearby`

Distributori entro un raggio, dal più economico (a parità di prezzo, il più vicino).

| Parametro | Tipo | Default | |
|---|---|---|---|
| `lat`, `lng` | numero | obbligatori | Posizione dell'utente |
| `radius_km` | numero | 5 | Massimo 50 |
| `fuel` | enum | `benzina` | |
| `mode` | enum | `self` | |
| `limit` | intero | 50 | Massimo 200 |

```sh
curl -H "X-API-Key: $KEY" \
  "$URL/v1/stations/nearby?lat=45.4781&lng=9.2270&radius_km=5&fuel=benzina&mode=self&limit=2"
```

```json
{
  "fuel": "benzina",
  "mode": "self",
  "data_date": "2026-09-25",
  "national_average": 1.742,
  "offers": [
    {
      "station": {
        "id": 1001, "brand": "Q8", "name": "Q8 EASY PACINI",
        "address": "VIA PACINI 12", "city": "MILANO", "province": "MI",
        "lat": 45.4841, "lng": 9.231
      },
      "price": 1.729,
      "mode": "self",
      "reported_at": "2026-09-24T19:00:00",
      "distance_km": 0.736
    }
  ]
}
```

`national_average` è la media nazionale dello stesso carburante e modalità, per mostrare il "−0,080 vs media".

### `GET /v1/stations/{station_id}`

Dettaglio con tutti i prezzi attuali (`prices`: carburante, modalità, prezzo, data di comunicazione). `station_id` è l'`idImpianto` del MIMIT.

### `GET /v1/stations?ids=1001,1002`

Fino a 50 distributori in una richiesta (per la schermata Preferiti). Gli id inesistenti vengono omessi.

### `GET /v1/stations/{station_id}/trend`

Prezzo del distributore giorno per giorno. Parametri: `fuel`, `mode`, `days` (2–366, default 30).

Il prezzo di un giorno è l'ultimo comunicato dal gestore entro quel giorno; i giorni precedenti al primo prezzo noto mancano.

```json
{
  "fuel": "benzina",
  "mode": "self",
  "points": [
    { "day": "2026-09-23", "price": 1.739 },
    { "day": "2026-09-24", "price": 1.729 },
    { "day": "2026-09-25", "price": 1.729 }
  ]
}
```

### `GET /v1/stations/{station_id}/google-rating`

Valutazione e recensioni da Google Places.

```json
{
  "place_id": "ChIJ...",
  "rating": 4.3,
  "rating_count": 212,
  "maps_url": "https://maps.google.com/?cid=...",
  "reviews": [
    {
      "author": "Mario R.",
      "author_uri": "https://www.google.com/maps/contrib/...",
      "rating": 5,
      "relative_time": "2 settimane fa",
      "text": "Personale gentile."
    }
  ],
  "attribution": "Valutazioni e recensioni fornite da Google"
}
```

Vincoli di Google da rispettare nell'app:

- mostrare il testo `attribution` e, per ogni recensione, il nome dell'autore con il link `author_uri`;
- Google fornisce **al massimo 5 recensioni** e **non** fornisce la distribuzione dei voti (quante 5 stelle, 4 stelle, ...): per le altre si apre `maps_url`;
- il server salva solo il `place_id`; valutazione e recensioni vengono richieste a Google ogni volta (a pagamento: meglio chiamarla solo nella schermata di dettaglio).

L'abbinamento distributore → luogo Google avviene alla prima richiesta: ricerca per marchio e indirizzo, accettando solo un risultato entro 250 m dalle coordinate MIMIT. Se non c'è, la risposta è `404` e la ricerca si ripete dopo 30 giorni.

### `GET /v1/trends/national`

Media nazionale giornaliera. Parametri: `fuel`, `mode`, `days`.

Calcolata a ogni import: media di tutti i distributori, esclusi i prezzi comunicati da oltre 30 giorni e quelli lontani più del 30% dalla mediana (errori di inserimento). C'è un punto per ogni giorno in cui l'import è stato eseguito.

### `GET /v1/trends/area`

Media giornaliera dei distributori entro `radius_km` da `lat`/`lng`. Parametri come `nearby` più `days`. Serve per il confronto "la tua zona vs Italia".

## Aggiornamento dei dati

I prezzi arrivano dagli [open data del MIMIT](https://www.mimit.gov.it/it/open-data/elenco-dataset/carburanti-prezzi-praticati-e-anagrafica-degli-impianti) (licenza IODL 2.0), pubblicati una volta al giorno con i prezzi validi alle 8. L'import (`benzina-import`) gira ogni mattina; `data_date` indica l'ultimo import riuscito.
