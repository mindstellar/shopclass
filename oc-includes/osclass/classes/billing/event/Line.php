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
