#!/usr/bin/env bash
# Suite de tests dans podman (rootless, sans sudo) : PHP + contre-verifications Python.
#
#   ./tests/run.sh                 # toute la suite
#   ./tests/run.sh tests/stl_dump.php   # un seul script PHP
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="${QLIP_OUT:-/tmp/opencode/badge}"
IMAGE="${QLIP_IMAGE:-localhost/qlip-test}"

mkdir -p "$OUT"

PODMAN=(podman run --rm
    -v "$ROOT:/app:ro"
    -v "$OUT:$OUT"
    -w /app
    -e QLIP_PHP=php
    -e QLIP_QR_JSON="$OUT/php_qr.json"
    -e QLIP_OUT="$OUT"
    "$IMAGE")

if [ "$#" -gt 0 ]; then
    exec "${PODMAN[@]}" php "$1"
fi

"${PODMAN[@]}" bash -eu -c '
    find src tests -name "*.php" -exec php -l {} ";" > /dev/null
    echo "lint                  : OK"

    echo
    echo "== QR : contre-verification qrcode + zxing-cpp =="
    python3 tests/qr_compare.py

    echo
    echo "== QR : fuzz (textes aleatoires) =="
    python3 tests/qr_fuzz.py

    echo
    echo "== STL / 3MF =="
    php tests/stl_dump.php
    python3 tests/stl_check.py

    echo
    echo "== WebAuthn (faux authenticateur) =="
    python3 tests/webauthn_fixture.py > "$QLIP_OUT/webauthn.json"
    QLIP_WEBAUTHN_JSON="$QLIP_OUT/webauthn.json" php tests/webauthn_check.php

    echo
    echo "== Application (routes, quotas, exports) =="
    QLIP_WEBAUTHN_JSON="$QLIP_OUT/webauthn.json" php tests/app_test.php

    echo
    echo "== HTTP (front controller, WebAuthn par HTTP) =="
    python3 tests/http_test.py
'
