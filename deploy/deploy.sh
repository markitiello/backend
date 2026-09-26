#!/usr/bin/env bash
#
# Deploy del backend su un server con SSH (VPS o hosting con accesso SSH).
#
#   deploy/deploy.sh [-c deploy/deploy.env] [--skip-tests]
#
# Struttura sul server ($DEPLOY_PATH):
#   releases/20260926-101500/   una cartella per versione
#   shared/.env, shared/var/    configurazione e dati comuni a tutte le versioni
#   current -> releases/...     versione online; il web server serve current/public
#
# Passi: test → build (solo dipendenze di produzione) → upload → migrazione del
# database → cambio atomico di "current" → controllo /health. Se il controllo
# fallisce si torna automaticamente alla versione precedente.

set -euo pipefail
# shellcheck source=deploy/lib.sh
source "$(dirname "$0")/lib.sh"

CONFIG="$ROOT/deploy/deploy.env"
SKIP_TESTS=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    -c) CONFIG="$2"; shift 2 ;;
    --skip-tests) SKIP_TESTS=1; shift ;;
    -h|--help) sed -n '2,16p' "$0"; exit 0 ;;
    *) die "Opzione sconosciuta: $1" ;;
  esac
done
load_config "$CONFIG"
: "${DEPLOY_HOST:?}" "${DEPLOY_PATH:?}" "${DEPLOY_URL:?}"
require_tools rsync curl
DEPLOY_PHP="${DEPLOY_PHP:-php}"
DEPLOY_KEEP="${DEPLOY_KEEP:-5}"

if [[ -n "$(git -C "$ROOT" status --porcelain)" ]]; then
  log "Attenzione: ci sono modifiche non committate, verrà pubblicato solo l'ultimo commit."
fi
REVISION="$(git -C "$ROOT" rev-parse --short HEAD)"
RELEASE="$(date -u +%Y%m%d-%H%M%S)"
log "Deploy di $REVISION su $DEPLOY_HOST:$DEPLOY_PATH (versione $RELEASE)"

if [[ "$SKIP_TESTS" -eq 0 ]]; then
  log "Test"
  run_tests
fi

log "Build"
BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT
build_release "$BUILD"
ok "Build pronta ($(du -sh "$BUILD" | cut -f1))"

log "Preparazione del server"
remote "
  mkdir -p '$DEPLOY_PATH/releases' '$DEPLOY_PATH/shared/var'
  if [ ! -f '$DEPLOY_PATH/shared/.env' ]; then
    echo 'Manca $DEPLOY_PATH/shared/.env: crearlo sul server partendo da .env.example.' >&2
    exit 1
  fi
  '$DEPLOY_PHP' -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || { echo 'Serve PHP 8.2 o successivo.' >&2; exit 1; }
"
PREVIOUS="$(remote "readlink '$DEPLOY_PATH/current' 2>/dev/null || true")"

log "Upload"
# --link-dest: i file uguali alla versione precedente non vengono ritrasferiti.
upload "$BUILD" "$DEPLOY_PATH/releases/$RELEASE" "${PREVIOUS:+$DEPLOY_PATH/$PREVIOUS}"

log "Collegamento dei file condivisi e migrazione del database"
remote "
  cd '$DEPLOY_PATH/releases/$RELEASE'
  ln -sfn ../../shared/.env .env
  rm -rf var && ln -sfn ../../shared/var var
  '$DEPLOY_PHP' bin/migrate.php
"

switch_to() {
  remote "
    cd '$DEPLOY_PATH'
    ln -sfn '$1' current.tmp && mv -Tf current.tmp current
    ${DEPLOY_RELOAD:+$DEPLOY_RELOAD}
  "
}

log "Messa online"
switch_to "releases/$RELEASE"

if ! health_check; then
  if [[ -n "$PREVIOUS" ]]; then
    log "Controllo /health fallito: ritorno a $PREVIOUS"
    switch_to "$PREVIOUS"
    remote "rm -rf '$DEPLOY_PATH/releases/$RELEASE'"
  fi
  die "Deploy fallito: la nuova versione non risponde correttamente su $DEPLOY_URL/health"
fi

log "Pulizia (tengo le ultime $DEPLOY_KEEP versioni)"
remote "
  cd '$DEPLOY_PATH/releases'
  ls -1d */ | sed 's#/\$##' | sort | head -n -$DEPLOY_KEEP | xargs -r rm -rf
"
ok "Online: $REVISION ($RELEASE). Per tornare indietro: deploy/rollback.sh"
