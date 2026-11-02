#!/usr/bin/env bash
# Prints a release's description: release/notes/<version>.md (what changed,
# written by hand) followed by the download instructions.
#
#   scripts/release-notes.sh v0.5.0 > notes.md
#
# Without a notes file it prints only the downloads, with a warning on
# stderr (a GitHub Actions annotation). A notes file must not have its own
# "## Downloads" section.
set -euo pipefail

version=${1:?usage: $0 <version>}
cd "$(dirname "$0")/.."
notes_file="release/notes/$version.md"

if [[ -f $notes_file ]]; then
  if grep -q '^## Downloads' "$notes_file"; then
    echo "::error file=$notes_file::leave out the Downloads section; it is added automatically" >&2
    exit 1
  fi
  cat "$notes_file"
  echo
else
  echo "::warning::$notes_file doesn't exist, so the release description only lists the downloads" >&2
fi

cat <<EOF
## Downloads

- **Windows:** \`ona-web-$version-windows.zip\`. Unzip, then double-click \`start.bat\`.
- **macOS:** \`ona-web-$version-macos.zip\`. Unzip, then double-click \`start.command\` (the first time, right-click it and choose Open).
- **Linux** (any distribution): \`ona-web-$version-linux.tar.gz\`. Extract with \`tar -xzf\`, then run \`./start.sh\` (or \`sudo ./start.sh\` if you're not in the \`docker\` group).

Windows and macOS need [Docker Desktop](https://www.docker.com/products/docker-desktop/) running; Linux needs [Docker Engine](https://docs.docker.com/engine/install/) with the Compose plugin (or Docker Desktop for Linux). ona-web opens at http://localhost:8000 (log in as \`admin\` / \`admin\` and change the password under Admin); the classic ONA UI is at http://localhost:8080. Both ports are only reachable from your computer. See \`README.txt\` in the download for settings, upgrading and troubleshooting.

Checksums are in \`SHA256SUMS\`.
EOF
