<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The printable invoice and credit note page: who may open it, and that it draws only the frozen
 * document (minus amounts and the invoice reference on a credit note, escaped text, no live data).
 * Usage: php tests/billing-document-view.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\Document;
use mindstellar\billing\DocumentPage;
use mindstellar\billing\DocumentStore;
use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;

$admin = api_admin_boot('osc_billing_docview');

seed_locale($admin);
seed_currency($admin);
foreach (['language' => 'en_US', 'currency' => 'USD', 'billing_currency' => 'USD', 'rewriteEnabled' => '0', 'pageTitle' => 'Live Shop Name'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();

$sue   = seed_user($admin, 'sue', 'sue@example.test');
$tom   = seed_user($admin, 'tom', 'tom@example.test');
$order = OrderStore::create($sue, 'offline', 9_000_000, 'USD', 0, ['_kind' => Order::KIND_GOODS]);

$data = static fn (string $type): array => [
    'wording'  => ['title' => $type === 'invoice' ? 'Invoice' : 'Credit note', 'extra' => [['label' => 'VAT ID', 'value' => 'DE123']], 'summary' => [], 'footer' => 'Thanks <b>x</b>'],
    'tax_point' => '2026-03-01 09:00:00',
    'order'    => ['id' => $order->getId(), 'label' => '#' . $order->getId(), 'method' => 'Bank transfer', 'ref' => 'R-1'],
    'ref_invoice' => $type === 'invoice' ? null : ['number' => 'INV-2026-00001', 'date' => '2026-03-01 10:00:00'],
    'seller'   => ['name' => 'Frozen Seller Ltd', 'details' => ['1 Main St', 'Berlin']],
    'buyer'    => ['name' => 'Sue <script>', 'email' => 'sue@example.test', 'address' => ['2 Side St']],
    'lines'    => [
        ['line_id' => 1, 'kind' => 'item', 'title' => 'Tee <i>', 'options' => 'Size: M', 'sku' => 'TEE-M', 'qty' => 2, 'unit_text' => '$40.00', 'amount_text' => '$80.00', 'included' => false],
        ['line_id' => 2, 'kind' => 'tax', 'title' => 'VAT 19%', 'options' => '', 'sku' => '', 'qty' => 1, 'unit_text' => '$12.77', 'amount_text' => '$12.77', 'included' => true],
    ],
    'totals'   => ['total' => 80_000_000, 'total_text' => '$80.00'],
    'refund'   => $type === 'invoice' ? null : ['reason' => 'Damaged', 'provider_ref' => '', 'restocked' => []],
];
$invoice = DocumentStore::insert(Document::INVOICE, $order, 9_000_000, $data('invoice'), '2026-03-01 10:00:00');
$note    = DocumentStore::insert(Document::CREDIT_NOTE, $order, 4_000_000, $data('credit_note'), '2026-03-05 10:00:00');
$show    = static function (Document $doc): string {
    ob_start();
    DocumentPage::render($doc, 'http://localhost/orders', 'Back');

    return (string) ob_get_clean();
};

harness_section('who may open it');
pin('the buyer opens their own invoice', true, DocumentPage::canView($invoice, $sue, false));
pin('another user gets no access', false, DocumentPage::canView($invoice, $tom, false));
pin('a guest gets no access', false, DocumentPage::canView($invoice, 0, false));
pin('an admin opens any', true, DocumentPage::canView($note, 0, true));
pin('the link is the document route', true, str_contains(DocumentPage::url($invoice), 'billing_document'));

harness_section('the invoice page');
Preference::getInstance()->set('pageTitle', 'Renamed Shop');
scratchdb_forget_cache();
osc_reset_preferences();
$html = $show($invoice);
pin('title carries the number', true, str_contains($html, '<h1 class="rc-title">Invoice INV-2026-00001</h1>'));
pin('the frozen seller shows, not the live site name', [true, false], [str_contains($html, 'Frozen Seller Ltd') && str_contains($html, '1 Main St'), str_contains($html, 'Renamed Shop')]);
pin('lines, tax and total show', [true, true, true], [str_contains($html, '$80.00'), str_contains($html, '$12.77 (included)'), str_contains($html, 'VAT ID: DE123')]);
pin('text is escaped', [false, false, true], [str_contains($html, '<script>'), str_contains($html, '<i>'), str_contains($html, 'Sue &lt;script&gt;')]);
pin('an invoice has no minus and no invoice reference', [false, false], [str_contains($html, "\u{2212}"), str_contains($html, 'For invoice')]);

harness_section('the credit note page');
$html = $show($note);
pin('amounts are negative', [true, true], [str_contains($html, "\u{2212}\$80.00"), str_contains($html, "\u{2212}\$12.77 (included)")]);
pin('it names the invoice and the reason', [true, true, true], [str_contains($html, 'For invoice INV-2026-00001'), str_contains($html, 'Damaged'), str_contains($html, 'Total credited')]);
pin('the page is marked noindex', true, str_contains($html, 'noindex'));

exit(harness_result());
