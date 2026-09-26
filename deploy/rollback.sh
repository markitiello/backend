#!/usr/bin/env bash
#
# Torna a una versione precedente del backend (deploy fatto con deploy.sh).
#
#   deploy/rollback.sh [-c deploy/deploy.env]            # versione precedente
#   deploy/rollback.sh [-c deploy/deploy.env] --list     # elenco versioni
#   deploy/rollback.sh [-c deploy/deploy.env] 20260926-101500
#
# Il database non viene toccato: le migrazioni aggiungono solo tabelle, quindi
# le versioni precedenti continuano a funzionare.

set -euo pipefail
# shellcheck source=deploy/lib.sh
source "$(dirname "$0")/lib.sh"

CONFIG="$ROOT/deploy/deploy.env"
TARGET=""
LIST=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    -c) CONFIG="$2"; shift 2 ;;
    --list) LIST=1; shift ;;
    -h|--help) sed -n '2,10p' "$0"; exit 0 ;;
    *) TARGET="$1"; shift ;;
  esac
done
load_config "$CONFIG"
: "${DEPLOY_HOST:?}" "${DEPLOY_PATH:?}" "${DEPLOY_URL:?}"

CURRENT="$(remote "readlink '$DEPLOY_PATH/current' | sed 's#releases/##'")"
RELEASES="$(remote "cd '$DEPLOY_PATH/releases' && ls -1d */ | sed 's#/\$##' | sort")"

if [[ "$LIST" -eq 1 ]]; then
  while read -r r; do
    [[ "$r" == "$CURRENT" ]] && echo "$r  ← online" || echo "$r"
  done <<< "$RELEASES"
  exit 0
fi

if [[ -z "$TARGET" ]]; then
  TARGET="$(grep -B1 -x "$CURRENT" <<< "$RELEASES" | head -n1)"
  [[ -n "$TARGET" && "$TARGET" != "$CURRENT" ]] || die "Nessuna versione precedente a $CURRENT."
fi
grep -qx "$TARGET" <<< "$RELEASES" || die "Versione $TARGET inesistente (deploy/rollback.sh --list)."

log "Da $CURRENT a $TARGET"
remote "
  cd '$DEPLOY_PATH'
  ln -sfn 'releases/$TARGET' current.tmp && mv -Tf current.tmp current
  ${DEPLOY_RELOAD:+$DEPLOY_RELOAD}
"
health_check || die "La versione $TARGET non risponde correttamente su $DEPLOY_URL/health"
ok "Online: $TARGET"
