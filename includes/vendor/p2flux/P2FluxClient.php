<?php

declare(strict_types=1);

namespace P2FluxWC\Vendor\P2Flux;

/**
 * P2Flux PHP SDK - a thin client over the HTTP API, no dependencies beyond curl.
 *
 * P2Flux executes payments. Your application owns the subscription lifecycle: when a renewal is
 * due, who the customer is, what happens after a failure. There is deliberately no scheduler and
 * no state here - call charge() from your existing renewal job (WP-Cron, Laravel scheduler, a
 * worker, whatever you already run).
 *
 *   $p2flux = new P2FluxClient(['apiUrl' => 'https://api.p2flux.example']);
 *   $result = $p2flux->charge($subscriptionRef);
 *   if ($result->ok) { // CHARGED or ALREADY_CHARGED
 *       $subscription->markRenewalPaid();
 *   }
 *
 * Pass a `transport` callable to route requests through the host application's HTTP client instead
 * of curl (see __construct) - hosts with their own stack, and tests, both need that.
 */
final class P2FluxClient
{
    /** Status => what the merchant system should do about it. */
    public const ACTIONS = [
        'CHARGED' => 'SUCCESS',
        'ALREADY_CHARGED' => 'SUCCESS',
        // The money moved and the chain has not settled. Not a failure and not a success: leave the
        // period open, change nothing, ask again shortly - and never send a second charge.
        'CONFIRMING' => 'WAIT',
        'PAYMENT_CONFIRMING' => 'WAIT',
        /* Refunds. REFUND_CONFIRMING is the same shape as PAYMENT_CONFIRMING and matters for the
         * same reason: the transfer is on chain but not settled, so keep the refund pending against
         * the SAME hash. Sending another because this one has not confirmed would refund twice. */
        'REFUND_CONFIRMING' => 'WAIT',
        /* Permanent: a token that is malformed or past its fifteen minutes never becomes valid.
         * These were absent, so the ?? fallback in throwIfError() reported them as RETRY_LATER and
         * a merchant would retry a dead token forever. */
        'INVALID_REFUND_TOKEN' => 'INVALID_REQUEST',
        'REFUND_TOKEN_EXPIRED' => 'INVALID_REQUEST',
        'REFUND_AMOUNT_INVALID' => 'INVALID_REQUEST',
        'REFUND_WRONG_MERCHANT' => 'INVALID_REQUEST',
        /* The receipt does not contain the refund it was supposed to. Never mark an order refunded
         * on this - investigate the transaction. */
        'REFUND_TRANSACTION_MISMATCH' => 'INVALID_REQUEST',
        'REFUND_ORIGINAL_PAYMENT_INVALID' => 'INVALID_REQUEST',
        /* Recovery. PAYMENT_NOT_FOUND is an as-of-this-block answer and never a permanent one, so
         * it is a retry rather than a verdict; the other two are operational. */
        'PAYMENT_NOT_FOUND' => 'RETRY_LATER',
        'PAYMENT_RECOVERY_INCONSISTENT' => 'RETRY_LATER',
        'RECOVERY_UNAVAILABLE' => 'RETRY_LATER',
        'NOT_DUE' => 'RETRY_LATER',
        'INSUFFICIENT_BALANCE' => 'CUSTOMER_ACTION_REQUIRED',
        'INSUFFICIENT_ALLOWANCE' => 'CUSTOMER_ACTION_REQUIRED',
        'PERMISSION_REVOKED' => 'STOP_SUBSCRIPTION',
        'SUBSCRIPTION_EXPIRED' => 'STOP_SUBSCRIPTION',
        'INVALID_SUBSCRIPTION' => 'INVALID_REQUEST',
        'INVALID_REQUEST' => 'INVALID_REQUEST',
        // Permanent: the amount or period is outside what the terms can express. Fix the request,
        // do not retry it - the fallback used to call these retryable, which reads as an outage.
        'AMOUNT_OUT_OF_BOUNDS' => 'INVALID_REQUEST',
        'PERIOD_OUT_OF_BOUNDS' => 'INVALID_REQUEST',
        'RPC_ERROR' => 'RETRY_LATER',
        'RELAYER_ERROR' => 'RETRY_LATER',
        'TRANSACTION_REVERTED' => 'RETRY_LATER',
        'INTERNAL_ERROR' => 'RETRY_LATER',
        'NETWORK_ERROR' => 'RETRY_LATER',
        // Infrastructure protection, not a payment outcome: the request was turned away before any
        // money could move, so the subscription is untouched and the call is safe to repeat.
        'RATE_LIMITED' => 'RETRY_LATER',
        'CONCURRENCY_LIMIT' => 'RETRY_LATER',
        // Gas could not be priced, or moved above what this subscription authorized. Nothing was
        // spent and the subscription is unchanged; the charge waits for better conditions.
        'GAS_TOO_HIGH' => 'RETRY_LATER',
        'GAS_QUOTE_UNAVAILABLE' => 'RETRY_LATER',
        'GAS_FEE_TOO_HIGH' => 'RETRY_LATER',
        /* Operator-side limits, not payment outcomes: refused before anything reached the chain, so
         * nothing was spent and the subscription is untouched. Nothing a customer can act on. */
        'RELAYER_TX_COST_TOO_HIGH' => 'RETRY_LATER',
        'RELAYER_BUDGET_EXCEEDED' => 'RETRY_LATER',
        'RELAYER_NOT_READY' => 'RETRY_LATER',
        // The service is at its own capacity: come back shortly, not "you asked too often".
        'RPC_BUSY' => 'RETRY_LATER',
        /* The API ships no `action` for these, so this local fallback is what a merchant sees. A
         * dead or mismatched token never becomes valid by retrying - integration errors, all. */
        'INVALID_INTENT' => 'INVALID_REQUEST',
        'INTENT_EXPIRED' => 'INVALID_REQUEST',
        'INVALID_REFERENCE' => 'INVALID_REQUEST',
        'INVALID_SETUP_TOKEN' => 'INVALID_REQUEST',
        'SETUP_TOKEN_EXPIRED' => 'INVALID_REQUEST',
        'INVALID_CANCEL_TOKEN' => 'INVALID_REQUEST',
        'CANCEL_TOKEN_EXPIRED' => 'INVALID_REQUEST',
        'TERMS_MISMATCH' => 'INVALID_REQUEST',
        'PERMISSION_NOT_FOUND' => 'INVALID_REQUEST',
        'INVALID_SIGNATURE' => 'INVALID_REQUEST',
        'UNSUPPORTED_SIGNATURE_FORMAT' => 'INVALID_REQUEST',
        'WRONG_SPENDER' => 'INVALID_REQUEST',
        'WRONG_TOKEN' => 'INVALID_REQUEST',
        'INVALID_EXTRA_DATA' => 'INVALID_REQUEST',
        // A settled intent cannot settle twice; retrying will not change the answer.
        'PAYMENT_ALREADY_PROCESSED' => 'INVALID_REQUEST',
        // The transaction may still be propagating or mining - the same question can answer anew.
        'TRANSACTION_NOT_FOUND' => 'RETRY_LATER',
        /* The customer's contract wallet costs more to validate than the API will spend. Only the
         * customer can fix that, by authorizing from an ordinary wallet. */
        'SIGNATURE_VALIDATION_TOO_EXPENSIVE' => 'CUSTOMER_ACTION_REQUIRED',
        /* Paying the network fee in the payment token. Unsupported is a fact about the deployment,
         * not a moment in time: fall back to native gas rather than retrying. An expired quote needs
         * a fresh price and a fresh signature, which only the customer can give. A sponsored
         * transaction that failed moved no money - the fee and the operation settle together - so it
         * is safe to try again with a new quote. */
        'PAYMENT_TOKEN_GAS_UNSUPPORTED' => 'INVALID_REQUEST',
        'PAYMENT_TOKEN_GAS_UNAVAILABLE' => 'RETRY_LATER',
        'PAYMENT_TOKEN_GAS_QUOTE_EXPIRED' => 'CUSTOMER_ACTION_REQUIRED',
        'PAYMENT_TOKEN_GAS_LIMIT_EXCEEDED' => 'RETRY_LATER',
        'INVALID_GAS_QUOTE' => 'INVALID_REQUEST',
        'INSUFFICIENT_PAYMENT_TOKEN_FOR_GAS' => 'CUSTOMER_ACTION_REQUIRED',
        'SPONSORED_TRANSACTION_FAILED' => 'RETRY_LATER',
        'SPONSORED_PERMIT_FAILED' => 'RETRY_LATER',
        // In flight: look the settlement up, never send another one.
        'SPONSORSHIP_CONFIRMING' => 'WAIT',
    ];

