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
        /* Payment links: a link this deployment did not sign, one past its date, a kind not offered
         * (or its contract replaced, or the subscription store full), a wallet already subscribed. */
        'INVALID_LINK' => 'INVALID_REQUEST',
        'LINK_EXPIRED' => 'INVALID_REQUEST',
        'LINK_UNAVAILABLE' => 'INVALID_REQUEST',
        'ALREADY_SUBSCRIBED' => 'INVALID_REQUEST',
        /* Sanctions screening (no KYC): the paying wallet is on the U.S. OFAC list or restricted by the
         * token issuer - stop charging it; the merchant's receiving wallet is on the OFAC list - nothing
         * can be created for or sent to it. Nothing was submitted in either case. */
        'PAYER_SANCTIONED' => 'STOP_SUBSCRIPTION',
        'RECIPIENT_SANCTIONED' => 'INVALID_REQUEST',
        // Signed webhooks (verifyWebhook): not signed by P2Flux, or signed too long ago.
        'WEBHOOK_SIGNATURE_INVALID' => 'INVALID_REQUEST',
        'WEBHOOK_TOO_OLD' => 'INVALID_REQUEST',
    ];

    private string $apiUrl;
    private int $timeout;
    /** The configured checkout address, as given; validated only by checkoutLink(). */
    private ?string $checkoutUrl;

    /** P2Flux's hosted checkout, per API host. */
    private const HOSTED_CHECKOUT = [
        'api.p2flux.com' => 'https://pay.p2flux.com',
        'api-test.p2flux.com' => 'https://pay-test.p2flux.com',
    ];

    /** `link` opens a payment link for a buyer; `links` is the merchant's private overview of one. */
    private const CHECKOUT_PAGES = ['pay', 'subscribe', 'cancel', 'refund', 'approve', 'link', 'links'];
    /** @var null|callable(string, array<string, mixed>, int): array{0: int, 1: array<string, mixed>} */
    private $transport;

    /* The curl transport, built once and only when something actually needs it. A host that passes
     * its own transport never loads the class at all, which is the point: a WordPress plugin
     * vendoring this SDK must be able to ship it without shipping a curl call. */
    private static ?CurlTransport $defaultTransport = null;

    /** @var null|array<string, string> The webhook signing keys from capabilities (kid => base64url). */
    private ?array $signingKeys = null;
    private int $signingKeysAt = 0;

    /**
     * @param array{apiUrl: string, timeout?: int, transport?: callable, checkoutUrl?: string} $options
     *        timeout defaults to 60 s: a charge waits for on-chain confirmation, which on a busy
     *        public RPC can take tens of seconds. Abandoning it early is safe but noisy - the
     *        payment may still land, and the next call returns ALREADY_CHARGED.
     *
     *        transport replaces curl with your own HTTP client - a host framework's (WordPress's
     *        wp_remote_post, Guzzle, Symfony HttpClient) or a stub in tests. It receives the
     *        absolute URL, the payload array and the timeout, and must return
     *        [int $httpStatus, array $decodedBody]. Throw P2FluxException('NETWORK_ERROR', ...)
     *        when the request never reached the API; charge() turns that into a retryable result.
     *
     *        checkoutUrl is where buyers open the checkout, for checkoutLink(). Defaults to P2Flux's
     *        hosted checkout for the API you use (api.p2flux.com -> https://pay.p2flux.com,
     *        api-test.p2flux.com -> https://pay-test.p2flux.com). Set it when you host the checkout
     *        yourself, e.g. https://pay.yourcompany.com or https://yourcompany.com/pay.
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
        /* Kept as given and checked only when a link is built: an optional setting with a typo must not
         * stop this client from creating, verifying or charging payments. Empty means not set. */
        $checkoutUrl = $options['checkoutUrl'] ?? null;
        $this->checkoutUrl = is_string($checkoutUrl) && trim($checkoutUrl) !== '' ? trim($checkoutUrl) : null;
    }

    /**
     * Does this on-chain `$reference` commit to your `$merchantRef`? True exactly when
     * reference == keccak256("p2flux.merchant_ref.v1" || salt || merchant_ref), computed here with no
     * call to P2Flux - so you, or an auditor you hand the salt to, can prove which order a payment on
     * chain settled. `$salt` is the `reference_salt` the payment came back with. Malformed input is
     * false. Pure PHP (P2Flux\Keccak), no extension.
     */
    public static function checkMerchantRef(string $reference, string $salt, string $merchantRef): bool
    {
        if (preg_match('/^0x[0-9a-fA-F]{64}$/', $reference) !== 1 || preg_match('/^0x[0-9a-fA-F]{64}$/', $salt) !== 1
            || preg_match('~^[A-Za-z0-9._:/#-]{1,64}$~', $merchantRef) !== 1) {
            return false;
        }
        if (!class_exists(Keccak::class)) {
            require_once __DIR__ . '/Keccak.php';
        }

        return Keccak::hash256('p2flux.merchant_ref.v1' . hex2bin(substr($salt, 2)) . $merchantRef) === strtolower($reference);
    }

    /**
     * Check a webhook's `p2flux-signature` header and return the decoded event.
     *
     * Pass the body EXACTLY as received (file_get_contents('php://input'), before any decoding) and
     * the header's value ($_SERVER['HTTP_P2FLUX_SIGNATURE']). Throws P2FluxException
     * `WEBHOOK_SIGNATURE_INVALID` (not signed by P2Flux, or changed) or `WEBHOOK_TOO_OLD` (signed more
     * than `tolerance_sec` from now - a replay, or a clock to fix); both have action INVALID_REQUEST.
     *
     * A valid signature proves P2Flux sent this event, not that money moved: still decide on money
     * with verifyPayment() (or handleNotification(), which does both).
     *
     * @param array{keys?: array<string, string>, tolerance_sec?: int, now?: int} $options
     *        keys: kid => base64url public key; default the API's own, from capabilities(), fetched
     *        once per client. tolerance_sec defaults to 300; now (unix seconds) is for tests.
     * @return array<string, mixed>
     */
    public function verifyWebhook(string $rawBody, string $header, array $options = []): array
    {
        $parts = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) === 2) {
                $parts[trim($pair[0])] = trim($pair[1]);
            }
        }
        $t = $parts['t'] ?? '';
        $kid = $parts['kid'] ?? '';
        $invalid = new P2FluxException('WEBHOOK_SIGNATURE_INVALID', 'INVALID_REQUEST');
        if (preg_match('/^\d{1,12}$/', $t) !== 1 || $kid === '' || ($parts['v1'] ?? '') === '') {
            throw $invalid;
        }
        $key = isset($options['keys']) ? ($options['keys'][$kid] ?? null) : $this->signingKey($kid);
        $publicKey = is_string($key) ? self::base64UrlDecode($key) : null;
        $signature = self::base64UrlDecode($parts['v1']);
        try {
            $ok = $publicKey !== null && $signature !== null
                && sodium_crypto_sign_verify_detached($signature, "{$t}.{$rawBody}", $publicKey);
        } catch (\SodiumException) {
            $ok = false;
        }
        if (!$ok) {
            throw $invalid;
        }
        // Checked after the signature, so a stale answer is only ever given about a genuine P2Flux event.
        if (abs(($options['now'] ?? time()) - (int) $t) > ($options['tolerance_sec'] ?? 300)) {
            throw new P2FluxException('WEBHOOK_TOO_OLD', 'INVALID_REQUEST');
        }
        $event = json_decode($rawBody, true);
        if (!is_array($event)) {
            throw new P2FluxException('WEBHOOK_SIGNATURE_INVALID', 'INVALID_REQUEST', ['reason' => 'MALFORMED']);
        }

        return $event;
    }

    /**
     * A webhook signing key by kid, from capabilities: fetched once, again for a kid not seen yet (a
     * rotation) - but at most once a minute, so forged kids cannot make every check a fetch.
     */
    private function signingKey(string $kid): ?string
    {
        if ($this->signingKeys === null || (!isset($this->signingKeys[$kid]) && time() - $this->signingKeysAt > 60)) {
            $keys = [];
            foreach ($this->capabilities()['webhook_signing_keys'] ?? [] as $k) {
                if (is_string($k['kid'] ?? null) && is_string($k['public_key'] ?? null)) {
                    $keys[$k['kid']] = $k['public_key'];
                }
            }
            $this->signingKeys = $keys;
            $this->signingKeysAt = time();
        }

        return $this->signingKeys[$kid] ?? null;
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }
        $bytes = base64_decode(strtr($value, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }

    /**
     * The address that opens a checkout page for a token the API issued: 'pay' for a payment
     * intent, 'subscribe' for a setup token, 'cancel', 'refund' and 'approve' for theirs. The token
     * goes in the fragment, which browsers never send to a server or put in a Referer header.
     *
     * @throws \InvalidArgumentException for an unknown page, an empty token, or when no checkout
     *         address is known (an API address of your own needs an explicit checkoutUrl)
     */
    public function checkoutLink(string $page, string $token): string
    {
        if (!in_array($page, self::CHECKOUT_PAGES, true)) {
            throw new \InvalidArgumentException("unknown checkout page: $page");
        }
        if ($token === '') {
            throw new \InvalidArgumentException('checkoutLink needs a token');
        }
        $base = self::checkoutBase($this->apiUrl, $this->checkoutUrl);
        if ($base === null) {
            throw new \InvalidArgumentException('checkoutUrl is required for this apiUrl');
        }
        return $base . '/#/' . $page . '/' . rawurlencode($token);
    }

    private static function checkoutBase(string $apiUrl, ?string $checkoutUrl): ?string
    {
        if ($checkoutUrl === null) {
            $host = parse_url($apiUrl, PHP_URL_HOST);
            return is_string($host) ? (self::HOSTED_CHECKOUT[strtolower($host)] ?? null) : null;
        }
        $parts = parse_url($checkoutUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException("checkoutUrl is not a URL: $checkoutUrl");
        }
        $local = in_array(strtolower($parts['host']), ['localhost', '127.0.0.1'], true);
        if (strtolower($parts['scheme']) !== 'https' && !($local && strtolower($parts['scheme']) === 'http')) {
            throw new \InvalidArgumentException('checkoutUrl must be https (http only for localhost)');
        }
        if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('checkoutUrl must not carry credentials, a query or a fragment');
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return rtrim(strtolower($parts['scheme']) . '://' . $parts['host'] . $port . ($parts['path'] ?? ''), '/');
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
     * Optional `merchant_ref` (your own number, rules as for createPayment()): carried in the signed
     * setup and echoed by resolveSubscription() and status(). Not part of any on-chain value.
     *
     * @param array{recipient: string, amount: string, period: int, end?: int, allowance?: string|array{periods: int}, merchant_ref?: string} $terms
     * @return array{setup_token: string, salt: string, amount: string, merchant_ref?: string, ...}
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
     * `notify_url` is optional: a public https URL P2Flux POSTs a `payment.paid` event to once the
     * payment is verified on chain. Omit it and nothing changes. Answer it with handleNotification().
     *
     * `return_url` is optional: where the hosted checkout sends the buyer after paying (https; http on
     * the test network only), with `p2flux_reference`, `p2flux_tx` and `p2flux_status` (`paid` or
     * `confirming`) in the query. A convenience, never proof: confirm with verifyPayment() or a webhook.
     * The answer carries `checkout_url`, P2Flux's hosted page for this payment (absent from older APIs;
     * a self-hosted checkout uses checkoutLink() instead).
     *
     * `private_payment` is optional, Base only: `'hinkal'` also offers the buyer "Pay privately with
     * Hinkal" on the hosted checkout (paid from their Hinkal private balance; native gas only). You
     * receive the same amount and fee; such a payment cannot be refunded automatically - its
     * verification says `accounting.refundable === false`.
     *
     * `merchant_ref` is optional: your own order or invoice number (ASCII letters, digits and
     * `. _ : / # -`, 1-64 characters; no spaces, no `@`). Signed, not encrypted - the buyer can read
     * it, so never personal data. The answer carries it back with `reference_salt`, and the on-chain
     * `reference` then equals keccak256("p2flux.merchant_ref.v1" || reference_salt || merchant_ref),
     * so you or an auditor can prove which order a payment settled. verifyPayment(), recoverPayment(),
     * resolvePayment(), verifyRefund() and the payment.paid webhook echo it. Needs an API with
     * merchant_ref (an older one refuses the field). Check the commitment with
     * P2FluxClient::checkMerchantRef() (no API call).
     *
     * @param array{recipient: string, amount: string, gas_payment_mode?: 'native'|'payment_token', notify_url?: string, return_url?: string, private_payment?: 'hinkal', merchant_ref?: string} $terms
     * @return array{intent: string, reference: string, amount: string, notify_url?: string, return_url?: string, private_payment?: 'hinkal', checkout_url?: string, merchant_ref?: string, reference_salt?: string, ...}
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
     *     screening?: 'clear'|'sanctioned'|'unknown',
     *     merchant_ref?: string,
     *     reference_salt?: string,
     *     accounting?: array{
     *         payment_units: string,
     *         payment_fee_units: string,
     *         network_fee_units: string,
     *         fixed_network_fee_units: string,
     *         merchant_net_units: string,
     *         buyer_total_units: string,
     *         payer: string,
     *         refundable?: false,
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
     *     merchant_ref?: string,
     *     reference_salt?: string,
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
     *
     * `$merchantRef`: this charge's own invoice number (rules as for createPayment()'s merchant_ref),
     * echoed as $result->merchantRef and by recoverCharge(). Needs an API with merchant_ref.
     */
    public function charge(string $subscriptionRef, ?string $merchantRef = null): ChargeResult
    {
        try {
            [, $body] = $this->post('/v1/charges', ['subscription' => $subscriptionRef] + ($merchantRef === null ? [] : ['merchant_ref' => $merchantRef]));
        } catch (P2FluxException $e) {
            /* The exception's raw body rides along (curl's `detail`, for one), because the place an
             * operator most needs to know WHY the API was unreachable is their renewal job's log -
             * and this used to be the one place that reason was dropped. */
            return ChargeResult::fromArray(['status' => 'NETWORK_ERROR'] + $e->raw);
        }

        return ChargeResult::fromArray($body);
    }

    /**
     * Create a payment link - nothing is stored. Send `checkoutLink('link', $link['link'])` to buyers
     * (e-mail, chat, QR code); keep `$link['manage']` for yourself (`checkoutLink('links', ...)`).
     *
     * `kind`: `once` (an invoice the contract lets settle only once), `reusable` (a fixed price) or
     * `subscription` (a plan P2Flux collects every `period` seconds, at least a day; `periods` limits
     * the number of charges). Optional `label` (up to 60 characters, no web addresses), `expires_at`
     * (unix seconds) and, for one-time kinds, `gas_payment_mode`. Optional `notify_url`: a webhook for
     * every payment through the link (`payment.paid`, or `subscription.charged` / `subscription.updated`).
     * Optional `return_url`: as for createPayment() (subscriptions: `p2flux_subscription` instead of
     * `p2flux_reference`). The answer carries `checkout_url`, the hosted page for the link.
     * Optional `private_payment` (`'hinkal'`, `once` and `reusable` links, not subscriptions): as for createPayment().
     * Optional `merchant_ref` (rules as for createPayment()): carried in the signed link (the buyer can
     * read it) and echoed by paymentLinkStatus() and the link's webhooks. The on-chain reference of a
     * link payment does not commit to it. A self-hosted checkout must be new enough to accept it.
     *
     * @param array{kind: string, recipient: string, amount: string, label?: string, expires_at?: int, gas_payment_mode?: string, period?: int, periods?: int, suspend_after_days?: int, notify_url?: string, return_url?: string, private_payment?: 'hinkal', merchant_ref?: string} $terms
     * @return array{link: string, manage: string, kind: string, id: string, recipient: string, amount: string, amount_units: string, created_at: int, expires_at: int, return_url?: string, checkout_url?: string, merchant_ref?: string, ...}
     */
    public function createPaymentLink(array $terms): array
    {
        [$httpStatus, $body] = $this->post('/v1/links', $terms);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * For a checkout of your own: the intent (one-time kinds) or setup token (subscriptions) a link
     * opens into. The P2Flux checkout calls this itself.
     *
     * @return array<string, mixed>
     */
    public function openPaymentLink(string $link, ?string $payer = null): array
    {
        [$httpStatus, $body] = $this->post('/v1/links/open', ['link' => $link] + ($payer === null ? [] : ['payer' => $payer]));
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * For a checkout of your own: join a subscription link with the capability the buyer just signed
     * (finalizeSubscription). P2Flux charges the first period at once and keeps the subscription only
     * when that charge landed or is on its way. Same answers as charge(); never throws. The raw answer
     * also says who collects the renewals (`collected_by`).
     */
    public function subscribePaymentLink(string $link, string $subscriptionRef): ChargeResult
    {
        try {
            [, $body] = $this->post('/v1/links/subscribe', ['link' => $link, 'subscription' => $subscriptionRef]);
        } catch (P2FluxException $e) {
            return ChargeResult::fromArray(['status' => 'NETWORK_ERROR'] + $e->raw);
        }

        return ChargeResult::fromArray($body);
    }

    /**
     * What a link has collected. Pass `['link' => ...]` for the public view (invoice paid or not) or
     * `['manage' => ...]` for yours: who paid, every payment read from chain (`complete: false` means
     * ask again for more), every subscriber of a subscription link.
     *
     * @param array{link?: string, manage?: string} $token
     * @return array{kind: string, state: string, paid?: bool, payment?: array<string, mixed>, payments?: list<array<string, mixed>>, complete?: bool, subscribers?: list<array<string, mixed>>, ...}
     */
    public function paymentLinkStatus(array $token): array
    {
        [$httpStatus, $body] = $this->post('/v1/links/status', $token);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * Answer a webhook from P2Flux: the raw body of the POST to your `notify_url` (string) or its
     * decoded array.
     *
     * A notification is a hint, never a proof: it carries no secret. This asks the API again - verify
     * for a payment, the manage view for a subscription - and checks the answer belongs to YOUR order.
     * Pass `reference` (stored with the order from createPayment) or your payment link's `link_id`, plus
     * its `manage` token for subscription events.
     *
     * - `payment.paid` confirmed: fulfil idempotently on `dedupe_key` (`reference:tx_hash`, from the API's
     *   verdict - never the event's own id, which anyone can make up).
     * - `subscription.charged` confirmed: the subscriber is paid through `subscriber.last_period`; grant
     *   access up to there (idempotent on `dedupe_key`), never "one more period per event".
     * - `subscription.updated` confirmed: apply `subscriber.state` as it is now (no `dedupe_key`).
     *
     * Answer the POST with a 2xx when this returns. It THROWS P2FluxException when the answer is not
     * final yet (a payment still confirming, the API busy or unreachable): answer 5xx then, and P2Flux
     * sends it again. A wrong, forged or replayed event returns `confirmed => false`, and when the event
     * does not even name your order the API is not asked. InvalidArgumentException on a missing or
     * malformed `$expect`.
     *
     * Optional `signature`: the `p2flux-signature` header. Given, the raw body (a string) is checked
     * first (verifyWebhook(), with `keys` / `tolerance_sec` as there) and a bad or stale one returns
     * `confirmed => false` with reason WEBHOOK_SIGNATURE_INVALID / WEBHOOK_TOO_OLD.
     *
     * @param string|array<string, mixed> $notification
     * @param array{reference?: string, link_id?: string, manage?: string, signature?: string, keys?: array<string, string>, tolerance_sec?: int} $expect
     * A confirmed payment.paid for a payment created with `merchant_ref` also carries `merchant_ref` and
     * `reference_salt`, taken from the verified answer (never from the event body).
     *
     * @return array{confirmed: bool, event_id: string, type: string, dedupe_key?: string, reason?: string, payment?: array<string, mixed>, subscriber?: array<string, mixed>, merchant_ref?: string, reference_salt?: string}
     */
    public function handleNotification(string|array $notification, array $expect): array
    {
        $reference = is_string($expect['reference'] ?? null) ? strtolower($expect['reference']) : '';
        $linkId = is_string($expect['link_id'] ?? null) ? strtolower($expect['link_id']) : '';
        if ($reference === '' && $linkId === '') {
            throw new \InvalidArgumentException('handleNotification needs reference or link_id in $expect');
        }
        // A malformed id would match too much ('0x' is a prefix of every reference).
        if (($reference !== '' && preg_match('/^0x[0-9a-f]{64}$/', $reference) !== 1) || ($linkId !== '' && preg_match('/^0x[0-9a-f]{32}$/', $linkId) !== 1)) {
            throw new \InvalidArgumentException('reference must be the 32-byte reference from createPayment, link_id the 16-byte link id');
        }
        if (isset($expect['signature'])) {
            if (!is_string($notification)) {
                throw new \InvalidArgumentException('signature needs the raw body as a string');
            }
            try {
                $this->verifyWebhook($notification, (string) $expect['signature'], array_intersect_key($expect, ['keys' => 1, 'tolerance_sec' => 1]));
            } catch (P2FluxException $e) {
                if ($e->action !== 'INVALID_REQUEST') {
                    throw $e;
                }

                return ['confirmed' => false, 'event_id' => '', 'type' => '', 'reason' => $e->status];
            }
        }
        $event = is_string($notification) ? json_decode($notification, true) : $notification;
        if (!is_array($event)) {
            return ['confirmed' => false, 'event_id' => '', 'type' => '', 'reason' => 'MALFORMED'];
        }
        $eventId = is_string($event['id'] ?? null) ? $event['id'] : '';
        $type = is_string($event['type'] ?? null) ? $event['type'] : '';
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $no = static fn (string $reason, array $extra = []): array => ['confirmed' => false, 'event_id' => $eventId, 'type' => $type, 'reason' => $reason] + $extra;
        $ours = static fn (mixed $ref): bool => is_string($ref) && preg_match('/^0x[0-9a-f]{64}$/', strtolower($ref)) === 1
            && ($reference !== '' ? strtolower($ref) === $reference : str_starts_with(strtolower($ref), $linkId));
        // An answer about the request (a bad intent, an unknown link) is a verdict; the API being unreachable is not.
        $asked = static function (callable $work) use ($no): array {
            try {
                return ['ok' => $work()];
            } catch (P2FluxException $e) {
                if ($e->action === 'INVALID_REQUEST') {
                    return ['no' => $no($e->status)];
                }
                throw $e;
            }
        };

        if ($type === 'payment.paid') {
            if (!is_string($data['intent'] ?? null) || !is_string($data['tx_hash'] ?? null)) {
                return $no('MALFORMED');
            }
            // Read (not trusted) locally: an intent for another order is refused without spending an API call.
            if (!$ours($data['reference'] ?? null) || !$ours(self::intentReference($data['intent']))) {
                return $no('NOT_YOUR_ORDER');
            }
            $answer = $asked(fn () => $this->verifyPayment($data['intent'], $data['tx_hash']));
            if (isset($answer['no'])) {
                return $answer['no'];
            }
            $verdict = $answer['ok'];
            if (($verdict['valid'] ?? false) !== true) {
                $code = is_string($verdict['code'] ?? null) ? $verdict['code'] : 'INTERNAL_ERROR';
                $action = self::ACTIONS[$code] ?? 'RETRY_LATER';
                // Not final yet (still confirming, RPC trouble): thrown, so the endpoint answers 5xx and it comes again.
                if ($action === 'WAIT' || $action === 'RETRY_LATER') {
                    throw new P2FluxException($code, $action, $verdict);
                }

                return $no($code, ['payment' => $verdict]);
            }
            // The reference that counts is the API's, from the verdict - not the one in the notification.
            if (!$ours($verdict['reference'] ?? null)) {
                return $no('NOT_YOUR_ORDER', ['payment' => $verdict]);
            }

            return ['confirmed' => true, 'event_id' => $eventId, 'type' => $type,
                'dedupe_key' => strtolower((string) $verdict['reference']) . ':' . strtolower((string) ($verdict['tx_hash'] ?? '')), 'payment' => $verdict]
                + array_filter(['merchant_ref' => $verdict['merchant_ref'] ?? null, 'reference_salt' => $verdict['reference_salt'] ?? null], 'is_string');
        }

        if ($type === 'subscription.charged' || $type === 'subscription.updated') {
            $manage = (string) ($expect['manage'] ?? '');
            if ($manage === '' || $linkId === '') {
                throw new \InvalidArgumentException("subscription events need link_id and manage (the link's manage token) in \$expect");
            }
            if ((is_string($data['link_id'] ?? null) ? strtolower($data['link_id']) : '') !== $linkId) {
                return $no('NOT_YOUR_ORDER');
            }
            $period = $data['period_index'] ?? null;
            if ($type === 'subscription.charged' && (!is_int($period) || $period < 0)) {
                return $no('MALFORMED');
            }
            $answer = $asked(fn () => $this->paymentLinkStatus(['manage' => $manage]));
            if (isset($answer['no'])) {
                return $answer['no'];
            }
            $status = $answer['ok'];
            if ((is_string($status['id'] ?? null) ? strtolower($status['id']) : '') !== $linkId) {
                return $no('NOT_YOUR_ORDER');
            }
            foreach ($status['subscribers'] ?? [] as $subscriber) {
                $id = is_string($subscriber['subscription_id'] ?? null) ? strtolower($subscriber['subscription_id']) : '';
                if ($id === '' || $id !== (is_string($data['subscription_id'] ?? null) ? strtolower($data['subscription_id']) : '')) {
                    continue;
                }
                if ($type === 'subscription.updated') {
                    return ['confirmed' => true, 'event_id' => $eventId, 'type' => $type, 'subscriber' => $subscriber];
                }
                /* Only the subscriber's latest collected period: an older one (or one skipped and never
                 * paid) is not something to grant now - a newer charge event covers the subscription. */
                $last = is_int($subscriber['last_period'] ?? null) ? $subscriber['last_period'] : -1;
                if ($period !== $last) {
                    return $no($period < $last ? 'NOT_CURRENT' : 'NOT_CHARGED', ['subscriber' => $subscriber]);
                }

                return ['confirmed' => true, 'event_id' => $eventId, 'type' => $type, 'dedupe_key' => "{$id}:{$last}", 'subscriber' => $subscriber];
            }

            return $no('NOT_FOUND');
        }

        return $no('UNKNOWN_EVENT');
    }

    /** "Collect now" for one subscriber of your subscription link. Same answers as charge(); never throws. */
    public function collectPaymentLink(string $manage, string $subscriptionId): ChargeResult
    {
        try {
            [, $body] = $this->post('/v1/links/collect', ['manage' => $manage, 'subscription_id' => $subscriptionId]);
        } catch (P2FluxException $e) {
            return ChargeResult::fromArray(['status' => 'NETWORK_ERROR'] + $e->raw);
        }

        return ChargeResult::fromArray($body);
    }

    /**
     * Stop automatic collection for one subscriber (collectPaymentLink resumes it). Only the buyer's
     * wallet can revoke the permission on chain.
     *
     * @return array{subscription_id: string, state: string}
     */
    public function stopPaymentLink(string $manage, string $subscriptionId): array
    {
        [$httpStatus, $body] = $this->post('/v1/links/stop', ['manage' => $manage, 'subscription_id' => $subscriptionId]);
        $this->throwIfError($httpStatus, $body);

        return $body;
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
     * What P2Flux did with the webhooks of one payment (`['intent' => ...]`) or one payment link
     * (`['manage' => ...]`): every event, every attempt's time, HTTP status (0 = no answer) and
     * duration, and when the next try is due. Kept 7 days; no bodies, no URLs.
     *
     * @param array{intent?: string, manage?: string} $token
     * @return array{events: list<array{id: string, type: string, created_at: int, attempts: list<array{at: int, status: int, duration_ms: int}>, next_at: int|null, outcome: string}>, ...}
     */
    public function webhookDeliveries(array $token): array
    {
        [$httpStatus, $body] = $this->post('/v1/webhooks/deliveries', $token);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * "Retry now" for one webhook event that is still pending (see webhookDeliveries()): P2Flux sends
     * it at once instead of at its next scheduled try. At most 3 times per event per hour.
     *
     * @param array{intent?: string, manage?: string} $token
     * @return array<string, mixed>
     */
    public function redeliverWebhook(array $token, string $eventId): array
    {
        [$httpStatus, $body] = $this->post('/v1/webhooks/redeliver', $token + ['event_id' => $eventId]);
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
     * x402 paywall: what an AI agent must pay for one request to `$recipient` at `$price` USDC.
     * Put `accepts`, with the URL, in the `PAYMENT-REQUIRED` header of a 402 - or use
     * {@see Paywall}, which does all of it. Reusable for `ttl` seconds.
     *
     * @return array{x402Version: int, accepts: list<array<string, mixed>>, ttl: int}
     */
    public function paywallChallenge(string $recipient, string $price, bool $usage = false): array
    {
        // $usage: $price is the MOST one request can cost; the agent signs for up to it (x402 `upto`).
        [$httpStatus, $body] = $this->post('/x402/paywall/challenge', ['recipient' => $recipient, 'price' => $price] + ($usage ? ['usage' => true] : []));
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * x402 paywall: settle the agent's `PAYMENT-SIGNATURE` header for YOUR wallet and price. Nothing
     * the agent sent decides what is owed. `paid: true` - serve the content, once: a payment is paid
     * one time and the same header again answers `invalid_transaction_state`. A refusal is a normal
     * answer (`paid: false` with `reason`), not an exception.
     *
     * @return array{paid: bool, reason?: string, message?: string, transaction?: string, payer?: string, amount?: string, network?: string, payment_response?: string, scheme?: string, receipt?: string, payment_required?: string}
     */
    public function paywallRedeem(string $recipient, string $price, string $paymentHeader, string $resource = '', ?string $amount = null): array
    {
        // $amount: usage pricing only - what the request cost, at most $price.
        [$httpStatus, $body] = $this->post('/x402/paywall/redeem', [
            'recipient' => $recipient,
            'price' => $price,
            'payment' => $paymentHeader,
            'resource' => substr($resource, 0, 2048),
        ] + ($amount !== null ? ['amount' => $amount] : []));
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * x402 paywall, usage pricing: will the agent's payment settle, for up to `$maxPrice`? Nothing
     * moves. Do the work after `valid: true`, then `paywallRedeem(..., $amount)` with what it cost.
     *
     * @return array{valid: bool, payer?: string, reason?: string, message?: string}
     */
    public function paywallVerify(string $recipient, string $maxPrice, string $paymentHeader): array
    {
        [$httpStatus, $body] = $this->post('/x402/paywall/verify', ['recipient' => $recipient, 'price' => $maxPrice, 'payment' => $paymentHeader]);
        $this->throwIfError($httpStatus, $body);

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    /** The reference inside a P2Flux intent token, read without checking its signature (the API checks that). */
    private static function intentReference(string $intent): string
    {
        $part = explode('.', $intent)[2] ?? '';
        $json = base64_decode(strtr($part, '-_', '+/'), true);
        $payload = is_string($json) ? json_decode($json, true) : null;

        return is_array($payload) && is_string($payload['reference'] ?? null) ? $payload['reference'] : '';
    }

    private function throwIfError(int $httpStatus, array $body): void
    {
        if ($httpStatus < 400) {
            return;
        }
        $status = (string) ($body['error'] ?? 'INTERNAL_ERROR');
        throw new P2FluxException($status, (string) ($body['action'] ?? self::ACTIONS[$status] ?? 'RETRY_LATER'), $body);
    }
}
