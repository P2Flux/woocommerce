# Architecture and invariants

What this plugin promises, where each promise is enforced, and which code you must not "simplify".
Every item here exists because the alternative is a customer paid twice, an order paid by nobody, or
a payment nobody can refund.

## The trust boundary

**A browser message is a claim. The server's verification is the verdict.**

The hosted checkout posts `p2flux.payment.completed { tx_hash }` and
`p2flux.subscription.created { subscription }` to the pay screen. Neither pays anything:

- `P2Flux_WC_Ajax::verify()` hands the hash to `P2Flux_WC_Payments::verify()`, which asks the P2Flux
  API to verify it against the chain for the exact intent this order minted. Only `valid: true` pays.
- `P2Flux_WC_Ajax::activate()` hands the capability to `P2Flux_WC_Activation::store()`, which reads
  the subscription's own terms from the chain via `status()` and compares salt, amount, recipient and
  period to the setup this order created before storing anything.

There is no code path from a `postMessage` to `payment_complete()` that does not pass through the API.

## Recurring ownership: one period funds one order

The contract allows one charge per billing period. It knows nothing about WooCommerce, which can end
up with two renewal orders for one period (an operator's retry, a plugin conflict). The protocol
would answer `ALREADY_CHARGED` to the second, and it would look paid.

`P2Flux_WC_Periods` is a dedicated table, `{prefix}p2flux_wc_periods`, with
`UNIQUE KEY (auth_id, period_index)`. The charger claims the period under the subscription lock
**before** sending the charge, so a lost response cannot leave it unclaimed. A second order finds the
row owned by someone else and is refused before any request is made (`PERIOD_CONFLICT`).

Rows are never deleted. A period settled years ago must still refuse a second claim and still name
the order a refund belongs to. `uninstall.php` keeps the table unless `P2FLUX_WC_REMOVE_DATA` is set.

A row is not payment proof. It records who may be paid by a settlement, never that one happened.

## `ALREADY_CHARGED` and `CONFIRMING` never pay an order

`P2Flux_WC_Renewal::decide()` pays an order only for `CHARGED` with a transaction hash.

- `ALREADY_CHARGED` proves the period was collected and names no transaction. An order paid without
  one cannot be attributed, audited or refunded (refunds start from the settlement). The order goes to
  **reconciling**: `P2Flux_WC_Jobs::reconcile()` calls `recoverCharge(auth, period_index, hint)`,
  validates the returned settlement against the order (`subscription_id`, `period_index`,
  `recipient`, `amount_units`) and only then calls `payment_complete()`. A settlement that does not
  match is flagged (`_p2flux_recover_mismatch`) and never applied.
- `CONFIRMING` is on chain but not settled. The order stays pending, nothing is emailed, and only
  reconciliation jobs run — never a second `/v1/charges` for that period.

Both are pinned by `tests/integration.php`.

## The charge path

`P2Flux_WC_Charger::collect()` is the only caller of `charge()`. Every entry point — a scheduled
renewal, a dunning retry, "Try the payment again", an allowance just restored, the first charge at
signup — goes through it:

```
acquire the subscription lock (or return 'busy' and let the caller reschedule; never wait)
re-read the subscription and the order from storage
re-check: paid? manually paid? collection state permits? status permits? authorization decryptable?
re-check the terms: Woo's amount and period against what the customer signed
claim the period (table above)
record the attempt time (the recovery hint)
send exactly one charge
re-read the subscription and the order AGAIN
verify the lock lease is still ours
apply the financial result; apply the lifecycle result only if the current state allows it
release
```

The lock (`P2Flux_WC_Lock`) is a compare-and-set on an options-table row with a 120-second lease.
WooCommerce and Action Scheduler expose no named cross-process lock, and MySQL `GET_LOCK` is
unavailable on several hosted stacks. The lock protects WooCommerce's own consistency; the chain's
one-charge-per-period rule remains the last line of defence, so a lock failure costs a duplicate
*request*, never a duplicate *payment*.

## The cancellation race

WCS can persist a status change without our lock. The guarantee is therefore:

- Cancellation or suspension committed **before** the worker's locked re-read → zero `/v1/charges`.
- The worker passed validation and the request is in flight when cancellation commits → that one
  already-authorized period **may settle**. The settlement is recorded on the order (it is real
  money), the cancellation is never overwritten, and no later period is ever collected.
- A worker whose lease expired mid-request records a proven settlement only if the order is still
  unpaid and the period row is still its own, and writes **no lifecycle state at all**.

`P2Flux_WC_Charger::reconcile()` implements this; the "stale worker" and "cancelled during flight"
cases are in `tests/integration.php`.

## Dunning is not suspension

WCS sets a subscription on-hold for two opposite reasons: itself, moments before asking the gateway
to collect a renewal ("collect now"), and a human suspending it ("never collect"). Status alone
cannot tell them apart.

`P2Flux_WC_Collection` stores the plugin's own reason: `normal`, `dunning` (we put it on hold, with
bounded retries for one named renewal), `suspended`, `reauth_required`, `cancelled`.
`P2Flux_WC_Lifecycle::on_hold()` distinguishes the transitions: inside a scheduled renewal request,
or flagged as our own, it is ignored; otherwise it is a suspension, and every queued job for the
subscription is dropped. `may_charge()` refuses `suspended` and `cancelled` unconditionally, and lets
a dunning retry collect only the renewal it was set for.

