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

use mindstellar\currency\Money;

/**
 * The invoice list as CSV, for the shop's accountant. Credit notes carry a minus sign.
 *
 * @package mindstellar\billing
 */
final class DocumentExport
{
    /** Documents read per query. */
    private const CHUNK = 500;

    /**
     * Every document the filters match, one row each.
     *
     * @param array{type?:string,from?:string,to?:string} $filters
     */
    public static function csv(array $filters): string
    {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, array('number', 'date', 'order', 'buyer', 'total', 'currency'), ',', '"', '');
        for ($offset = 0;; $offset += self::CHUNK) {
            $docs = DocumentStore::search($filters, self::CHUNK, $offset);
            foreach ($docs as $doc) {
                fputcsv($out, self::row($doc), ',', '"', '');
            }
            if (count($docs) < self::CHUNK) {
                break;
            }
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * @return list<string>
     */
    private static function row(Document $doc): array
    {
        $buyer    = (array) ($doc->data['buyer'] ?? array());
        $name     = trim((string) ($buyer['name'] ?? ''));
        $email    = trim((string) ($buyer['email'] ?? ''));
        $decimals = (int) ($doc->data['currency']['decimals'] ?? Money::decimals($doc->currency));
        $total    = Money::fromMicros(DocumentPage::signedTotal($doc), $decimals);

        return array(
            self::safe($doc->number),
            $doc->date,
            (string) $doc->orderId,
            self::safe($name === '' ? $email : trim($name . ($email !== '' ? ' <' . $email . '>' : ''))),
            $total,
            $doc->currency,
        );
    }

    /**
     * A cell a spreadsheet would run as a formula gets a leading quote.
     */
    private static function safe(string $cell): string
    {
        return $cell !== '' && str_contains("=+-@\t\r", $cell[0]) ? "'" . $cell : $cell;
    }
}
