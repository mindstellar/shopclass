<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\billing;

/**
 * A gateway's reading of an inbound webhook or return-URL hit.
 *
 * The gateway has already verified the request's signature by the time it builds one of
 * these -- only the plugin knows its provider's scheme, so that check cannot live in
 * core. What core does with the result is verify the money: the amount and currency
 * reported here are checked against the stored order before anything is fulfilled.
 *
 * That division matters. A callback is attacker-reachable input; a gateway that trusts
 * its numbers ships the classic tampered-callback bug, where a request claiming a paid
 * order for a fraction of the price is honoured. Core performing the comparison means
 * no plugin author can forget it.
 *
 * @package mindstellar\billing
 */
final class CallbackResult
{
    /** Settle the order and mint its credits. */
    public const OUTCOME_PAID = 'paid';

    /** The payment attempt failed; the order is closed unpaid. */
    public const OUTCOME_FAILED = 'failed';

    /** A previously paid order was reversed by the provider. */
    public const OUTCOME_REFUNDED = 'refunded';

    /** Not ours, not actionable, or already handled. Core does nothing and returns 200. */
    public const OUTCOME_IGNORED = 'ignored';

    private function __construct(
        private string $outcome,
        private ?int $orderId = null,
        private ?string $externalRef = null,
        private ?int $amount = null,
        private ?string $currency = null,
        private string $message = ''
    ) {
    }

    /**
     * @param int         $orderId     Our t_billing_order id, recovered from the payload
     * @param string|null $externalRef The provider's own id, stored for reconciliation
     * @param int|null    $amount      Micros as reported by the provider; core compares
     *                                 it against the order and refuses on mismatch
     * @param string|null $currency    ISO 4217 as reported by the provider
     */
    public static function paid(
        int $orderId,
        ?string $externalRef = null,
        ?int $amount = null,
        ?string $currency = null
    ): self {
        return new self(self::OUTCOME_PAID, $orderId, $externalRef, $amount, $currency);
    }

    public static function failed(int $orderId, string $message = '', ?string $externalRef = null): self
    {
        return new self(self::OUTCOME_FAILED, $orderId, $externalRef, null, null, $message);
    }

    public static function refunded(int $orderId, ?string $externalRef = null): self
    {
        return new self(self::OUTCOME_REFUNDED, $orderId, $externalRef);
    }

    /**
     * Nothing to do. Use this for pings, unrecognised event types, and duplicates --
     * anything the provider should stop retrying but that changes no state here.
     */
    public static function ignored(string $message = ''): self
    {
        return new self(self::OUTCOME_IGNORED, null, null, null, null, $message);
    }

    public function getOutcome(): string
    {
        return $this->outcome;
    }

    public function getOrderId(): ?int
    {
        return $this->orderId;
    }

    public function getExternalRef(): ?string
    {
        return $this->externalRef;
    }

    /** Reported amount in micros, or null when the gateway does not echo one. */
    public function getAmount(): ?int
    {
        return $this->amount;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function getMessage(): string
    {
        return $this->message;
    }
}

/* file end: ./oc-includes/osclass/classes/billing/CallbackResult.php */
