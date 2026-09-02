<?php

declare(strict_types=1);

namespace P2FluxWC\Vendor\P2Flux;

/**
 * The outcome of one charge attempt, normalized.
 *
 * $ok is true for CHARGED and ALREADY_CHARGED: both mean "this billing period is paid". Treating
 * ALREADY_CHARGED as anything other than success is the classic integration bug - it is exactly
 * what a retry after a timeout or a crashed worker returns.
 *
 * CONFIRMING is neither: the transaction is on chain but not settled to the required depth, so $ok
 * is false and $retryable is true. Nothing local may be marked paid, and nothing may be charged
 * again - $txHash names the transaction the next call will reconcile.
 */
final class ChargeResult
{
    private function __construct(
        public readonly string $status,
        public readonly bool $ok,
        public readonly bool $alreadyPaid,
        public readonly string $action,
        public readonly bool $retryable,
        public readonly ?string $txHash,
        public readonly ?string $amount,
        public readonly ?string $subscriptionId,
        public readonly ?int $periodIndex,
        public readonly ?string $nextPeriodAt,
        /** @var array<string, mixed> The raw API body, for logs and forward compatibility. */
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromArray(array $body): self
    {
        $status = (string) ($body['status'] ?? $body['error'] ?? 'INTERNAL_ERROR');
        $action = (string) ($body['action'] ?? P2FluxClient::ACTIONS[$status] ?? 'RETRY_LATER');

        return new self(
            status: $status,
            ok: $status === 'CHARGED' || $status === 'ALREADY_CHARGED',
            alreadyPaid: $status === 'ALREADY_CHARGED',
            action: $action,
            // WAIT is retryable in the only sense that matters: ask the same question again.
            retryable: $action === 'RETRY_LATER' || $action === 'WAIT',
            txHash: isset($body['tx_hash']) ? (string) $body['tx_hash'] : null,
            amount: isset($body['amount']) ? (string) $body['amount'] : null,
            subscriptionId: isset($body['subscription_id']) ? (string) $body['subscription_id'] : null,
            periodIndex: isset($body['period_index']) ? (int) $body['period_index'] : null,
            nextPeriodAt: isset($body['next_period_at']) ? (string) $body['next_period_at'] : null,
            raw: $body,
        );
    }
}
