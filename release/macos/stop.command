#!/bin/bash
# Stops ona-web. Data is kept; it is back next time you run start.command.
cd "$(dirname "$0")" || exit 1
for d in /usr/local/bin /opt/homebrew/bin /Applications/Docker.app/Contents/Resources/bin; do
  [ -x "$d/docker" ] && PATH="$d:$PATH"
done
docker compose down
echo "ona-web stopped."
