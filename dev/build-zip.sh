#!/usr/bin/env bash
# Build the plugin package exactly as WordPress will receive it.
#
#   dev/build-zip.sh [output-dir]
#
# Same filtering as the release check, from the same source: the committed tree (git archive HEAD),
# minus everything in .distignore. A dirty working tree refuses to build - a package must contain
# exactly what the release check inspected, and only what is in git.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
out="${1:-$root/../}"
name="p2flux-for-woocommerce"

bash "$root/dev/release-check.sh"

if [ -n "$(git -C "$root" status --porcelain --untracked-files=no)" ]; then
  echo "FAIL: the working tree has uncommitted changes; commit them, then build" >&2
  exit 1
fi

staging="$(mktemp -d)"
mkdir -p "$staging/$name"
git -C "$root" archive --format=tar HEAD | tar -x -C "$staging/$name"

( cd "$staging/$name"
  while IFS= read -r pattern; do
    case "$pattern" in ''|\#*|!*|\*.md) continue;; esac
    rm -rf $pattern
  done < "$root/.distignore"
  # Markdown anywhere in the tree is developer documentation; readme.txt is the only prose that ships.
  find . -name '*.md' -type f -delete
  rm -rf .git .gitignore .distignore )

version="$(grep -m1 '^ \* Version:' "$root/$name.php" | awk '{print $3}')"
zip="$out/$name-$version.zip"
rm -f "$zip"
( cd "$staging" && zip -qr "$zip" "$name" )
rm -rf "$staging"

echo "$zip"
sha256sum "$zip" | awk '{print "sha256 " $1}'
