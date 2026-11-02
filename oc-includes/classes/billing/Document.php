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

namespace mindstellar\billing;

/**
 * One issued invoice or credit note, a t_billing_document row. It never changes after issue.
 *
 * @package mindstellar\billing
 */
final readonly class Document
{
    public const INVOICE     = 'invoice';
    public const CREDIT_NOTE = 'credit_note';
    public const TYPES       = array(self::INVOICE, self::CREDIT_NOTE);

    /**
     * @param int                 $id
     * @param string              $type     INVOICE or CREDIT_NOTE
     * @param string              $number   e.g. INV-2026-00042
     * @param string              $date     Issue datetime, site time
     * @param int                 $orderId
     * @param int                 $total    Micros, positive for both types
     * @param string              $currency ISO 4217
     * @param array<string,mixed> $data     The frozen document (s_data)
     */
    public function __construct(
        public int $id,
        public string $type,
        public string $number,
        public string $date,
        public int $orderId,
        public int $total,
        public string $currency,
        public array $data
    ) {
    }

    /**
     * Build from a t_billing_document row; unreadable s_data gives an empty array.
     *
     * @param array<string,mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $data = json_decode((string) ($row['s_data'] ?? ''), true);

        return new self(
            (int) $row['pk_i_id'],
            (string) $row['s_type'],
            (string) $row['s_number'],
            (string) $row['dt_date'],
            (int) $row['fk_i_order_id'],
            (int) $row['i_total'],
            (string) $row['s_currency'],
            is_array($data) ? $data : array()
        );
    }

    public function isInvoice(): bool
    {
        return $this->type === self::INVOICE;
    }

    public function isCreditNote(): bool
    {
        return $this->type === self::CREDIT_NOTE;
    }
}
