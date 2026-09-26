# Funzioni comuni agli script di deploy (da includere con "source").
# shellcheck shell=bash

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

log() { printf '\033[1;33m▸\033[0m %s\n' "$*"; }
ok() { printf '\033[1;32m✓\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m✗\033[0m %s\n' "$*" >&2; exit 1; }

# Legge deploy/deploy.env (o il file passato con -c). Le variabili d'ambiente
# già impostate hanno la precedenza (utile in CI).
load_config() {
  local file="$1"
  [[ -f "$file" ]] || die "Configurazione mancante: $file (copiare deploy/deploy.env.example)"
  local line key value
  while IFS= read -r line || [[ -n "$line" ]]; do
    [[ "$line" =~ ^[[:space:]]*(#|$) ]] && continue
    key="${line%%=*}"
    value="${line#*=}"
    [[ "$key" =~ ^[A-Z_][A-Z0-9_]*$ ]] || continue
    if [[ -z "${!key:-}" ]]; then
      export "$key=$value"
    fi
  done < "$file"
}

# Opzioni ssh: la porta solo se indicata, altrimenti vale ~/.ssh/config.
ssh_command() {
  local cmd="ssh -o BatchMode=yes"
  [[ -n "${DEPLOY_PORT:-}" ]] && cmd+=" -p $DEPLOY_PORT"
  echo "$cmd"
}

# Esegue un comando bash sul server (o in locale con DEPLOY_HOST=local).
# shellcheck disable=SC2153  # DEPLOY_HOST arriva da load_config
remote() {
  if [[ "$DEPLOY_HOST" == "local" ]]; then
    bash --norc -euo pipefail -c "$1"
  else
    # shellcheck disable=SC2029,SC2046  # il comando va espanso qui, in locale
    $(ssh_command) "$DEPLOY_HOST" "bash --norc -euo pipefail -c $(printf '%q' "$1")"
  fi
}

# Copia una cartella locale sul server con rsync.
upload() {
  local src="$1" dest="$2" link_dest="${3:-}"
  local args=(-a --delete)
  [[ -n "$link_dest" ]] && args+=("--link-dest=$link_dest")
  if [[ "$DEPLOY_HOST" == "local" ]]; then
    rsync "${args[@]}" "$src/" "$dest/"
  else
    rsync "${args[@]}" -e "$(ssh_command)" "$src/" "$DEPLOY_HOST:$dest/"
  fi
}

# Prepara in una cartella temporanea la versione da pubblicare: solo i file
# del commit corrente (git archive) e le dipendenze di produzione.
build_release() {
  local build="$1"
  git -C "$ROOT" archive --format=tar HEAD | tar -x -C "$build"
  rm -rf "$build/tests" "$build/.github" "$build/deploy" "$build/phpunit.xml" "$build/Dockerfile" "$build/.dockerignore"
  (cd "$build" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --no-progress \
    --prefer-dist --optimize-autoloader --classmap-authoritative --quiet)
  # Se Composer ha dovuto clonare i pacchetti da git, niente cartelle .git online.
  find "$build/vendor" -name .git -type d -prune -exec rm -rf {} +
  git -C "$ROOT" rev-parse HEAD > "$build/REVISION"
  # mktemp crea la cartella con permessi 700: il web server deve poterla leggere.
  chmod 755 "$build"
}

# GET /health: deve rispondere 200 con "status":"ok".
health_check() {
  local url="${DEPLOY_URL%/}/health" body
  for attempt in 1 2 3 4 5; do
    if body="$(curl -fsS --max-time 10 "$url" 2>/dev/null)" && [[ "$body" == *'"status":"ok"'* ]]; then
      ok "Controllo superato: $url → $body"
      return 0
    fi
    sleep "$attempt"
  done
  return 1
}
