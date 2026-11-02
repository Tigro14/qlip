#!/usr/bin/env bash
# Serveur de developpement (PHP integre) : http://127.0.0.1:8080
#
#   QLIP_PORT=8080 ./bin/serve.sh
#
# Seulement pour le developpement local et les tests : en production, l'app est servie par
# Apache ou nginx avec PHP-FPM, document root = public/ (voir deploy/). Le routage est le
# meme, c'est le front controller public/index.php qui travaille dans les deux cas.
#
# En conteneur (podman, rootless) :
#   podman run --rm -p 8080:8080 -v "$PWD:/app:ro" -w /app php:8.5-cli php -S 0.0.0.0:8080 public/router.php
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${QLIP_PHP:-php}"
HOST="${QLIP_HOST:-127.0.0.1}"
PORT="${QLIP_PORT:-8080}"

exec "$PHP" -S "$HOST:$PORT" "$ROOT/public/router.php"
