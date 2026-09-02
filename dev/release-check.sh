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

# The offline suites.
php "$root/tests/unit.php" >/dev/null || note "the unit suite does not pass"
php "$root/tests/integration.php" >/dev/null || note "the invariant suite does not pass"

[ "$fail" -eq 0 ] && echo "release checks passed"
exit "$fail"
