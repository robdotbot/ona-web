#!/usr/bin/env bash
# Stops ona-web. Data is kept; it is back next time you run ./start.sh.
cd "$(dirname "$0")" || exit 1
docker compose down && echo "ona-web stopped."
