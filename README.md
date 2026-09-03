# P2Flux for WooCommerce

Accept USDC on Base directly to your own wallet, including subscriptions. Non-custodial: the money
moves from the customer's wallet to yours in one transaction, and nobody holds it in between.

- **One-time payments** in any store currency the plugin can convert.
- **Subscriptions**: simple fixed-price ones with the plugin's own Native Subscriptions (no other plugin), and everything WooCommerce Subscriptions offers through its adapter - priced in USD: one wallet authorization at
  signup, and the store collects each renewal on the schedule WooCommerce Subscriptions decides.
- **Test mode** on Base Sepolia with faucet money; the same contracts and the same failure modes as
  production.
- **Refunds** from your own wallet, recorded in WooCommerce only after P2Flux confirms them on chain.

The merchant-facing guide — installation, configuration, every renewal outcome, cancellation,
refunds, troubleshooting — is at [p2flux.com/docs/woocommerce.html](https://p2flux.com/docs/woocommerce.html).
`readme.txt` is the WordPress.org listing. This file is for people working on the plugin.

## Requirements

Taken from the plugin header and from the checks the gateway makes before offering itself:

| | |
|---|---|
| WordPress | 6.5 or newer (`Requires Plugins` support) |
| WooCommerce | 8.0 or newer; HPOS and the block checkout are both supported |
| PHP | 8.1 or newer, **64-bit** — the money arithmetic refuses to run on a 32-bit build rather than overflow |
| `sodium` | ships with PHP 7.2+ and with WordPress itself; stored subscription authorizations are encrypted with it |
| WooCommerce Subscriptions | only for advanced recurring features (trials, sign-up fees, variable, switching); simple fixed subscriptions need nothing extra |
| A wallet on Base | your payout address; payments arrive there directly |

## How it is put together

```
p2flux-for-woocommerce.php          bootstrap, compatibility declarations, class loading
includes/
  class-p2flux-wc-gateway.php       WC_Payment_Gateway: settings, availability, checkout, the renewal hook
  class-p2flux-wc-charger.php       the ONLY place charge() is called: lock → re-read → validate → charge → reconcile
  class-p2flux-wc-renewal.php       pure decision logic: a ChargeResult in, an order outcome out
  class-p2flux-wc-collection.php    may this plugin attempt a charge right now? (dunning vs. suspension)
  class-p2flux-wc-periods.php       the period-ownership table: one {authorization, period} funds one order
  class-p2flux-wc-auth-history.php  every authorization a subscription ever had, encrypted, with the active pointer
  class-p2flux-wc-payments.php      one-time intents, verification and settlement
  class-p2flux-wc-intents.php       intent ledger: what a late settlement means for an order
  class-p2flux-wc-jobs.php          Action Scheduler jobs: retries, reconciliation, recovery
  class-p2flux-wc-lock.php          per-subscription lock across PHP processes
  class-p2flux-wc-crypto.php        capability encryption and the key ring
  class-p2flux-wc-money.php         integer-only money: micro-USDC, conversion, periods, bounds
  class-p2flux-wc-refunds.php       one full refund per payment, merchant-sent, verified before booked
  class-p2flux-wc-ajax.php          the endpoints the pay screen and the admin box call
  class-p2flux-wc-lifecycle.php     WCS status hooks: suspension vs. renewal on-hold, cancellation
  class-p2flux-wc-account.php       My Account: restore approval, retry, revoke
  class-p2flux-wc-admin.php         order screen box, notices
  class-p2flux-wc-blocks.php        block checkout integration
  class-p2flux-wc-subscriptions.php one place that finds a subscription, whichever engine owns it
  class-p2flux-wc-native-*.php      native subscriptions: record + store, scheduler, product/cart/gateway rules,
                                    account page, admin screen, emails, privacy export/erasure
  class-p2flux-wc-calendar.php      UTC due dates from an anchor: month-end clamp, leap years
  emails/, ../templates/emails/     the two native WooCommerce emails
  vendor/p2flux/                    the PHP SDK, namespaced for this plugin, curl-free
assets/                             checkout, blocks, admin and account scripts (no build step)
tests/unit.php                      offline: money, decisions, crypto, history
tests/integration.php               offline: the cross-class invariants, against a stub API
tests/native.php                    offline: the native engine (activation window, misses, downtime, expiry)
dev/                                docker store, release checks, the dev-only period fixture
```

Two engines can own a subscription: WooCommerce Subscriptions, when installed, and the plugin's own
native subscriptions (fixed-price, non-taxable virtual products, paid through P2Flux only). They
coexist; a product belongs to one. Everything that charges, repairs, refunds or displays a
subscription works on either through `P2Flux_WC_Subscriptions`.

The design and the invariants it protects are in [`docs/architecture.md`](docs/architecture.md).
Read it before changing anything under `includes/` that touches an order's paid state.

## Developing

```bash
php tests/unit.php            # 119 checks, no WordPress needed
php tests/integration.php     # 42 checks, real charger against a fake store and a stub API
php tests/native.php          # 80 checks, the native engine against an in-memory store
bash dev/release-check.sh     # everything that must be true of a package

docker compose -f dev/docker-compose.yml up -d
dev/setup.sh 0xYourSepoliaWallet     # WordPress + WooCommerce + this plugin at http://localhost:8080
```

The docker store mounts the plugin directory, so an edit is live on the next request. It also
mounts `dev/mu-plugins/`, which contains the short-period fixture described in the architecture
document — development only, Sepolia only, excluded from any package by `.distignore` and refused
by the release check.

Recurring behaviour is currently exercised against Automattic's public `woocommerce-subscriptions-core`
v8.2.0 through `dev/tests/mu-plugins/p2flux-wcs-core-harness.php` (see `docs/architecture.md`). That is an
interim target: the current commercial WooCommerce Subscriptions release still has to be validated before
any public release.

Faucets for Base Sepolia: [ETH](https://portal.cdp.coinbase.com/products/faucet) for gas,
[USDC](https://faucet.circle.com) for payments.

### Vendoring the SDK

```bash
dev/vendor-sdk.sh ../p2flux_sdk_php v0.6.1
```

Copies the client, rewrites its namespace to `P2FluxWC\Vendor\P2Flux` so another plugin's copy of
the same SDK cannot collide with it, and deliberately omits the curl transport: WordPress.org rejects
plugins that call curl directly, and this plugin always passes `wp_remote_post`.

### Encryption key

Stored subscription authorizations are bearer capabilities and are encrypted at rest. The docker
store sets `P2FLUX_WC_ENCRYPTION_KEY` in its `wp-config.php`; a real store should too. Without it
the plugin generates a key into the options table, which protects an exported orders table or a
stolen backup but not a full database compromise. Rotation is `P2FLUX_WC_ENCRYPTION_KEY_PREVIOUS`
plus `wp p2flux rekey`.

## Related

- [P2Flux documentation](https://p2flux.com/docs/) — the protocol, the API, environments, errors.
- [P2Flux PHP SDK](https://github.com/P2Flux/sdk-php) — what `includes/vendor/p2flux/` is vendored from.
- [P2Flux contracts](https://github.com/P2Flux/contracts) — the on-chain layer.

## License

GPL-2.0-or-later. The vendored SDK is MIT; its license travels with it.
