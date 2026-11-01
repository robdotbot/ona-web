#!/usr/bin/env bash
# Starts ona-web with Docker and opens it in the browser.
#   ./start.sh            (needs permission to use Docker; otherwise: sudo ./start.sh)
set -euo pipefail
cd "$(dirname "$0")"

fail() {
  echo "$*" >&2
  if [ -t 0 ] && [ -z "${CI:-}" ]; then read -r -p "Press Enter to close." _ || true; fi
  exit 1
}

command -v docker >/dev/null 2>&1 || fail "Docker is required. Install Docker Engine with the Compose plugin: https://docs.docker.com/engine/install/"
if ! out=$(docker info 2>&1); then
  if grep -qi "permission denied" <<<"$out"; then
    fail "You don't have permission to use Docker. Run: sudo ./start.sh
(or add yourself to the docker group: sudo usermod -aG docker \$USER, then log out and in again)"
  fi
  fail "Docker is not running. Start it (e.g. sudo systemctl start docker) and run this again."
fi
docker compose version >/dev/null 2>&1 || fail "The Docker Compose plugin (docker compose) is required: https://docs.docker.com/compose/install/linux/"

if [ ! -f .env ]; then
  echo "Creating .env with random database passwords..."
  rand() { if command -v openssl >/dev/null 2>&1; then openssl rand -hex 16; else od -An -tx1 -N16 /dev/urandom | tr -d ' \n'; fi; }
  (
    umask 077
    {
      echo "ONA_DB_ROOT_PASSWORD=$(rand)"
      echo "ONA_DB_PASSWORD=$(rand)"
      echo "ONA_WEB_PORT=8000"
      echo "ONA_CLASSIC_PORT=8080"
      echo "ONA_DEFAULT_DOMAIN=example.com"
    } > .env
  )
fi
PORT=$(sed -n 's/^ONA_WEB_PORT=//p' .env | tail -n 1)
PORT=${PORT:-8000}
CLASSIC=$(sed -n 's/^ONA_CLASSIC_PORT=//p' .env | tail -n 1)
CLASSIC=${CLASSIC:-8080}

echo "Starting ona-web. The first start builds the images and sets up the database; this takes a few minutes."
docker compose up -d --build || fail "Starting the containers failed; see the messages above."

echo "Waiting for ona-web to be ready..."
for _ in $(seq 1 150); do
  if curl -fs -o /dev/null "http://localhost:${PORT}/api/v1/health" 2>/dev/null \
     || wget -q -O /dev/null "http://localhost:${PORT}/api/v1/health" 2>/dev/null; then
    echo
    echo "ona-web is running:"
    echo "  Web UI:      http://localhost:${PORT}   (first login: admin / admin - change the password under Admin)"
    echo "  Classic UI:  http://localhost:${CLASSIC}"
    echo "Run ./stop.sh to stop it. Your data is kept between runs, and it starts again with Docker after a reboot."
    if [ -z "${CI:-}" ] && { [ -n "${DISPLAY:-}" ] || [ -n "${WAYLAND_DISPLAY:-}" ]; } && command -v xdg-open >/dev/null 2>&1; then
      xdg-open "http://localhost:${PORT}/" >/dev/null 2>&1 &
    fi
    exit 0
  fi
  sleep 2
done
fail "ona-web did not become ready. Check \"docker compose logs\" in this folder."
