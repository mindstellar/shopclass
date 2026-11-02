<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\billing\event;

/**
 * A cart line: an id, a label, an amount in micros, a currency and free-form meta.
 */
final class Line
{
    /** Most lines one event may hold; later ones are refused. */
    public const MAX_PER_EVENT = 20;

    /** Longest label; a longer one is cut. */
    public const MAX_LABEL = 200;

    /**
     * @param array<string,mixed> $meta
     */
    public function __construct(
        private string $id,
        private string $label,
        private int $amount,
        private string $currency,
        private array $meta = []
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function amount(): int
    {
        return $this->amount;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * @return array<string,mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    /**
     * @return array{id:string,label:string,amount:int,currency:string,meta:array<string,mixed>}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'amount' => $this->amount, 'currency' => $this->currency, 'meta' => $this->meta];
    }

    /**
     * Why a line of $kind breaks the cart's rules, or null when it keeps them: it needs a label,
     * an id of 1-64 of a-z, 0-9, `_`, `.` and `-`, the cart's currency, and no amount below zero
     * unless it is a discount.
     */
    public static function fault(string $id, string $label, int $amount, string $currency, string $kind, string $cartCurrency): ?string
    {
        if (trim($label) === '') {
            return 'A cart line needs a label.';
        }
        if ($currency !== $cartCurrency) {
            return sprintf('The line "%s" is in %s; the cart is in %s.', $id, $currency, $cartCurrency);
        }
        if (preg_match('/^[a-z0-9_.-]{1,64}$/', $id) !== 1) {
            return sprintf('"%s" is not a line id.', $id);
        }
        if ($amount < 0 && $kind !== 'discount') {
            return sprintf('The %s line "%s" is below zero.', $kind, $id);
        }

        return null;
    }

    /**
     * A line that keeps the rules as core keeps it: the label trimmed and cut to MAX_LABEL and a
     * discount always taking off.
     *
     * @param array<string,mixed> $meta
     *
     * @return array{id:string,label:string,amount:int,currency:string,meta:array<string,mixed>}
     */
    public static function row(string $id, string $label, int $amount, string $currency, string $kind, array $meta = []): array
    {
        return [
            'id'       => $id,
            'label'    => mb_substr(trim($label), 0, self::MAX_LABEL, 'UTF-8'),
            'amount'   => $kind === 'discount' ? -abs($amount) : $amount,
            'currency' => $currency,
            'meta'     => $meta,
        ];
    }

    /**
     * The line an array describes, or null when it is not one.
     *
     * @param mixed $line
     */
    public static function fromArray($line, string $currency = ''): ?self
    {
        if (!is_array($line) || !is_numeric($line['amount'] ?? null)) {
            return null;
        }

        return new self(
            is_string($line['id'] ?? null) ? $line['id'] : '',
            is_string($line['label'] ?? null) ? $line['label'] : '',
            (int) $line['amount'],
            is_string($line['currency'] ?? null) ? $line['currency'] : $currency,
            is_array($line['meta'] ?? null) ? $line['meta'] : []
        );
    }
}
