# Changelog

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
