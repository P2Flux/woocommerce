#!/usr/bin/env bash
# Build the plugin package exactly as WordPress will receive it.
#
#   dev/build-zip.sh [output-dir]
#
# Same filtering as the release check: the working tree, minus everything in .distignore. It runs
# the release check first, because a package that fails it should not exist in the first place.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
out="${1:-$root/../}"
name="p2flux-for-woocommerce"

bash "$root/dev/release-check.sh"

staging="$(mktemp -d)"
mkdir -p "$staging/$name"
tar --exclude-vcs --exclude='./.git' -cf - -C "$root" . | tar -x -C "$staging/$name"

( cd "$staging/$name"
  while IFS= read -r pattern; do
    case "$pattern" in ''|\#*|!*) continue;; esac
    rm -rf $pattern
  done < "$root/.distignore"
  rm -rf .git .gitignore .distignore )

version="$(grep -m1 '^ \* Version:' "$root/$name.php" | awk '{print $3}')"
zip="$out/$name-$version.zip"
rm -f "$zip"
( cd "$staging" && zip -qr "$zip" "$name" )
rm -rf "$staging"

echo "$zip"
sha256sum "$zip" | awk '{print "sha256 " $1}'
