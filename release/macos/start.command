#!/bin/bash
# Starts ona-web with Docker Desktop and opens it in the browser.
set -euo pipefail
cd "$(dirname "$0")"

pause_exit() { echo; read -r -p "Press Enter to close." _ || true; exit "${1:-1}"; }

if ! command -v docker >/dev/null 2>&1; then
  for d in /usr/local/bin /opt/homebrew/bin /Applications/Docker.app/Contents/Resources/bin; do
    [ -x "$d/docker" ] && PATH="$d:$PATH"
  done
fi
if ! command -v docker >/dev/null 2>&1; then
  echo "Docker Desktop is required. Install it from https://www.docker.com/products/docker-desktop/ and run this again."
  open "https://www.docker.com/products/docker-desktop/" || true
  pause_exit 1
fi
if ! docker info >/dev/null 2>&1; then
  echo "Docker Desktop is not running. Start it, wait until it says \"Engine running\", then run this again."
  open -a Docker 2>/dev/null || true
  pause_exit 1
fi

if [ ! -f .env ]; then
  echo "Creating .env with random database passwords..."
  umask 077
  {
    echo "ONA_DB_ROOT_PASSWORD=$(openssl rand -hex 16)"
    echo "ONA_DB_PASSWORD=$(openssl rand -hex 16)"
    echo "ONA_WEB_PORT=8000"
    echo "ONA_CLASSIC_PORT=8080"
    echo "ONA_DEFAULT_DOMAIN=example.com"
  } > .env
fi
PORT=$(sed -n 's/^ONA_WEB_PORT=//p' .env | tail -n 1)
PORT=${PORT:-8000}

echo "Starting ona-web. The first start builds the images and sets up the database; this takes a few minutes."
if ! docker compose up -d --build; then
  echo "Starting the containers failed; see the messages above."
  pause_exit 1
fi

echo "Waiting for ona-web to be ready..."
for _ in $(seq 1 150); do
  if curl -fs -o /dev/null "http://localhost:${PORT}/api/v1/health"; then
    echo
    echo "ona-web is running:"
    echo "  Web UI:      http://localhost:${PORT}   (first login: admin / admin - change the password under Admin)"
    echo "  Classic UI:  http://localhost:8080"
    echo "Run stop.command to stop it. Your data is kept between runs."
    open "http://localhost:${PORT}/"
    exit 0
  fi
  sleep 2
done
echo "ona-web did not become ready. Check \"docker compose logs\" in this folder."
pause_exit 1
