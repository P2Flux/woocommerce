# Changelog

## 1.1.0 — unreleased

- No-ETH checkout: a one-time payment can be minted with its network fee paid in USDC
  (`gas_payment_mode: payment_token`), so the customer needs no ETH. The merchant receives the
  price less 1% less a fixed 0.10 USDC. Setting `sponsored`: on for fresh installs, written `no`
  for installs upgraded from 1.0.0. Offered only when `/v1/capabilities` says sponsored one-time
  USDC payments are supported (5 s timeout, cached 1 h, 5 min on failure) and the amount covers
  the fees; a refused sponsored create falls back to native once.
- The pay screen offers "I have ETH on Base — pay the network fee in ETH", and the way back after
  a switch (`wc_ajax_p2flux_mode`). A switch first asks whether the current intent was paid, and
  reuses an unexpired intent of the requested mode.
- A second settlement on an already-paid order is recorded (`_p2flux_unexpected_payment`,
  `duplicate`) with a note to refund it; `payment_complete()` is never called twice. Sibling
  intents are checked once after they can no longer be started.
- Verification uses the intent the browser opened, only if it is in the order's own ledger.
- Minting is serialised per order under the intent lock.
- Settlement stores `_p2flux_gas_mode` and a note with what the merchant received.
- Returning to the pay screen checks for an earlier payment before offering to pay.
- Admin warning when Action Scheduler is missing or a `p2flux` job is more than two hours overdue.
- Uninstall also removes the capabilities and mode-switch transients, by exact name.
- Vendored `p2flux/sdk-php` v0.7.3, read from the tag itself.

## 1.0.0 — 2026-09-05

First release.

- One-time USDC payments on Base, in the classic and the block checkout.
- Subscriptions via WooCommerce Subscriptions: authorization, first charge, renewals, bounded
  dunning, cancellation and customer-side revocation.
- Recovery for payments whose checkout window died before the store heard about them, and for
  renewal charges whose response was lost.
- Full refunds from the merchant's own wallet, recorded in WooCommerce only after P2Flux confirms
  the transfer on chain.
- Test mode on Base Sepolia; orders keep the environment they were created in.
