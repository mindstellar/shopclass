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
        $sign = $document->isCreditNote() ? "\u{2212}" : '';
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
            'extra'    => (array) ($data['wording']['extra'] ?? array()),
            'summary'  => (array) ($data['wording']['summary'] ?? array()),
            'footer'   => (string) ($data['wording']['footer'] ?? ''),
            'refund'   => is_array($data['refund'] ?? null) ? $data['refund'] : null,
            'site'     => (string) ($data['seller']['name'] ?? ''),
        ) + Receipts::brand();
        require ABS_PATH . 'oc-includes/osclass/gui/billing/document.php';
    }
}
