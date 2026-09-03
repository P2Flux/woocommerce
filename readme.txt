=== P2Flux for WooCommerce ===
Contributors: p2flux
Tags: woocommerce, payments, usdc, crypto, subscriptions
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 8.0
WC tested up to: 11.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept USDC on Base directly to your own wallet, including subscriptions. Non-custodial: nobody holds your money but you.

== Description ==

P2Flux settles payments from the customer's wallet to yours in a single on-chain transaction. There
is no account to open, no balance to withdraw and no third party holding your revenue: the money
never sits anywhere on its way to you.

Subscriptions work the same way. The customer signs one authorization in their wallet, and after
that the store decides when a renewal is due and this plugin collects it. The customer's wallet is
never asked again, and the amount, the wallet it pays and the billing period are fixed by what they
signed - a merchant cannot change them afterwards, which is the point.

= Native Simple Subscriptions =

Simple recurring products can use P2Flux Native Subscriptions without any separate subscription
extension: WooCommerce plus this plugin is enough. Tick "P2Flux recurring subscription" on an
ordinary simple product, choose daily, weekly, monthly or yearly, and the product's price becomes
the recurring amount. The plugin creates a renewal order each period, collects it, and shows the
subscription under My Account → USDC subscriptions and WooCommerce → P2Flux Subscriptions.

Native v1 supports fixed-price, non-taxable virtual subscription products, priced in US dollars,
bought one at a time and on their own. Taxes, coupons, shipping, free trials, sign-up fees and
variable subscriptions are not supported in native mode. Native subscription products are paid
through P2Flux only; other WooCommerce gateways cannot be used for them. Normal WooCommerce
products continue to use your existing payment gateways - the P2Flux-only requirement applies only
to products using P2Flux Native Subscriptions.

If you already run WooCommerce Subscriptions, it keeps working as before and the two coexist; a
product belongs to one of them, never both.

= What you need =

* A wallet address on Base that you control. Payments arrive there directly.
* Prices in USD for subscriptions. One-time payments work in any currency the plugin can convert.
* For advanced subscription features (trials, sign-up fees, variable subscriptions, switching),
  WooCommerce Subscriptions. Simple fixed subscriptions need nothing extra.

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

== Privacy ==

The plugin stores no name, address, email, wallet address, IP address or user agent of its own;
those stay in WooCommerce's customer and order records and follow WooCommerce's own personal-data
export and erasure. What the plugin stores is the financial history of each payment and
subscription: amounts, billing periods, public blockchain identifiers (an authorization id and
transaction hashes), the store's own payout wallet, and - for subscriptions - the customer's
recurring authorization, encrypted, which the store needs to refund a payment and the customer to
revoke it. Native subscriptions live in a table of their own, linked to the customer account by id.

Personal-data requests: the plugin registers an exporter (what each subscription record says) and
an eraser. Erasure unlinks the subscription from the customer, cancels it so a wallet whose owner
the store no longer knows is never charged, and keeps the financial rows; deleting the customer's
WordPress account does the same. Financial records are retained because a payment that happened on a
public blockchain is not made private by deleting the store's only record of it, and the encrypted
authorization is what still allows a refund. Uninstalling keeps them too unless the store opts into
destructive removal.

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
arrived, and the customer's own "I already paid - check my payment" link asks the same question. It
never offers to pay again while a payment might already exist.

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

= I changed a subscription's price. What happens? =

Renewals stop, with a note on the order, because the customer's wallet authorized the old amount and
the plugin never charges an old authorization for new terms. The customer's account page explains
the new amount and offers "Re-authorize": one signature in their wallet, and the outstanding renewal
is collected straight away. The old authorization stays on record so its payments remain refundable.

= What if the customer cancels? =

WooCommerce stops collecting immediately - that part is entirely in the store's hands. The standing
permission in the customer's wallet is theirs to remove, and their account page offers it.

= Do free trials work? =

Not in this version. A subscription's first charge and its renewals are one signed amount with one
start date, so a trial would need protocol support that does not exist yet. Sign-up fees, a second
subscription, or anything else in the cart alongside the subscription - all of which make the first
payment differ from the renewals - are unsupported for the same reason. P2Flux is simply not offered
for such a cart, rather than selling a subscription it cannot renew; the customer pays with another
method, or removes the extra item.

= Why does the wallet approve unlimited USDC at signup? =

Because the subscription has no end and the approval can only ever be used for the terms the
customer signed: it is granted to the P2Flux recurring contract, which moves nothing a signed
authorization does not permit. If you would rather bound it, the "USDC approval for subscriptions"
setting asks the wallet for 12, 24 or 36 billing periods' worth instead; when that runs out the
customer's account page offers "Restore USDC approval" and the renewal is collected right after.

= Can I price subscriptions in euros? =

Not in this version. A recurring authorization fixes one USDC amount for its whole life, so a
subscription priced in another currency would drift away from its own price as the rate moved -
and neither you nor the customer would have agreed to what it became. One-time payments convert
normally.

= Do I need WooCommerce Subscriptions? =

Not for simple fixed subscriptions. P2Flux Native Subscriptions handle a fixed price, one product,
one interval, with renewals, dunning, cancellation and refunds. WooCommerce Subscriptions is
still the right choice for free trials, sign-up fees, variable subscriptions, switching and
proration, which native mode intentionally does not do.

Feature comparison - P2Flux Native / WooCommerce Subscriptions: simple fixed subscription yes/yes;
USDC recurring yes/via P2Flux; free trial no/WCS may support; sign-up fee no/WCS may support;
variable subscriptions no/WCS may support; switching and proration no/WCS may support; multiple
advanced lifecycle features no/yes.

= How does a native subscription start? =

The customer authorizes it in their wallet at checkout and the first payment is collected right
away. The first payment must complete shortly after authorization; if the setup expires before it
does, the subscription is marked expired, it never activates, and nothing is charged automatically
later. The customer simply starts a new order. An expired signup keeps its unused wallet
authorization on record so the customer can revoke it from My Account.

= What happens when a native renewal cannot be collected? =

The renewal order is marked failed, the subscription goes on hold, and the customer is emailed what
to do (add USDC, restore the approval, or authorize again). Retries stay inside the renewal's own
billing period. A period that passes unpaid is never collected later, there is no catch-up billing,
and the subscription is not cancelled automatically however many renewals are missed: it stays on
hold until a later payment succeeds or somebody cancels it. If the store was offline for a while,
at most one payment is attempted when it comes back - the current one.

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

= 1.1.0 =
* P2Flux Native Subscriptions: simple fixed recurring products without WooCommerce Subscriptions.
* A "USDC approval for subscriptions" setting: unlimited, or a number of billing periods.
* Re-authorize from My Account after a price change; hard P2Flux-only enforcement for native products.

= 1.0.0 =
* One-time USDC payments on Base, classic and block checkout.
* Subscriptions through WooCommerce Subscriptions: authorization, first charge, renewals, dunning.
* Payment recovery when a checkout window dies before the store hears about the payment.
* Full refunds from the merchant's own wallet, recorded in WooCommerce only after confirmation.
* Test mode on Base Sepolia.

== Upgrade notice ==

= 1.1.0 =
Adds P2Flux Native Subscriptions (no WooCommerce Subscriptions needed for simple fixed-price
subscriptions), a USDC-approval setting, re-authorization from My Account, and privacy export and
erasure for subscription records. The database schema moves to version 2 automatically.

= 1.0.0 =
First release.
