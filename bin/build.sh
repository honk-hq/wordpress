#!/usr/bin/env bash
# Builds the plugin as it is published on WordPress.org: <out>/honk/ (the repository minus the
# paths in .distignore) and <out>/honk-<version>.zip. The version comes from honk.php.
#
#   bin/build.sh [<out-dir>]      default: build/
set -euo pipefail
cd "$(dirname "$0")/.."
version="$(sed -nE "s/^define\( 'HONK_VERSION', '([^']+)' \);$/\1/p" honk.php)"
[ -n "$version" ] || { echo "build.sh: no HONK_VERSION in honk.php" >&2; exit 1; }
out="${1:-build}"
mkdir -p "$out"
out="$(cd "$out" && pwd)"
rm -f "$out/honk-$version.zip"
mkdir -p "$out/honk"
rsync -a --delete --exclude-from=.distignore ./ "$out/honk/"
(cd "$out" && zip -qr "honk-$version.zip" honk)
echo "$out/honk-$version.zip"