## Authorization history

`_p2flux_authorizations` on the WC subscription holds every authorization the subscription ever had:
id (the on-chain subscription id), the encrypted capability, environment, recipient, units, period,
start, salt, status (`active | superseded | revoked | expired`). `_p2flux_active_auth_id` points at
the one renewals charge. Every paid order records `_p2flux_auth_id` and `_p2flux_period_index`.

A refund resolves the capability from the **order's** authorization id, never from the active
pointer — a customer may have re-authorized since, and the settlement being refunded was collected
by the old one. `tests/unit.php` proves an old order still resolves its own capability after a
replacement becomes active.

A replacement authorization for an existing subscription inherits that subscription's stored
recipient and environment. Global settings apply only to new payments and newly created
subscriptions.

## Environment and recipient are historical

`_p2flux_env` and `_p2flux_recipient` are written on every order and subscription when it is created
and never rewritten. `P2Flux_WC_Client::for_object()` builds the client from the stored environment.
A merchant who switches the gateway from test to live still has test orders, and those must keep
talking to the API that issued their tokens; a merchant who changes the payout wallet does not
change what a customer already authorized.

## Secrets

- The capability is encrypted with libsodium `secretbox` (`P2Flux_WC_Crypto`). Key order:
  `P2FLUX_WC_ENCRYPTION_KEY`, then `P2FLUX_WC_ENCRYPTION_KEY_PREVIOUS`, then the option
  `p2flux_wc_key` (generated once, never autoloaded). Ciphertext is stamped with a key id so
  rotation is legible; `wp p2flux rekey` re-encrypts everything with the current key. A value no key
  opens fails closed: the renewal is refused, the subscription is marked `reauth_required`, a note
  and a log line say why. Nothing is ever charged with a broken reference.
- `P2Flux_WC_Logger::redact()` removes every P2Flux token prefix from anything logged.
- The capability appears in plaintext exactly once on the wire: the `p2flux_activate` request that
  carries it from the pay screen to the server, over a same-origin POST with a nonce and the order
  key. No response ever echoes it. No order note, no page, no URL ever carries it.

## Action Scheduler jobs

All in group `p2flux`, all per order, all bounded, all re-reading state before acting.

| Hook | Purpose |
|---|---|
| `p2flux_wc_recharge` | Try a renewal charge again (transient failure, dunning, `NOT_DUE`). Ladder and ceilings in `P2Flux_WC_Renewal`. |
| `p2flux_wc_reconcile` | Recover the exact settlement behind `ALREADY_CHARGED` / `CONFIRMING` and pay the order. Bounded; flags the order for a human when exhausted. |
| `p2flux_wc_recover_order` | Ask whether a one-time payment landed after the checkout window died. Five fixed offsets from intent creation, then stops. |
| `p2flux_wc_sweep` | Daily reconciliation only: schedules the two jobs above for pending orders whose queue was lost (a restored backup). Not the primary mechanism. |

`P2Flux_WC_Jobs::unschedule_subscription()` drops everything for a subscription on cancellation,
suspension and manual payment.

## Manual payment of a renewal

A renewal that could not be collected can be paid from the order-pay screen as a one-off. When that
settles, `P2Flux_WC_Payments::stop_recurring_collection()` marks the period `manually_satisfied`,
drops every queued job and sets `_p2flux_manual_paid`, and the charger refuses the order from then
on. The period stays uncollected on chain, which costs nothing — there is no catch-up billing, and
the next renewal falls in a later period.

## Money

Integers only, everywhere (`P2Flux_WC_Money`). Micro-USDC has six decimals; conversion is
`round_half_up(amount_scaled * 1e6 / rate_scaled)` on scaled integers. The intermediate reaches 1e18,
which fits 64-bit PHP and not 32-bit — `supported_platform()` makes the gateway unavailable rather
than overflow.

A month maps to 28 days and a year to 365, shorter than the calendar interval WCS uses. The contract's
periods are fixed-length; an authorization whose period is never longer than the interval guarantees
each renewal lands in a fresh period (at worst skipping one, which is free). The reverse would make
renewals arrive early and answer `NOT_DUE` forever.

## The development-only short-period fixture

`dev/mu-plugins/p2flux-test-fixture.php` defines `P2FLUX_WC_DEV_SHORT_PERIODS` and filters
`p2flux_wc_period_seconds` to 60 seconds, so two real renewals can be watched in two minutes on Base
Sepolia. `P2Flux_WC_Gateway::billing_period()` honours the filter only in the **test** environment;
the API refuses periods under an hour anywhere but Base Sepolia. The fixture is excluded from any
package by `.distignore`, and `dev/release-check.sh` fails the build if shipped code references it.
It must never be documented as a merchant feature.
