# P2Flux for WooCommerce 1.0.0 — pre-Mainnet validation report (2026-09-03)

Evidence labels: LIVE_CHAIN (Base Sepolia transaction), LIVE_STAGING (real WordPress/WooCommerce staging, no chain tx), LIVE_STAGING_FAULT_INJECTION (staging with the transport wrapper), OFFLINE_INTEGRATION / OFFLINE_UNIT (CLI suites with fakes), CODE_REVIEW, NOT_TESTED.

Boundaries honoured: PRODUCTION API DEPLOY: NO. PRODUCTION CHECKOUT DEPLOY: NO. BASE MAINNET TRANSACTION: NO. WORDPRESS.ORG SUBMISSION: NO. Docker: not used. Private keys/seed phrases: never requested. Every wallet action was signed by the user, one per message. P2S2_EXPOSED_DURING_AUDIT: NO (out-of-band charges ran inside PHP via a dev-only helper: decrypt → use → discard; only auth id / period / status / tx were returned).

Funds: BUYER_BALANCE_BEFORE 2.564076 USDC (user topped up +4.00 → 6.564076); MERCHANT_BALANCE_BEFORE 5.22 USDC. End: buyer 0.54826, merchant 10.14. Real-chain spend: 8 charges of 1.00 (orders 32, 33, 35, 39, 41, 43, 48, 53, 55 minus refunds of 33, 43, 53) + gas reimbursements of ~0.0017 each. ADDITIONAL_TOPUP_REQUIRED: none further.

## A. Scope and state

- Plugin main @ 3c583b5 (the tree released as 1.0.0; it carried the development number 1.1.0 at the time). Staging: WP 7.1, WC 11.0.1, PHP 8.3 (8.1 CLI runtime also tested), MySQL 8.0, wcs-core 8.2.0 harness, 60 s dev fixture, HPOS switched ON mid-lifecycle (was CPT).
- Core API (feat/charge-recovery) unchanged in this phase; production API stays at e23bd53 (not redeployed).
- Suites after all fixes: unit 119, integration 42, native 90 — all PASS on PHP 8.3 and 8.1; release gate PASS; Plugin Check 0 errors / 84 style warnings.

## B. Native lifecycle (product 28, native #3, buyer 0x04b7…eFf0)

| step | evidence | result |
|---|---|---|
| A signup (real checkout, Authorize within 60 s) | LIVE_CHAIN order 32 tx 0x7b5e4614…, auth 0x3466a741…, activation deadline = end of period 0, period row 0 settled, one renewal job | PASS |
| B renewal | LIVE_CHAIN order 33 created_via p2flux_native_renewal, parent 32, 1.00, period 1 | PASS |
| D CONFIRMING (natural) | LIVE_CHAIN charge 0xdd6ac019… answered CONFIRMING; order unpaid; reconcile paid it by exact recovery at 12:11:58; no second charge; no customer email | PASS |
| C out-of-band / ALREADY_CHARGED | LIVE_CHAIN helper charged period 18 (tx 0xe5ee8591…); plugin's own cycle-18 job answered CONFIRMING/period 18/same tx → order 43 reconciling → paid by exact recovery; period row unique per order. Literal ALREADY_CHARGED status is unreachable live with 60 s periods (API finality > period); OFFLINE_INTEGRATION covers it | PASS |
| miss / on-hold / resume | LIVE_STAGING cycles 3, 14, 16, 17, 21 missed → orders failed, one action-required email each, sub on-hold, never auto-cancelled; resume charged exactly one eligible cycle each time | PASS |
| E INSUFFICIENT_BALANCE | LIVE_CHAIN (real 0.55 balance) order 45 note "The wallet does not hold enough USDC…", failed, email once, on-hold; after refund landed the dunning retry charged order 48 | PASS |
| F allowance zero → restore | LIVE_CHAIN ALLOWANCE_ZERO_TX 0xe4c2ac40…4f8c (block 46335722), ALLOWANCE_AFTER_ZERO 0; order 51 INSUFFICIENT_ALLOWANCE note, email once, on-hold, no auto-retry; Restore from My Account: RESTORE_APPROVAL_TX 0x3418c338…e478, ALLOWANCE_AFTER_RESTORE unlimited; AUTH_ID_BEFORE == AUTH_ID_AFTER (0x3466a741…7799); next cycle order 53 charged 0x64e01850… and settled | PASS |
| G cancellation (real wc-ajax) | LIVE_STAGING other customer → "Not allowed."; bad nonce → 403; owner → cancelled, jobs cleared, auth retained, second cancel refused | PASS |
| H revoke | LIVE_CHAIN revoke tx 0x1c45dbd6… stored only after on-chain verification; history status revoked; helper charge → "no active authorization"; status stays cancelled | PASS |
| I refunds | LIVE_CHAIN orders 33 (0xff0084de…), 43 (0xdb05426b…), 53 (0x64d844c5…) full refunds, merchant-signed, one wc refund each, booked once | PASS |
| manual pay of a failed native renewal (A-04) | NOT_TESTED live (period always gone under the fixture); CODE_REVIEW + OFFLINE_INTEGRATION | — |

