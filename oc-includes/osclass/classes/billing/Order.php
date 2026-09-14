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
 * A payment intent: who is paying, how much, in which currency, through which gateway,
 * and how many credits are minted when it settles.
 *
 * Immutable. A status change is a new row state read back from the database rather than
 * a mutation here, so an Order handed to a gateway plugin cannot be edited by it.
 *
 * The amount is in micros -- the value times 1,000,000 -- matching t_item.i_price. Money
 * is never a float anywhere in this subsystem: a float amount compared against a gateway
 * callback is a rounding bug waiting to reject a legitimate payment, or accept a short one.
 *
 * @package mindstellar\billing
 */
final class Order
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_PAID      = 'paid';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_REFUNDED  = 'refunded';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = array(
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_FAILED,
        self::STATUS_REFUNDED,
        self::STATUS_CANCELLED,
    );

    /** @var array<string,mixed> */
    private array $meta;

    /**
     * @param int                 $id          t_billing_order primary key
     * @param int                 $userId      Who is paying
     * @param string              $gateway     Id of the gateway handling the payment
     * @param string|null         $externalRef The provider's own id, once it has one
     * @param int                 $amount      Micros (value x 1,000,000)
     * @param string              $currency    ISO 4217 code
     * @param int                 $credits     Credits minted when the order settles
     * @param string              $status      One of the STATUS_* constants
     * @param array<string,mixed> $meta        Plugin-owned metadata
     * @param string              $date        Creation datetime
     * @param string|null         $paidDate    Settlement datetime, once paid
     */
    public function __construct(
        private int $id,
        private int $userId,
        private string $gateway,
        private ?string $externalRef,
        private int $amount,
        private string $currency,
        private int $credits,
        private string $status,
        array $meta = array(),
        private string $date = '',
        private ?string $paidDate = null
    ) {
        $this->meta = $meta;
    }

    /**
     * Build from a t_billing_order row. s_meta is plugin-owned JSON; malformed JSON
     * yields an empty array rather than an error, because a gateway's stored metadata
     * must never be able to break the admin order list.
     *
     * @param array $row
     *
     * @return Order
     */
    public static function fromRow(array $row): self
    {
        $meta = array();
        if (!empty($row['s_meta'])) {
            $decoded = json_decode((string) $row['s_meta'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        return new self(
            (int) $row['pk_i_id'],
            (int) $row['fk_i_user_id'],
            (string) $row['s_gateway'],
            isset($row['s_external_ref']) ? (string) $row['s_external_ref'] : null,
            (int) $row['i_amount'],
            (string) $row['s_currency'],
            (int) $row['i_credits'],
            (string) $row['s_status'],
            $meta,
            (string) ($row['dt_date'] ?? ''),
            isset($row['dt_paid_date']) ? (string) $row['dt_paid_date'] : null
        );
    }

    /**
     * The t_billing_order primary key.
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Who is paying.
     *
     * @return int
     */
    public function getUserId(): int
    {
        return $this->userId;
    }

    /**
     * Id of the gateway handling this payment.
     *
     * @return string
     */
    public function getGateway(): string
    {
        return $this->gateway;
    }

    /**
     * The provider's own id for the payment, or null before it reports one.
     *
     * @return string|null
     */
    public function getExternalRef(): ?string
    {
        return $this->externalRef;
    }

    /**
     * Amount in micros (value x 1,000,000).
     *
     * @return int
     */
    public function getAmount(): int
    {
        return $this->amount;
    }

    /**
     * ISO 4217 code, e.g. 'USD'.
     *
     * @return string
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * Credits minted when this order is paid.
     *
     * @return int
     */
    public function getCredits(): int
    {
        return $this->credits;
    }

    /**
     * Where the order stands: one of the STATUS_* constants.
     *
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Plugin-owned metadata, decoded from s_meta.
     *
     * @return array<string,mixed>
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * One metadata entry, or $default when the gateway never stored it.
     *
     * @param string $key
     * @param mixed  $default
     *
     * @return mixed
     */
    public function meta(string $key, $default = null)
    {
        return $this->meta[$key] ?? $default;
    }

    /**
     * When the order was created.
     *
     * @return string
     */
    public function getDate(): string
    {
        return $this->date;
    }

    /**
     * When the order settled, or null while it has not.
     *
     * @return string|null
     */
    public function getPaidDate(): ?string
    {
        return $this->paidDate;
    }

    /**
     * Whether the money is in and the credits are minted.
     *
     * @return bool
     */
    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /**
     * Whether this order is still awaiting settlement, and so may still transition.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}

/* file end: ./oc-includes/osclass/classes/billing/Order.php */