    private string $apiUrl;
    private int $timeout;
    /** @var null|callable(string, array<string, mixed>, int): array{0: int, 1: array<string, mixed>} */
    private $transport;

    /* The curl transport, built once and only when something actually needs it. A host that passes
     * its own transport never loads the class at all, which is the point: a WordPress plugin
     * vendoring this SDK must be able to ship it without shipping a curl call. */
    private static ?CurlTransport $defaultTransport = null;

    /**
     * @param array{apiUrl: string, timeout?: int, transport?: callable} $options
     *        timeout defaults to 60 s: a charge waits for on-chain confirmation, which on a busy
     *        public RPC can take tens of seconds. Abandoning it early is safe but noisy - the
     *        payment may still land, and the next call returns ALREADY_CHARGED.
     *
     *        transport replaces curl with your own HTTP client - a host framework's (WordPress's
     *        wp_remote_post, Guzzle, Symfony HttpClient) or a stub in tests. It receives the
     *        absolute URL, the payload array and the timeout, and must return
     *        [int $httpStatus, array $decodedBody]. Throw P2FluxException('NETWORK_ERROR', ...)
     *        when the request never reached the API; charge() turns that into a retryable result.
     */
    public function __construct(array $options)
    {
        if (empty($options['apiUrl'])) {
            throw new \InvalidArgumentException('apiUrl is required');
        }
        if (isset($options['transport']) && !is_callable($options['transport'])) {
            throw new \InvalidArgumentException('transport must be callable');
        }
        $this->apiUrl = rtrim($options['apiUrl'], '/');
        $this->timeout = $options['timeout'] ?? 60;
        $this->transport = $options['transport'] ?? null;
    }