Fixture artefacts (not reproducible with day/month periods): settlement after due(n) skips period n by design (no catch-up); the API keeps answering CONFIRMING about the previous charge for ~6 minutes, longer than a 60 s period.

## C. WCS-core regression (product 18, subscription 40)

LIVE_CHAIN: signup order 39 tx 0xf92944e2…, active; renewal order 41 period 1 CONFIRMING → recovered → paid; period rows engine=wcs; parent carries no native meta; native path untouched. Historical terms: after recipient → B and price → 2.00, renewal order 55 total 1.00 / units 1000000, on-chain transfer 0.88 to recipient A (0xada6…94e9), 0.02 fee wallet, 0.101708 gas treasury. New WCS signup (order 57 / wcs:58) and new native signup (order 56 / native:5) carried recipient B and 2000000 units. Settings restored, test orders cancelled.

EXISTING_WCS_TERMS_IMMUTABLE: YES (LIVE_CHAIN). NEW_WCS_SUB_USES_CURRENT_SETTINGS: YES (LIVE_STAGING). NEW_NATIVE_SUB_USES_CURRENT_SETTINGS: YES (LIVE_STAGING). EXISTING_NATIVE_TERMS_IMMUTABLE: YES by construction (amount/recipient come from the stored authorization; every live native renewal carried units 1000000 / recipient A) — CODE_REVIEW + OFFLINE_INTEGRATION; a post-settings-change native renewal was not possible live because #3 was already cancelled and revoked by then. Environment immutability: OFFLINE_UNIT.

## D. Security surfaces

- CLASSIC_TAMPERED_PAYMENT_METHOD: LIVE_STAGING `?wc-ajax=checkout` with payment_method=bacs (BACS temporarily enabled) → "Invalid payment method. This subscription can only be paid through P2Flux.", no order, no native row; P2Flux control created order 36. PASS.
- ORDER_PAY_TAMPERED_GATEWAY: LIVE_STAGING POST to order-pay with bacs → 302 back with the same message, order pending, method unchanged. PASS.
- PAYMENT_COMPLETE_GATE: LIVE_STAGING payment_complete() on a native order with method bacs → stays pending/unpaid. PASS.
- STORE_API: LIVE_STAGING PASS (earlier in the phase).
- Ownership/nonce on customer ajax: LIVE_STAGING PASS (cancel). Restore/retry/reauth refs use engine-qualified references (A-02 fix, OFFLINE_INTEGRATION).
- Secrets: no decrypted capability in DB, logs, pages, exports (section G).

## E. Concurrency, recovery, fault injection

