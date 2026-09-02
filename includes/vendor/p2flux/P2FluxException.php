<?php

declare(strict_types=1);

namespace P2FluxWC\Vendor\P2Flux;

/**
 * Thrown by the non-charge calls (status, prepare*) where any failure really is exceptional.
 * charge() never throws for a payment outcome - it returns a ChargeResult instead.
 */
final class P2FluxException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public readonly string $status,
        public readonly string $action,
        public readonly array $raw = [],
    ) {
        parent::__construct($status);
    }
}
