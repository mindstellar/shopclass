<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
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
    public const STATUS_SHIPPED   = 'shipped';
    public const STATUS_DELIVERED = 'delivered';

    public const STATUSES = array(
        self::STATUS_PENDING,
        self::STATUS_PAID,
        self::STATUS_FAILED,
        self::STATUS_REFUNDED,
        self::STATUS_CANCELLED,
        self::STATUS_SHIPPED,
        self::STATUS_DELIVERED,
    );

    /** The statuses where the money is in: a goods order moves on to shipped and delivered. */
    public const SETTLED = array(self::STATUS_PAID, self::STATUS_SHIPPED, self::STATUS_DELIVERED);

    /** s_meta `_kind` of an order for goods, not credits. */
    public const KIND_GOODS = 'goods';

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
     * Whether the money is in: paid, or a goods order since shipped or delivered. A cash on
     * delivery order on its way is not paid yet.
     *
     * @return bool
     */
    public function isPaid(): bool
    {
        return in_array($this->status, self::SETTLED, true) && !$this->awaitsCash();
    }

    /**
     * A cash on delivery order shipped before the courier collected: it is paid once delivered.
     *
     * @return bool
     */
    public function awaitsCash(): bool
    {
        return $this->status === self::STATUS_SHIPPED && $this->paidDate === null && $this->gateway === gateway\CashOnDeliveryGateway::ID;
    }

    /**
     * Whether the admin may mark it shipped: a paid goods order, or a cash on delivery one not
     * paid yet.
     *
     * @return bool
     */
    public function canShip(): bool
    {
        return $this->isGoods() && (
            $this->status === self::STATUS_PAID
            || ($this->status === self::STATUS_PENDING && $this->gateway === gateway\CashOnDeliveryGateway::ID)
        );
    }

    /**
     * Whether this order sells goods (lines in t_billing_order_line) rather than credits.
     *
     * @return bool
     */
    public function isGoods(): bool
    {
        return $this->meta('_kind') === self::KIND_GOODS;
    }

    /**
     * What the order is for, in a line a gateway can show: "Tee × 2 and 1 more", or "100 credits".
     *
     * @return string
     */
    public function getLabel(): string
    {
        $label = $this->meta('_label');
        if (is_string($label) && $label !== '') {
            return $label;
        }

        return sprintf(_m('%d credits'), $this->credits);
    }

    /**
     * Where a goods order goes and how far it got: method, address, carrier, tracking,
     * tracking_url, shipped_at, delivered_at. Null for a credits order.
     *
     * @return array<string,mixed>|null
     */
    public function fulfilment(): ?array
    {
        if (!$this->isGoods()) {
            return null;
        }
        $shipping = $this->meta('_shipping');
        $shipping = is_array($shipping) ? $shipping : array();
        $address  = $this->meta('_address');

        return array(
            'method'       => isset($shipping['method']) ? (string) $shipping['method'] : null,
            'label'        => isset($shipping['label']) ? (string) $shipping['label'] : null,
            'address'      => is_array($address) ? $address : null,
            'carrier'      => isset($shipping['carrier']) ? (string) $shipping['carrier'] : null,
            'tracking'     => isset($shipping['tracking']) ? (string) $shipping['tracking'] : null,
            'tracking_url' => isset($shipping['url']) ? (string) $shipping['url'] : null,
            'shipped_at'   => isset($shipping['shipped_at']) ? (string) $shipping['shipped_at'] : null,
            'delivered_at' => isset($shipping['delivered_at']) ? (string) $shipping['delivered_at'] : null,
        );
    }

    /**
     * Whether the buyer may cancel it: a pending goods order paid outside the site. A card
     * order stays open, as its payment page may still take the money.
     *
     * @return bool
     */
    public function buyerMayCancel(): bool
    {
        return $this->isGoods() && $this->isPending() && in_array($this->gateway, Billing::HOLDS_STOCK, true);
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