- Lock: INSERT IGNORE on the options unique index (A-15, CODE_REVIEW); stale CHARGING rows swept to reconciliation (A-14, OFFLINE_INTEGRATION).
- One period → one order: UNIQUE(auth_id, period_index) held through 60+ live cycles; period-conflict / previous-period-settling paths exercised live (A-40).
- API failures (LIVE_STAGING_FAULT_INJECTION on WCS order 59): http500, timeout, malformed JSON, http429 → order pending, "the customer has not been charged", one retry job (sooner replaces later), balance unchanged. recover_* faults: OFFLINE_INTEGRATION only.
- Downtime probe 1/3/10/100/100000 missed cycles → one charge (OFFLINE_INTEGRATION). Dunning ladder clamped inside the period, no negative delay (OFFLINE_INTEGRATION + LIVE_STAGING).
- CAS on the meta document with interleaved writers: OFFLINE_INTEGRATION.

## F. Storage, upgrade, uninstall, privacy

- Disposable DB copy (dropped afterwards): repeated upgrade schema unchanged; upgrading an earlier development build to this one adds the engine column + native table and keeps period rows; default uninstall keeps data; destructive uninstall (A-01 fix) removes only plugin data. ORIGINAL_STAGING_DB_UNCHANGED: YES (checksums of orders/periods/native identical before and after the copy tests). No DB changes since those tests.
- HPOS: enabled mid-lifecycle (sync 37 orders, 0 unsynced); order/native lookups, account page, cancel, revoke, refund all ran under HPOS afterwards.
- PRIVACY_DATA_INVENTORY: native row = user_id (link), product, amounts, dates, status, store payout wallet, auth id (public digest), tx hashes, encrypted authorization (p2fwc1); no name/email/address/IP (those are WooCommerce's).
- WP_EXPORT_BEHAVIOR: exporter "P2Flux subscriptions" registered; LIVE_STAGING export for user 2 → 1 item, 0 secrets.
- WP_ERASE_BEHAVIOR: eraser unlinks (user_id → 0), cancels anything still live, retains financial rows; LIVE_STAGING run: items_removed/retained true with the retention message.
- CUSTOMER_DELETION_BEHAVIOR: deleted_user hook does the same detach (OFFLINE_INTEGRATION).
- FINANCIAL_RETENTION_POLICY: amounts, periods, public chain identifiers and ciphertext retained (refund/revoke capability, audit trail); documented in readme.txt Privacy section. PRIVACY_DOCS_UPDATED: YES.

## G. Secrets and logging (counts only)

Staging DB after the whole lifecycle: p2s2 plaintext 0 in postmeta / options / wc_orders_meta / native table / comments / usermeta / actionscheduler args; ciphertext p2fwc1 only where expected. Logs: debug.log 0, wc-logs 0. Rendered pay panel: p2s2 0, p2setup2 1 (browser setup token by design). Customer/admin pages: p2s2 0, p2fwc1 0. Privacy export JSON: 0.

## H. Compatibility and runtimes

| runtime | status |
|---|---|
| PHP 8.3 (staging) | LIVE TESTED |
| PHP 8.1 (declared minimum) | RUNTIME TESTED via CLI suites + lint; not run as the web runtime |
| PHP 8.0 / 7.4 | NOT TESTED (not declared) |
| WP 7.1 / WC 11.0.1 | LIVE TESTED; declared minimums STATIC REVIEWED only |
| MySQL 8.0 | LIVE TESTED; MySQL 5.5.5 / MariaDB 10.1 minimums STATIC REVIEWED (whole-column JSON, no JSON_SET) |
| HPOS on / off | LIVE TESTED both |
| WooCommerce Subscriptions | the wcs-core 8.2.0 harness only. **The current commercial WooCommerce Subscriptions plugin has NOT been tested.** |

## I. Package

The validated package — sha256 `f63aa796222eec9204f1da5a5971bcc4e72dd8c2002f68f88e0b1ad982f12a0d`, 66 files, 472,976 bytes, built by git archive from 3c583b5 (clean tree), before the version was renumbered to 1.0.0 and the Plugin Check warnings were fixed. Contains no dev/tests/md/git/env/map, no curl_, no fixture/harness/audit references, 0 secret-like strings. Plugin Check 0 errors. External service disclosure and privacy section present in readme.txt.

## J. Docs vs code

readme.txt, README.md, docs/architecture.md and the website page updated for native subscriptions, allowance modes and privacy (A-31). Open doc notes: A-10 (guest ajax nonce semantics), A-11/A-35 (settlement receipt trust is an API invariant), A-34 (browser intents at rest).

## K. Findings (FOUND → FIXED → REGRESSION TEST ADDED)

| id | sev | finding | fix / commit | test | status |
|---|---|---|---|---|---|
| A-02/A-03 | HIGH/MED | customer retry passed a bare id → wrong engine could be charged | engine refs + ORDER_MISMATCH pairing (2b271d0) | native.php | FIXED |
| A-14 | HIGH | stale CHARGING rows never reconciled | sweep → RECONCILING (8a1f5e7) | native.php | FIXED |
| A-15 | HIGH | lock not atomic | INSERT IGNORE (8a1f5e7) | code review | FIXED |
| A-40 | MED | API re-reporting an already-recovered tx wedged the subscription until the hourly sweep (LIVE) | treat as previous-period-settling, retry (04c9bd3) | native.php ×3 | FIXED, path then observed live |
| A-01, A-05, A-16…A-19, A-26, A-27 | MED | uninstall, unverified revoke, re-chargeable settled/refunded orders, cancel overwritten by late settlement, swallowed sooner retry, refund double-booking, privacy, build from dirty tree | see ledger | tests where practical | FIXED |
| A-39 | LOW | abandoned (never authorized) signup never expired (LIVE) | sweep + cancelled-order hook (fe8c153) | native.php ×5 | FIXED, cancel hook observed live |
| A-41 | LOW | second action-required email on the same order (LIVE) | suppress 'missed' after a cause email (ec3014b) | native.php ×2 | FIXED |
| A-42 | TEST_GAP | native emails never loaded offline | loaded (76039c0) | — | FIXED |
| A-44 | LOW | "still confirming" note repeated every 60 s retry | once per period (3c583b5) | suites | FIXED |
| A-06…A-09, A-20…A-25, A-28…A-30, A-32 | LOW/HARDENING/DOCS | see ledger | fixed | — | FIXED |
| A-33, A-36, A-38, A-43, A-45 | LOW | email dedupe not locked; JSON counters may lose increments; non-USD refund bookkeeping; no "revoked" line on the detail page; uncapped 60 s re-ask while the API reports an earlier period (WCS) | — | — | OPEN (LOW, documented) |
| A-10, A-11, A-34, A-35 | DOCS | notes | — | — | OPEN (docs) |
| A-37 | HARDENING | exhausted reconciliation waits for a human | by design | — | ACCEPTED |

Open CRITICAL/HIGH/MEDIUM: 0. Delta review since the last agent reviews (39e8225..3c583b5) done by hand (two reviewer-agent runs failed on API overload).

## L. Limitations

- 60 s fixture makes "CONFIRMING lasts longer than a period" the normal case; several live paths (period skips, previous-period waits) are fixture artefacts, but the code handled all of them without a wrong payment.
- Literal ALREADY_CHARGED, recover_* faults, manual pay of a failed native renewal, native renewal after a settings change: offline/code-review evidence only.
- Emails: staging has no sendmail; dispatch verified by hooks/notes, not delivery.
- A 0.0017 USDC gas reimbursement per charge is debited from the buyer on top of the price; the shown 1.05 is the allowance unit (amount + 0.05 cap), never the debit.

## M. Verdict

- SECURITY_REVIEW: PASS (0 open CRITICAL/HIGH/MEDIUM)
- NATIVE_LIFECYCLE: COMPLETE (A–I live)
- WCS_REGRESSION: COMPLETE (wcs-core harness); COMMERCIAL_WCS_TESTED: NO
- INVARIANTS / CONCURRENCY / DATA_INTEGRITY: PASS
- PRIVACY_REVIEW: COMPLETE
- ZIP: PASS
- READY_FOR_CONTROLLED_MAINNET_SMOKE: YES — with production checkout still undeployed and the API at e23bd53, the first Mainnet step must be a single-store, single-product smoke with day-length periods.
