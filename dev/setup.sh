#!/usr/bin/env bash
# Bring the development store up to something you can buy from.
#
# Idempotent: run it as often as you like. It installs WordPress and WooCommerce, activates this
# plugin, points it at the test environment, and creates one $1 product and one $1/day subscription
# product (the latter only if WooCommerce Subscriptions is present, since it is a paid extension).
#
#   dev/setup.sh 0xYourSepoliaWallet
set -euo pipefail

wallet="${1:-}"
compose="docker compose -f $(dirname "$0")/docker-compose.yml"
wp() { $compose exec -T cli wp --path=/var/www/html --allow-root "$@"; }

$compose up -d
echo "waiting for WordPress…"
until wp core is-installed 2>/dev/null || wp core version >/dev/null 2>&1; do sleep 2; done

if ! wp core is-installed >/dev/null 2>&1; then
  wp core install \
    --url=http://localhost:8080 \
    --title="P2Flux dev store" \
    --admin_user=admin \
    --admin_password=admin \
    --admin_email=dev@example.test \
    --skip-email
fi

wp plugin install woocommerce --activate
wp plugin install plugin-check --activate || true
wp plugin activate p2flux-for-woocommerce

wp option update woocommerce_currency USD
wp option update woocommerce_store_address "1 Test Street"
wp option update woocommerce_default_country "US:CA"

settings='{"enabled":"yes","title":"Pay with USDC","description":"Pay in USDC from your own wallet on Base.","environment":"test","rate_mode":"auto","debug":"yes"'
if [ -n "$wallet" ]; then
  settings="$settings,\"recipient\":\"$wallet\""
fi
settings="$settings}"
wp option update woocommerce_p2flux_settings "$settings" --format=json

if ! wp post list --post_type=product --field=title | grep -q "P2Flux test item"; then
  id=$(wp post create --post_type=product --post_title="P2Flux test item" --post_status=publish --porcelain)
  wp post meta update "$id" _price 1.00
  wp post meta update "$id" _regular_price 1.00
  wp post meta update "$id" _virtual yes
  wp wc product update "$id" --type=simple --user=admin >/dev/null 2>&1 || true
fi

echo
echo "store:  http://localhost:8080"
echo "admin:  http://localhost:8080/wp-admin (admin / admin)"
[ -z "$wallet" ] && echo "NOTE: no payout wallet set. Pass one: dev/setup.sh 0xYourSepoliaWallet"
echo "faucets: https://portal.cdp.coinbase.com/products/faucet  and  https://faucet.circle.com"