    /**
     * Technical terms for a recurring authorization. No customer, order or product fields exist -
     * the API rejects unknown properties. The returned setup_token goes in the checkout URL
     * fragment; `salt` identifies this exact setup, so a capability finalized from a different one
     * can be told apart later (compare it against the salt in status()).
     *
     * `allowance` bounds the standing ERC-20 allowance the hosted checkout asks the customer for:
     * 'unlimited' (the default: renewals never need the wallet again), 'until_end' (needs `end`),
     * or ['periods' => N] for N charges' worth, after which your restore flow asks again. Whatever
     * the mode, the allowance only reaches the recurring contract, which moves nothing the signed
     * authorization does not permit.
     *
     * @param array{recipient: string, amount: string, period: int, end?: int, allowance?: string|array{periods: int}} $terms
     * @return array{setup_token: string, salt: string, amount: string, ...}
     */
    public function createSubscription(array $terms): array
    {
        [$httpStatus, $body] = $this->post('/v1/subscriptions', $terms);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * The authoritative terms behind a setup token, plus the exact EIP-712 payload (`typed_data`)
     * the customer's wallet must sign. This is what a checkout displays - the token, not the page
     * that opened it, is the source of truth for what is being authorized.
     *
     * @param string|null $gasPaymentMode 'payment_token' also prices the allowance transaction
     *                                    P2Flux would send for a customer holding no native
     *                                    currency; requires `$payer`.
     * @param string|null $payer With `payment_token`: the wallet whose allowance will be set. The
     *                           price depends on it, which is why it is not known at creation time.
     * @return array{typed_data: array<string, mixed>, terms?: array<string, mixed>, quote?: string, ...}
     */
    public function resolveSubscription(
        string $setupToken,
        ?string $gasPaymentMode = null,
        ?string $payer = null
    ): array {
        $payload = ['setup_token' => $setupToken];
        /* Zero-native signup: the customer holds no native currency, so instead of sending an
         * approval they sign one, and this prices the transaction P2Flux will send for them. Both
         * fields or neither - the quote is for a specific wallet's allowance. */
        if ($gasPaymentMode !== null && $payer !== null) {
            $payload['gas_payment_mode'] = $gasPaymentMode;
            $payload['payer'] = $payer;
        }

        [$httpStatus, $body] = $this->post('/v1/subscriptions/resolve', $payload);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * Exchange the customer's EIP-712 signature for the `p2s2.` charge capability.
     *
     * The hosted checkout performs this step itself; call it directly when you run your OWN
     * checkout page and collected the signature there. The returned `subscription` is the ONE
     * thing your system stores per subscription - treat it like a credential: encrypted at rest,
     * never in a URL, never in a log.
     *
     * @param array<string,string>|null $sponsorship For a customer with no native currency: the
     *        `quote`, `permit_signature`, `network_fee_signature` and optional `permit_nonce` they
     *        signed at resolve. The capability is minted first and costs nothing, so a sponsorship
     *        that fails is reported inside the response (`sponsorship.status`) rather than thrown -
     *        the subscription exists either way, and the allowance is repairable from the restore
     *        flow. `ALREADY_SETTLED` means a repeat of a request that already succeeded.
     * @return array{subscription: string, subscription_id?: string, sponsorship?: array{status: string, ...}, ...}
     */
    public function finalizeSubscription(
        string $setupToken,
        string $payer,
        string $signature,
        ?array $sponsorship = null
    ): array {
        $payload = [
            'setup_token' => $setupToken,
            'payer' => $payer,
            'signature' => $signature,
        ];
        if ($sponsorship !== null) {
            $payload['sponsorship'] = $sponsorship;
        }

        [$httpStatus, $body] = $this->post('/v1/subscriptions/finalize', $payload);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * Technical terms for a one-time payment. The reference is server-generated: keep your own
     * order -> reference mapping.
     *
     * The hosted API enforces a 0.01 USDC minimum per one-time payment; a smaller amount is
     * refused as AMOUNT_OUT_OF_BOUNDS before an intent exists. The server is canonical - this SDK
     * deliberately does not duplicate the check.
     *
     * `gas_payment_mode` is optional. Omitted - or 'native' - is what has always happened: the buyer
     * sends the transaction and pays the network in the chain's own currency. With 'payment_token'
     * the buyer needs none of that currency: P2Flux sends the transaction and the buyer reimburses
     * the quoted network cost in the payment token. P2Flux's own fees come out of the amount, so
     * the merchant funds them and the buyer pays no fee on top of the price. Check
     * `capabilities()` first; not every network and token supports it, and an unsupported request is
     * refused here rather than after a customer has been sent to a checkout that cannot work.
     *
     * @param array{recipient: string, amount: string, gas_payment_mode?: 'native'|'payment_token'} $terms
     * @return array{intent: string, reference: string, amount: string, ...}
     */
    public function createPayment(array $terms): array
    {
        [$httpStatus, $body] = $this->post('/v1/payments', $terms);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * The authoritative terms for a checkout to display, read back from the intent itself.
     * Refuses an expired intent (INTENT_EXPIRED) - expiry stops a payment being STARTED; it never
     * makes an existing settlement unverifiable.
     *
     * @return array{intent?: string, recipient: string, amount: string, ...}
     */
    public function resolvePayment(string $intent): array
    {
        [$httpStatus, $body] = $this->post('/v1/payments/resolve', ['intent' => $intent]);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * Settle a payment whose buyer holds no native currency.
     *
     * The buyer signed the token authorization the hosted checkout showed them; this hands that
     * signature to P2Flux, which sends the transaction and takes the quoted network fee out of the
     * same authorization. Nothing is fronted on credit: the fee and the payment settle together, or
     * neither does.
     *
     * `status => 'CONFIRMING'` means it is in flight. Ask `verifyPayment()` about the hash - do NOT
     * call this again, because the buyer's authorization may already be spent.
     *
     * @return array{status: string, tx_hash?: string, ...}
     */
    public function sponsorPayment(string $intent, string $quote, string $payer, string $signature): array
    {
        [$httpStatus, $body] = $this->post('/v1/payments/sponsor', [
            'intent' => $intent,
            'quote' => $quote,
            'payer' => $payer,
            'signature' => $signature,
        ]);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * What this deployment supports, per token and per operation.
     *
     * Read it once at start-up rather than per checkout - it changes only when the deployment does -
     * and use it to decide whether to offer a buyer the option of paying the network fee in the
     * payment currency. Architectural possibility is not support: an operation reported false here
     * has not been deployed and tested on this network, whatever the token is capable of.
     *
     * @return array{
     *     chain_id: int,
     *     network: string,
     *     native_currency: string,
     *     supported: bool,
     *     tokens: list<array{
     *         address: string,
     *         symbol: string,
     *         decimals: int,
     *         gas_payment_modes: list<string>,
     *         fixed_network_fee_units?: string,
     *         operations?: array<string, bool>,
     *         sponsor_contracts?: array<string, string>,
     *         ...
     *     }>,
     *     ...
     * }
     */
    public function capabilities(): array
    {
        [$httpStatus, $body] = $this->get('/v1/capabilities');
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * The trust boundary for one-time payments: re-reads the receipt on chain and checks it against
     * the signed intent. Never grant access on a browser's word alone - call this server-side.
     *
     * A rejected payment is `['valid' => false, 'code' => ...]` with HTTP 200, not an exception.
     *
     * `$settlementReceipt` is the sealed token a previous CONFIRMED verification of this same
     * payment returned (the checkout couriers it to your callback). Passing it lets the server
     * answer without re-reading the chain; a missing or broken one silently falls back to the full
     * verification, so it is always safe to pass whatever the browser handed you - the server,
     * not the receipt, remains the authority.
     *
     * @return array{
     *     valid: bool,
     *     code?: string,
     *     tx_hash?: string,
     *     block_number?: int,
     *     reference?: string,
     *     amount?: string,
     *     settlement_receipt?: string,
     *     gas_payment_mode?: 'native'|'payment_token',
     *     accounting?: array{
     *         payment_units: string,
     *         payment_fee_units: string,
     *         network_fee_units: string,
     *         fixed_network_fee_units: string,
     *         merchant_net_units: string,
     *         buyer_total_units: string,
     *         payer: string,
     *         ...
     *     },
     *     retry_after?: int,
     *     ...
     * }
     */
    public function verifyPayment(string $intent, string $txHash, ?string $settlementReceipt = null): array
    {
        $payload = ['intent' => $intent, 'tx_hash' => $txHash];
        if ($settlementReceipt !== null && $settlementReceipt !== '') {
            $payload['settlement_receipt'] = $settlementReceipt;
        }
        [$httpStatus, $body] = $this->post('/v1/payments/verify', $payload);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * Find the transaction that settled an intent, when its hash was lost.
     *
     * The failure this is for: the checkout window dies between the wallet returning a hash and
     * your server recording it. The money has moved, the order looks unpaid, and there is nothing
     * to reconcile against. Give this the intent and it finds the settlement on chain - you supply
     * no hash and no hint, and the match is bound to the exact payment the intent describes, so it
     * can never hand you somebody else's transaction.
     *
     * Pure reads and idempotent, so it is safe to call from a cron for any order you are unsure
     * about. It also works long after the intent expired: expiry stops a payment being STARTED and
     * says nothing about one that already happened.
     *
     * `['found' => false, 'code' => 'PAYMENT_NOT_FOUND']` means no settlement existed AS OF the
     * block named in `as_of_block` - NOT that the buyer will never pay. The contract does not
     * enforce your intent's expiry, so a slow wallet can still settle afterwards and a later call
     * will find it. Stop retrying on your own business rules, never on one not-found.
     *
     * A settlement that is still confirming comes back with `found => true` and the transaction
     * hash, so you keep the hash rather than having to recover it again.
     *
     * @return array{
     *     found: bool,
     *     valid?: bool,
     *     code?: string,
     *     tx_hash?: string,
     *     block_number?: int,
     *     amount?: string,
     *     as_of_block?: int,
     *     gas_payment_mode?: 'native'|'payment_token',
     *     accounting?: array<string, string>,
     *     ...
     * }
     */
    public function recoverPayment(string $intent): array
    {
        [$httpStatus, $body] = $this->post('/v1/payments/recover', ['intent' => $intent]);

        /* Nothing settled, and still confirming, are both ANSWERS - only a broken request or a
         * deployment that cannot recover is an exception. */
        $code = isset($body['code']) ? (string) $body['code'] : (isset($body['error']) ? (string) $body['error'] : '');
        if ($httpStatus >= 400 && $code !== 'PAYMENT_NOT_FOUND' && $code !== 'PAYMENT_CONFIRMING') {
            $this->throwIfError($httpStatus, $body);
        }

        return $body;
    }

    /**
     * Find the transaction that charged one recurring period, when its hash was lost.
     *
     * The failure this is for: a charge lands, the response never reaches your worker, and the
     * retry answers ALREADY_CHARGED - which proves the period was collected and names no
     * transaction. P2Flux stores nothing, so the hash lives only in the contract's log, and without
     * it you cannot attribute the payment to an order, audit it, or refund it: refunds start from
     * the original settlement.
     *
     * The period index is required and exact. There is no "current period" form, because you are
     * reconciling one specific collection - today, or a year from now - and the answer must not
     * move under you. Take it from the charge response or from status().
     *
     * `['found' => false, 'code' => 'PAYMENT_NOT_FOUND']` is ordinary, not an error: there is no
     * catch-up billing, so a period that was never collected is a normal history, and a later
     * period having been charged says nothing about an earlier one. Like recoverPayment(), it is a
     * statement about one block height rather than a permanent verdict.
     *
     * `$hint` (`['attempted_at' => unix seconds]` or `['block' => n]`) is where your own records say
     * you attempted the charge. It narrows the search and nothing else - it can never turn a miss
     * into a hit - and omitting it is always safe.
     *
     * @param array{attempted_at?: int, block?: int}|null $hint
     * @return array{
     *     found: bool,
     *     code?: string,
     *     tx_hash?: string,
     *     block_number?: int,
     *     subscription_id?: string,
     *     period_index?: int,
     *     payer?: string,
     *     recipient?: string,
     *     amount_units?: string,
     *     net_units?: string,
     *     fee_units?: string,
     *     network_fee_units?: string,
     *     as_of_block?: int,
     *     ...
     * }
     */
    public function recoverCharge(string $subscriptionRef, int $periodIndex, ?array $hint = null): array
    {
        $payload = ['subscription' => $subscriptionRef, 'period_index' => $periodIndex];
        if ($hint !== null && $hint !== []) {
            $payload['hint'] = $hint;
        }

        [$httpStatus, $body] = $this->post('/v1/charges/recover', $payload);

        /* Nothing found, and a settlement still confirming, are both ANSWERS - the same rule
         * recoverPayment() follows, and for the same reason: a caller that has just been told
         * ALREADY_CHARGED will ask again, and an exception would make that a special case. */
        $code = isset($body['code']) ? (string) $body['code'] : (isset($body['error']) ? (string) $body['error'] : '');
        if ($httpStatus >= 400 && $code !== 'PAYMENT_NOT_FOUND' && $code !== 'PAYMENT_CONFIRMING') {
            $this->throwIfError($httpStatus, $body);
        }

        return $body;
    }

    /**
     * A session for restoring the token allowance one subscription needs.
     *
     * INSUFFICIENT_ALLOWANCE is not a dead subscription: the authorization the customer signed is
     * intact and you can still collect. What ran short is the ERC-20 allowance, and the fix is one
     * approve() from the customer's own wallet - no new signature, no new subscription.
     *
     * The token this returns is the narrowest P2Flux issues: the payer, the spender, the token and
     * how much the next charge pulls. It cannot charge, cannot revoke and cannot refund. Open
     * <checkout>/#/approve/<approve_token> and wait for `p2flux.allowance.restored`, then charge()
     * the SAME subscription again.
     *
     * @return array{approve_token: string, ...}
     */
    public function createAllowanceRestoreSession(string $subscriptionRef): array
    {
        [$httpStatus, $body] = $this->post('/v1/allowances/restore/session', ['subscription' => $subscriptionRef]);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * Read an allowance-restore session back: what to approve, and who must approve it.
     *
     * Browser-side, like the other resolve calls - the checkout uses it to show the terms and build
     * the customer's own approve(). A merchant server has no reason to call it.
     *
     * @return array<string, mixed>
     */
    public function resolveAllowanceRestore(string $approveToken, ?string $gasPaymentMode = null): array
    {
        $payload = ['approve_token' => $approveToken];
        /* With 'payment_token' the response also carries a price and the two messages the customer
         * signs, and P2Flux sends the transaction for them. Without it nothing changes: the terms
         * describe the customer's own `approve()`, which their wallet sends and pays gas for. */
        if ($gasPaymentMode !== null && $gasPaymentMode !== '') {
            $payload['gas_payment_mode'] = $gasPaymentMode;
        }
        [$httpStatus, $body] = $this->post('/v1/allowances/restore/resolve', $payload);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * Carry a customer's signed allowance change onto the chain, so they need no native currency.
     *
     * They signed two things: the allowance change, and a bounded fee for the transaction that
     * carries it. P2Flux sends one call that does both - a change that cannot execute returns the
     * fee, so the customer is never charged for something that did not happen.
     *
     * `$allowanceUnits` of "0" REMOVES the allowance, which stops collection. That is not a
     * revocation of the recurring authorization - only the payer's own transaction does that - and
     * the two must be described separately to customers.
     *
     * @return array<string, mixed>
     */
    public function submitAllowanceRestore(
        string $approveToken,
        string $quote,
        string $permitSignature,
        string $networkFeeSignature,
        ?string $allowanceUnits = null,
        ?string $permitNonce = null
    ): array {
        $payload = [
            'approve_token' => $approveToken,
            'quote' => $quote,
            'permit_signature' => $permitSignature,
            'network_fee_signature' => $networkFeeSignature,
        ];
        if ($allowanceUnits !== null) {
            $payload['allowance_units'] = $allowanceUnits;
        }
        if ($permitNonce !== null) {
            $payload['permit_nonce'] = $permitNonce;
        }
        [$httpStatus, $body] = $this->post('/v1/allowances/restore/submit', $payload);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * Exchange a p2s2 for a short-lived cancellation token that can safely reach the customer's
     * browser: it carries the authorization fields needed to build revoke(), and neither the
     * customer's signature nor any ability to charge. Open <checkout>/#/cancel/<cancel_token>.
     *
     * The contract still requires the payer's own wallet to send the transaction, so possession of
     * the token alone cannot revoke anything.
     *
     * @return array{cancel_token: string, ...}
     */
    public function createCancellationSession(string $subscriptionRef): array
    {
        [$httpStatus, $body] = $this->post('/v1/subscriptions/revoke/session', ['subscription' => $subscriptionRef]);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * Attempt one recurring charge.
     *
     * Never throws for a payment outcome - inspect $result->status and $result->action. Only an
     * unreachable API produces NETWORK_ERROR, which is itself retryable.
     *
     * Safe to retry: the contract allows one charge per billing period, so a repeat call after a
     * timeout or a crashed worker returns ALREADY_CHARGED instead of charging again.
     */
    public function charge(string $subscriptionRef): ChargeResult
    {
        try {
            [, $body] = $this->post('/v1/charges', ['subscription' => $subscriptionRef]);
        } catch (P2FluxException $e) {
            /* The exception's raw body rides along (curl's `detail`, for one), because the place an
             * operator most needs to know WHY the API was unreachable is their renewal job's log -
             * and this used to be the one place that reason was dropped. */
            return ChargeResult::fromArray(['status' => 'NETWORK_ERROR'] + $e->raw);
        }

        return ChargeResult::fromArray($body);
    }

    /**
     * Current state, read straight from the chain. Use it to reconcile after downtime.
     *
     * @return array{
     *     terms: array{salt: string, amount_units: string, recipient: string, period: int, ...},
     *     due?: bool,
     *     charged_this_period?: bool,
     *     period_index?: int,
     *     next_period_at?: string,
     *     revoked?: bool,
     *     ...
     * }
     */
    public function status(string $subscriptionRef): array
    {
        [$httpStatus, $body] = $this->post('/v1/subscriptions/status', ['subscription' => $subscriptionRef]);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * Calldata that cancels this one subscription. Only the customer's wallet can send it -
     * P2Flux cannot revoke wallet authority and does not pretend to.
     *
     * @return array<string, mixed>
     */
    public function prepareSubscriptionCancellation(string $subscriptionRef): array
    {
        [$httpStatus, $body] = $this->post('/v1/subscriptions/revoke/prepare', ['subscription' => $subscriptionRef]);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * Calldata that removes the token allowance entirely - stops every P2Flux subscription paid
     * in that token from this wallet.
     *
     * @return array<string, mixed>
     */
    public function prepareAllowanceRevocation(): array
    {
        [$httpStatus, $body] = $this->post('/v1/allowances/revoke/prepare', []);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * The one read with no body. Sent through the same transport as everything else, so a host that
     * injects its own HTTP client keeps one place to configure.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function get(string $path): array
    {
        return $this->post($path, []);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function post(string $path, array $payload): array
    {
        if ($this->transport !== null) {
            [$httpStatus, $body] = ($this->transport)($this->apiUrl . $path, $payload, $this->timeout);

            return [(int) $httpStatus, is_array($body) ? $body : []];
        }

        if (!class_exists(CurlTransport::class)) {
            throw new P2FluxException(
                'NETWORK_ERROR',
                'RETRY_LATER',
                ['detail' => 'no transport: pass one, or load P2Flux\\CurlTransport']
            );
        }

        return (self::$defaultTransport ??= new CurlTransport($this->timeout))($this->apiUrl . $path, $payload, $this->timeout);
    }


    /**
     * Lock the terms of a refund the merchant is about to send from their own wallet.
     *
     * A refund is a plain USDC transfer, merchant to buyer. P2Flux never holds the money, never
     * sends it, charges nothing for it, and returns none of its original commission - the merchant
     * absorbs that. Gas is the merchant's.
     *
     * Nothing you pass here decides where the money goes. The payer, the merchant and the maximum
     * are derived from the original settlement on chain, so a compromised plugin cannot turn a
     * refund button into a withdrawal form.
     *
     * `$original` identifies the settlement: `['intent' => ..., 'tx_hash' => ...]` for a one-time
     * payment, or `['subscription' => ..., 'tx_hash' => ..., 'period_index' => ...]` for one
     * recurring period. Refunds are always per charge, never per subscription.
     *
     * `$amountUnits` is micro-USDC as an integer string - "10000000" is 10.00 USDC. Decimals are
     * refused, because a partial refund computed in floating point is a rounding bug.
     *
     * The returned `refund_token` is a SHORT-LIVED browser capability: open
     * `<checkout>/#/refund/<refund_token>` so the merchant's wallet can send the transfer. Do not
     * store it. Reconcile later with `verifyRefund()`, which needs no token.
     *
     * IMPORTANT: P2Flux keeps no refund history and therefore cannot tell you whether this payment
     * was already refunded. One refund per payment is YOUR record to enforce - reserve the order row
     * before calling this, not after.
     *
     * @param array{intent?: string, subscription?: string, tx_hash: string, period_index?: int} $original
     * @return array{refund_token: string, refund_amount: string, merchant: string, payer: string, ...}
     */
    public function prepareRefund(array $original, string $amountUnits): array
    {
        [$httpStatus, $body] = $this->post('/v1/refunds/prepare', $original + ['amount' => $amountUnits]);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * Did the refund actually happen, and has it settled?
     *
     * Takes the ORIGINAL settlement rather than the prepare token, deliberately: a refund may need
     * reconciling hours or days later, after a crash or a support ticket, and a fifteen-minute
     * bearer token cannot answer that. Everything is re-derived from the chain each time.
     *
     * A transaction hash is not a refund. This checks that the receipt carries exactly one USDC
     * transfer from the original merchant to the original payer for exactly this amount, and that
     * it is settled to the configured depth. The transfer is matched by EVENT, not by transaction
     * sender, so a Safe or smart account executing on the merchant's behalf verifies correctly.
     *
     * Still confirming is `REFUND_CONFIRMING`, which is a waiting state: poll the SAME hash. Never
     * send a second refund because the first has not confirmed yet.
     *
     * @param array{intent?: string, subscription?: string, tx_hash: string, period_index?: int} $original
     * @return array{status?: string, refund_tx_hash?: string, code?: string, error?: string, ...}
     */
    public function verifyRefund(array $original, string $amountUnits, string $refundTxHash): array
    {
        [$httpStatus, $body] = $this->post(
            '/v1/refunds/verify',
            $original + ['refund_amount' => $amountUnits, 'refund_tx_hash' => $refundTxHash]
        );

        /* Still confirming is an ANSWER, not an exception - this method's own documentation says so,
         * and it used to throw anyway. A caller forced to catch an exception to learn "wait a
         * moment" is a caller that eventually sends a second refund.
         *
         * The API answers 409 for this as of 2026-08-21, matching PAYMENT_CONFIRMING; it previously
         * answered 400. Keyed on the CODE rather than the status, so both behave identically and an
         * older deployment keeps working. */
        $code = isset($body['code']) ? (string) $body['code'] : (isset($body['error']) ? (string) $body['error'] : '');
        if ($httpStatus >= 400 && $code !== 'REFUND_CONFIRMING') {
            $this->throwIfError($httpStatus, $body);
        }

        return $body;
    }

    /**
     * What a refund token authorizes, read back by the page that holds it. The browser-facing
     * counterpart of prepareRefund(); reconciliation still uses verifyRefund(), which needs no
     * token at all.
     *
     * @return array<string, mixed>
     */
    public function resolveRefund(string $refundToken): array
    {
        [$httpStatus, $body] = $this->post('/v1/refunds/resolve', ['refund_token' => $refundToken]);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function throwIfError(int $httpStatus, array $body): void
    {
        if ($httpStatus < 400) {
            return;
        }
        $status = (string) ($body['error'] ?? 'INTERNAL_ERROR');
        throw new P2FluxException($status, (string) ($body['action'] ?? self::ACTIONS[$status] ?? 'RETRY_LATER'), $body);
    }
}
