#!/usr/bin/env bash
# Builds the Windows and macOS release bundles into dist-release/:
#   ona-web-<version>-windows.zip, ona-web-<version>-macos.zip, SHA256SUMS
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

bundle() {
  local os=$1 start=$2 stop=$3
  local dir="$stage/$os/ona-web"
  mkdir -p "$stage/$os"
  cp -R "$base" "$dir"
  cp release/"$os"/* "$dir/"
  sed -e "s/{{VERSION}}/$version/" -e "s/{{START}}/$start/" -e "s/{{STOP}}/$stop/" release/README.txt > "$dir/README.txt"
  if [ "$os" = windows ]; then
    crlf "$dir/README.txt"
    for f in "$dir"/*.bat; do crlf "$f"; done
  else
    chmod +x "$dir"/*.command
  fi
  (cd "$stage/$os" && zip -qrX "$out/ona-web-$version-$os.zip" ona-web)
}

bundle windows 'Double-click start.bat.' 'Double-click stop.bat.'
bundle macos 'Double-click start.command. If macOS says it is from an unidentified developer, right-click it, choose Open, then Open again (needed once).' 'Double-click stop.command.'

(cd "$out" && sha256sum ./*.zip | sed 's# \./# #' > SHA256SUMS)
echo "Built:"
ls -l "$out"
