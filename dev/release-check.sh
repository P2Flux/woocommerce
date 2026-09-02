#!/usr/bin/env bash
# What must be true of the zip before it is submitted anywhere.
#
# Each check is here because the alternative is a store running something it should not: a
# development fixture that shortens billing periods, a curl call WordPress.org rejects, a
# capability written to a log.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
fail=0
note() { echo "FAIL: $1"; fail=1; }

# The fixture can change what customers are charged. It must not be shippable.
grep -rl "P2FLUX_WC_DEV_SHORT_PERIODS" "$root/includes" "$root/assets" "$root/p2flux-for-woocommerce.php" 2>/dev/null \
  | grep -q . && note "the development short-period fixture is referenced from shipped code"

# WordPress.org rejects plugins that call curl directly; the vendored SDK ships without it.
grep -rn "curl_" "$root/includes" "$root/p2flux-for-woocommerce.php" 2>/dev/null | grep -v "^Binary" | grep -q . \
  && note "shipped code calls curl directly"

# The SDK must be namespaced, or another plugin's copy could win.
grep -q "namespace P2FluxWC\\\\Vendor\\\\P2Flux;" "$root/includes/vendor/p2flux/P2FluxClient.php" \
  || note "the vendored SDK is not namespaced for this plugin"

# Syntax, on every file that ships.
while IFS= read -r file; do
  php -l "$file" >/dev/null || note "syntax error in $file"
done < <(find "$root/includes" "$root/p2flux-for-woocommerce.php" "$root/uninstall.php" -name '*.php')

# Documentation must not teach the wrong thing.
# The capability prefix must never appear in merchant-facing text (an example that shows one is an
# example that gets pasted), and the development period fixture must never be described as a feature.
grep -q "p2s2\." "$root/readme.txt" && note "readme.txt exposes a capability prefix"
grep -qi "60.second\|short.period\|P2FLUX_WC_DEV" "$root/readme.txt" && note "readme.txt documents the development fixture"
# The vendored SDK must be the release both SDKs share.
grep -q "v0.6.0" "$root/includes/vendor/p2flux/VENDORED.md" || note "vendored SDK is not v0.6.0"
# The bundled zip: build it the way the release does, and look inside.
tmp="$(mktemp -d)"
( cd "$root" && git archive --format=tar --prefix=p2flux-for-woocommerce/ HEAD | tar -x -C "$tmp" ) 2>/dev/null
if [ -d "$tmp/p2flux-for-woocommerce" ]; then
  # .distignore is what `wp dist-archive` honours; apply it the same way.
  while IFS= read -r pattern; do
    case "$pattern" in ''|\#*|!*) continue;; esac
    rm -rf "$tmp/p2flux-for-woocommerce/$pattern"
  done < "$root/.distignore"
  grep -rl "p2flux-test-fixture\|P2FLUX_WC_DEV_SHORT_PERIODS" "$tmp/p2flux-for-woocommerce" 2>/dev/null | grep -v architecture.md | grep -q . && note "the release archive contains the development fixture"
  grep -rn "curl_" "$tmp/p2flux-for-woocommerce" --include='*.php' 2>/dev/null | grep -q . && note "the release archive calls curl"
fi
rm -rf "$tmp"

# The offline suites.
php "$root/tests/unit.php" >/dev/null || note "the unit suite does not pass"
php "$root/tests/integration.php" >/dev/null || note "the invariant suite does not pass"

[ "$fail" -eq 0 ] && echo "release checks passed"
exit "$fail"
