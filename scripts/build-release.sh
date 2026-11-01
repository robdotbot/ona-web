#!/usr/bin/env bash
# Builds the Windows, macOS and Linux release bundles into dist-release/:
#   ona-web-<version>-windows.zip, ona-web-<version>-macos.zip,
#   ona-web-<version>-linux.tar.gz, SHA256SUMS
# Each bundle holds the committed api/ tree, the prebuilt web UI, a compose
# file and double-click launchers. Needs git, Node 20+ (with web/node_modules
# installed: cd web && npm ci) and zip.
#
#   scripts/build-release.sh v0.2.0
set -euo pipefail

version=${1:?usage: scripts/build-release.sh <version, e.g. v0.2.0>}
if ! [[ $version =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]]; then
  echo "version must look like v1.2.3 or v1.2.3-rc.1" >&2
  exit 1
fi

root=$(git rev-parse --show-toplevel)
cd "$root"
if [ -n "$(git status --porcelain -- api web/src release)" ]; then
  echo "warning: uncommitted changes in api/, web/src or release/; the bundle uses the committed api/ tree" >&2
fi

out="$root/dist-release"
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
rm -rf "$out"
mkdir -p "$out"

echo "Building the web UI..."
(cd web && npm run build >/dev/null)

base="$stage/ona-web"
mkdir -p "$base/web"
# Committed files only: no local DB settings, logs or plugin state.
git archive HEAD api | tar -x -C "$base"
cp -R web/dist "$base/web/dist"
cp release/docker-compose.yml "$base/"
cp release/web/Dockerfile release/web/nginx.conf "$base/web/"
echo "$version" > "$base/RELEASE"

crlf() { sed -i.bak 's/\r*$/\r/' "$1" && rm -f "$1.bak"; }

desktop() {
  printf 'Docker Desktop (%s): https:\\/\\/www.docker.com\\/products\\/docker-desktop\\/\nStart Docker Desktop before starting ona-web.' "$1"
}

bundle() {
  local os=$1 requirements=$2 start=$3 stop=$4
  local dir="$stage/$os/ona-web"
  mkdir -p "$stage/$os"
  cp -R "$base" "$dir"
  cp release/"$os"/* "$dir/"
  # README placeholders (values may span lines; sed needs them escaped).
  local req=${requirements//$'\n'/\\n} st=${start//$'\n'/\\n} sp=${stop//$'\n'/\\n}
  sed -e "s/{{VERSION}}/$version/" -e "s/{{REQUIREMENTS}}/$req/" -e "s/{{START}}/$st/" -e "s/{{STOP}}/$sp/" release/README.txt > "$dir/README.txt"
  case $os in
    windows)
      crlf "$dir/README.txt"
      for f in "$dir"/*.bat; do crlf "$f"; done
      (cd "$stage/$os" && zip -qrX "$out/ona-web-$version-windows.zip" ona-web) ;;
    macos)
      chmod +x "$dir"/*.command
      (cd "$stage/$os" && zip -qrX "$out/ona-web-$version-macos.zip" ona-web) ;;
    linux)
      chmod +x "$dir"/*.sh
      tar -C "$stage/$os" --owner=0 --group=0 --numeric-owner -czf "$out/ona-web-$version-linux.tar.gz" ona-web ;;
  esac
}

bundle windows "$(desktop 'Windows 10 or 11')" 'Double-click start.bat.' 'Double-click stop.bat.'
bundle macos "$(desktop 'macOS 12 or later')" 'Double-click start.command. If macOS says it is from an unidentified developer, right-click it, choose Open, then Open again (needed once).' 'Double-click stop.command.'
bundle linux 'Docker Engine 23 or later with the Compose plugin ("docker compose"),
on any distribution: https:\/\/docs.docker.com\/engine\/install\/
Docker Desktop for Linux works too. Make sure Docker is running.' 'In a terminal in this folder run .\/start.sh (or sudo .\/start.sh if your
user is not in the docker group). It opens your browser when it can.' 'Run .\/stop.sh in this folder.'

(cd "$out" && sha256sum ./*.zip ./*.tar.gz | sed 's# \./# #' > SHA256SUMS)
echo "Built:"
ls -l "$out"
