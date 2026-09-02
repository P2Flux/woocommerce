#!/usr/bin/env bash
# Vendor the P2Flux PHP SDK into this plugin.
#
# Two things this does that a plain copy does not:
#
#   The namespace is rewritten to P2FluxWC\Vendor\P2Flux. Another plugin on the same site may bundle
#   its own copy of this SDK, and whichever loaded first would otherwise win silently - a version
#   skew nobody can see, in the code that moves money. class_exists() guards do not solve that; they
#   ARE that.
#
#   CurlTransport is deliberately not copied. WordPress.org rejects plugins that call curl directly,
#   and this plugin always passes wp_remote_post, so the file would be dead weight that fails review.
#
# Usage: dev/vendor-sdk.sh ../p2flux_sdk_php [tag]
set -euo pipefail

src="${1:-../p2flux_sdk_php}"
tag="${2:-}"
dest="$(cd "$(dirname "$0")/.." && pwd)/includes/vendor/p2flux"

[ -d "$src/src" ] || { echo "not an SDK checkout: $src" >&2; exit 1; }
if [ -n "$tag" ]; then git -C "$src" checkout -q "$tag"; fi
version="$(git -C "$src" describe --tags --always)"

mkdir -p "$dest"
for file in P2FluxException.php ChargeResult.php P2FluxClient.php; do
  sed 's/^namespace P2Flux;$/namespace P2FluxWC\\Vendor\\P2Flux;/' "$src/src/$file" > "$dest/$file"
done
cp "$src/LICENSE" "$dest/LICENSE"

if grep -rn 'curl_' "$dest"/*.php; then
  echo "vendored SDK still calls curl - WordPress.org will reject this" >&2
  exit 1
fi

cat > "$dest/VENDORED.md" <<NOTE
# Vendored: p2flux/p2flux-php

Source: https://github.com/P2Flux/sdk-php at \`$version\`, copied by \`dev/vendor-sdk.sh\`.

Two edits, both mechanical:

- \`namespace P2Flux\` becomes \`namespace P2FluxWC\Vendor\P2Flux\`, so this copy cannot collide with
  another plugin's copy of the same SDK.
- \`CurlTransport.php\` is not copied. This plugin always supplies \`wp_remote_post\`, and
  WordPress.org rejects plugins that call curl directly.

Do not edit these files. Fix the SDK upstream, tag it, and re-run the script.
NOTE

echo "vendored $version -> $dest"
