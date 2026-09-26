#!/usr/bin/env bash
#
# Deploy su hosting condiviso con solo FTP/FTPS (senza SSH), es. Aruba o
# simili. Richiede lftp (brew install lftp / apt install lftp).
#
#   FTP_PASSWORD=... deploy/deploy-ftp.sh [-c deploy/deploy.env] [--skip-tests]
#
# Carica la versione (con le dipendenze di produzione) in $FTP_PATH, senza
# toccare .env e var/ sul server. Non è atomico e non ha rollback: durante
# l'upload le richieste possono trovare file misti. Con SSH usare deploy.sh.
#
# Prima volta: caricare a mano FTP_PATH/.env (da .env.example) e impostare nel
# pannello dell'hosting il cron giornaliero `php FTP_PATH/bin/import.php`.

set -euo pipefail
# shellcheck source=deploy/lib.sh
source "$(dirname "$0")/lib.sh"

CONFIG="$ROOT/deploy/deploy.env"
SKIP_TESTS=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    -c) CONFIG="$2"; shift 2 ;;
    --skip-tests) SKIP_TESTS=1; shift ;;
    -h|--help) sed -n '2,15p' "$0"; exit 0 ;;
    *) die "Opzione sconosciuta: $1" ;;
  esac
done
load_config "$CONFIG"
: "${FTP_HOST:?}" "${FTP_USER:?}" "${FTP_PATH:?}" "${DEPLOY_URL:?}"
[[ -n "${FTP_PASSWORD:-}" ]] || die "Impostare FTP_PASSWORD (variabile d'ambiente o deploy.env)."
require_tools lftp curl

if [[ "$SKIP_TESTS" -eq 0 ]]; then
  log "Test"
  run_tests
fi

log "Build"
BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT
build_release "$BUILD"
rm -rf "${BUILD:?}/var"

log "Upload su $FTP_HOST:$FTP_PATH"
# FTPS se disponibile; --delete toglie i file non più presenti, ma mai .env e var/.
lftp -u "$FTP_USER,$FTP_PASSWORD" "$FTP_HOST" <<LFTP
set ftp:ssl-allow yes
set net:max-retries 3
set cmd:fail-exit yes
mkdir -p -f $FTP_PATH
mirror --reverse --delete --parallel=4 \
  --exclude-glob .env --exclude var/ \
  "$BUILD" "$FTP_PATH"
mkdir -p -f $FTP_PATH/var
bye
LFTP

health_check || die "Il sito non risponde correttamente su $DEPLOY_URL/health (controllare .env sul server)."
ok "Online: $(cat "$BUILD/REVISION")"
