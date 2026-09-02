=== P2Flux for WooCommerce ===
Contributors: p2flux
Tags: woocommerce, payments, usdc, crypto, subscriptions
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept USDC on Base directly to your own wallet, including subscriptions. Non-custodial: nobody holds your money but you.

== Description ==

P2Flux settles payments from the customer's wallet to yours in a single on-chain transaction. There
is no account to open, no balance to withdraw and no third party holding your revenue: the money
never sits anywhere on its way to you.

Subscriptions work the same way. The customer signs one authorization in their wallet, and after
that WooCommerce Subscriptions decides when a renewal is due and this plugin collects it. The
customer's wallet is never asked again, and the amount, the wallet it pays and the billing period
are fixed by what they signed - a merchant cannot change them afterwards, which is the point.

= What you need =

* A wallet address on Base that you control. Payments arrive there directly.
* Prices in USD for subscriptions. One-time payments work in any currency the plugin can convert.
* WooCommerce Subscriptions, if you want recurring payments.

= Fees =

P2Flux takes 1% of a one-time payment, and 2% plus a fixed 0.10 USDC network fee on a recurring
charge. The customer pays the gas on a one-time payment; on a renewal the gas is reimbursed out of
the charge, capped by what the customer signed.

= Test it first =

The plugin ships with a test mode that settles on Base Sepolia. It behaves exactly like the live
one - same contracts, same signatures, same failure modes - with faucet money instead of real USDC.
Orders keep the environment they were created in, so switching to live later never disturbs them.

== External services ==

This plugin sends data to two external services.

**P2Flux** (https://api.p2flux.com, https://api-test.p2flux.com, and the hosted checkout at
https://pay.p2flux.com / https://pay-test.p2flux.com)

Used to create payment instructions, to verify payments against the Base blockchain, and to collect
subscription renewals. Sent: your payout wallet address, the amount in USDC, the billing period for
a subscription, and transaction hashes for verification. Your customers' browsers open the hosted
checkout window, which sees only the amount, the recipient and the network - never the product, the
customer or the order.

Terms of service: https://p2flux.com/terms. Privacy policy: https://p2flux.com/privacy.

**Coinbase exchange rates** (https://api.coinbase.com/v2/exchange-rates)

Used only when your store's currency is not USD, to convert a price into USDC. Sent: nothing but the
request itself; the response is cached for an hour. No order, customer or store data is included.

Terms of service: https://www.coinbase.com/legal/user_agreement. Privacy policy:
https://www.coinbase.com/legal/privacy.

== Documentation ==

The full guide - installation, every renewal outcome, cancellation, refunds, recovery,
troubleshooting - is at https://p2flux.com/docs/woocommerce.html. Developer documentation and the
source are at https://github.com/P2Flux/woocommerce.

== Frequently Asked Questions ==

= Where does the money go? =

Straight to the wallet you configure, in the same transaction the customer sends. P2Flux never holds
it and cannot move it.

= What happens if a customer closes the payment window? =

Nothing is lost. The order keeps its payment instruction, the plugin checks whether the payment
arrived, and the customer's own "Check payment" button asks the same question. It never offers to
pay again while a payment might already exist.

= Can I refund? =

Yes, in full, from your own wallet. The order screen has a P2Flux box that prepares the refund and
opens your wallet; WooCommerce records the refund once P2Flux confirms the transfer on chain.
WooCommerce's normal refund button is not offered, because no server can send money out of your
wallet - only you can.

= A renewal failed. What now? =

It depends on why, and the plugin says so on the order. A wallet that is short of USDC is retried
daily for three days and the customer is told to top up. An approval that ran short cannot be fixed
by retrying, so the customer gets a "Restore USDC approval" button on their account page. A customer
who revoked their authorization has their subscription cancelled.

= What if the customer cancels? =

WooCommerce stops collecting immediately - that part is entirely in the store's hands. The standing
permission in the customer's wallet is theirs to remove, and their account page offers it.

= Do free trials work? =

Not in this version. A subscription's first charge and its renewals are one signed amount with one
start date, so a trial would need protocol support that does not exist yet. Sign-up fees and a first
payment that differs from the recurring amount are unsupported for the same reason, and the gateway
refuses those carts rather than selling a subscription it cannot renew.

= Can I price subscriptions in euros? =

Not in this version. A recurring authorization fixes one USDC amount for its whole life, so a
subscription priced in another currency would drift away from its own price as the rate moved -
and neither you nor the customer would have agreed to what it became. One-time payments convert
normally.

= Where are subscription authorizations stored? =

Encrypted, in your own database, and never in a log, a page or a URL. For stronger protection add a
key to `wp-config.php`:

`define( 'P2FLUX_WC_ENCRYPTION_KEY', 'your-base64-key' );`

Without it the plugin generates a key and stores it in the options table, which protects an exported
orders table or a stolen backup but not a full database compromise. To rotate: set the new key,
keep the old one in `P2FLUX_WC_ENCRYPTION_KEY_PREVIOUS`, and every stored authorization keeps
working while new ones use the new key.

== Screenshots ==

1. The payment method at checkout.
2. Paying from a wallet in the hosted checkout window.
3. The P2Flux box on an order, with the settlement transaction and the refund control.
4. Gateway settings, including the test and live environments.

== Changelog ==

= 1.0.0 =
* One-time USDC payments on Base, classic and block checkout.
* Subscriptions through WooCommerce Subscriptions: authorization, first charge, renewals, dunning.
* Payment recovery when a checkout window dies before the store hears about the payment.
* Full refunds from the merchant's own wallet, recorded in WooCommerce only after confirmation.
* Test mode on Base Sepolia.

== Upgrade notice ==

= 1.0.0 =
First release.
