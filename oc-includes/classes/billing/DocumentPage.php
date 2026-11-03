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
 * The printable page of an invoice or credit note, drawn from the frozen document only.
 *
 * @package mindstellar\billing
 */
final class DocumentPage
{
    /**
     * The page's address.
     */
    public static function url(Document $document): string
    {
        return osc_core_url('billing_document', array('id' => $document->id));
    }

    /**
     * The page's address in the admin panel.
     */
    public static function adminUrl(Document $document): string
    {
        return osc_admin_base_url(true) . '?page=billing&action=document&id=' . $document->id;
    }

    /**
     * The minus sign printed before a credit note's amounts, or '' for an invoice.
     */
    public static function sign(Document $document): string
    {
        return $document->isCreditNote() ? "\u{2212}" : '';
    }

    /**
     * An amount text with the credit note sign. A negative amount, such as a discount, turns positive.
     */
    public static function signed(string $sign, string $text): string
    {
        if ($sign === '') {
            return $text;
        }

        return str_starts_with($text, $sign) ? substr($text, strlen($sign)) : $sign . $text;
    }

    /**
     * The total in micros, negated for a credit note.
     */
    public static function signedTotal(Document $document): int
    {
        return $document->isCreditNote() ? -$document->total : $document->total;
    }

    /**
     * Admins see every document; a buyer sees the documents of orders that are theirs.
     */
    public static function canView(Document $document, int $userId, bool $isAdmin): bool
    {
        if ($isAdmin) {
            return true;
        }
        if ($userId <= 0) {
            return false;
        }
        $order = OrderStore::find($document->orderId);

        return $order !== null && $order->getUserId() === $userId;
    }

    /**
     * Print the page. The caller has checked who may see it.
     */
    public static function render(Document $document, string $backUrl, string $backLabel): void
    {
        $data = $document->data;
        $sign = self::sign($document);
        $page = array(
            'title'    => (string) ($data['wording']['title'] ?? ($document->isCreditNote() ? __('Credit note') : __('Invoice'))),
            'number'   => $document->number,
            'sign'     => $sign,
            'date'     => osc_format_date($document->date),
            'taxPoint' => ($data['tax_point'] ?? '') !== '' ? osc_format_date((string) $data['tax_point']) : '',
            'order'    => (array) ($data['order'] ?? array()),
            'refInv'   => is_array($data['ref_invoice'] ?? null) ? $data['ref_invoice'] : null,
            'seller'   => (array) ($data['seller'] ?? array()),
            'buyer'    => (array) ($data['buyer'] ?? array()),
            'lines'    => array_values(array_filter((array) ($data['lines'] ?? array()), 'is_array')),
            'total'    => (string) ($data['totals']['total_text'] ?? ''),
            'codeLabel' => (string) ($data['wording']['code_label'] ?? ''),
            'extra'    => (array) ($data['wording']['extra'] ?? array()),
            'summary'  => (array) ($data['wording']['summary'] ?? array()),
            'footer'   => (string) ($data['wording']['footer'] ?? ''),
            'refund'   => is_array($data['refund'] ?? null) ? $data['refund'] : null,
            'site'     => (string) ($data['seller']['name'] ?? ''),
        ) + Receipts::brand();
        require ABS_PATH . 'oc-includes/osclass/gui/billing/document.php';
    }
}
