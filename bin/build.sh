#!/usr/bin/env bash
# Builds the plugin as it is published on WordPress.org (slug honk-me): <out>/honk-me/ (the
# repository minus the paths in .distignore) and <out>/honk-me-<version>.zip. The version comes
# from honk-me.php.
#
#   bin/build.sh [<out-dir>]      default: build/
set -euo pipefail
cd "$(dirname "$0")/.."
slug=honk-me
version="$(sed -nE "s/^define\( 'HONK_VERSION', '([^']+)' \);$/\1/p" "$slug.php")"
[ -n "$version" ] || { echo "build.sh: no HONK_VERSION in $slug.php" >&2; exit 1; }
out="${1:-build}"
mkdir -p "$out"
out="$(cd "$out" && pwd)"
rm -f "$out/$slug-$version.zip"
mkdir -p "$out/$slug"
rsync -a --delete --exclude-from=.distignore ./ "$out/$slug/"
(cd "$out" && zip -qr "$slug-$version.zip" "$slug")
echo "$out/$slug-$version.zip"
