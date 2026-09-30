#!/usr/bin/env bash
#
# Versione del backend: MAJOR.MINOR dal file VERSION, poi il numero di commit.
#
#   deploy/version.sh          # stampa: 1.0.57 e21dfd2
#
# MAJOR.MINOR si cambiano a mano nel file VERSION. Serve la storia completa di
# git (in CI: fetch-depth: 0).

set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

MAJOR_MINOR="$(tr -d '[:space:]' < "$ROOT/VERSION")"
[[ "$MAJOR_MINOR" =~ ^[0-9]+\.[0-9]+$ ]] || { echo "VERSION deve contenere MAJOR.MINOR, es. 1.0" >&2; exit 1; }
if [[ "$(git -C "$ROOT" rev-parse --is-shallow-repository)" == "true" ]]; then
  echo "Clone parziale: il numero di commit sarebbe sbagliato (git fetch --unshallow)." >&2
  exit 1
fi
echo "$MAJOR_MINOR.$(git -C "$ROOT" rev-list --count HEAD) $(git -C "$ROOT" rev-parse --short=7 HEAD)"
