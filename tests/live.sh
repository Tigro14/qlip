#!/usr/bin/env bash
# Protocol de tests en conditions reelles sur l'instance deployee.
#
#   QLIP_LIVE_URL=https://qlip.tigro.fr ./tests/live.sh
#   QLIP_LIVE_TRANSPORT=smtp ./tests/live.sh
#
# Read-only par defaut. La partie authentifiee (compte de test, page, QR, STL, 3MF,
# threpse) ne se lance qu'avec QLIP_LIVE_WRITE=1, et ce qui est cree n'est pas supprime
# par l'app : a faire avec un compte de test, pas avec le compte administrateur.
#
# Ce script ne remplace pas ./tests/run.sh : la suite hors ligne verifie l'app, celui-ci
# verifie le serveur (reecriture, variables d'environnement, assets, dossiers proteges).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${QLIP_OUT:-/tmp/opencode/badge}"
IMAGE="${QLIP_IMAGE:-localhost/qlip-test}"

mkdir -p "$OUT"

exec podman run --rm \
    -v "$ROOT:/app:ro" \
    -v "$OUT:$OUT" \
    -w /app \
    -e QLIP_OUT="$OUT" \
    -e QLIP_LIVE_URL="${QLIP_LIVE_URL:-https://qlip.tigro.fr}" \
    -e QLIP_LIVE_EMAIL="${QLIP_LIVE_EMAIL:-}" \
    -e QLIP_LIVE_PASSWORD="${QLIP_LIVE_PASSWORD:-}" \
    -e QLIP_LIVE_WRITE="${QLIP_LIVE_WRITE:-0}" \
    -e QLIP_LIVE_TRANSPORT="${QLIP_LIVE_TRANSPORT:-outbox}" \
    "$IMAGE" python3 tests/live_test.py
