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
 * Invoices and credit notes: a paid goods order, a delivered cash on delivery order, a credit pack
 * and a listing order each get one invoice, a replayed webhook none; the frozen copy, the compose
 * filter and the issued hook; a plugin that throws, or an amount that keeps changing, queues a job
 * that issues it later; credit notes with tax in proportion, the closing note taking the exact
 * remainder, and a full refund issuing one.
 * Usage: php tests/billing-invoices.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\Document;
use mindstellar\billing\DocumentPage;
use mindstellar\billing\DocumentStore;
use mindstellar\billing\event\DocumentComposing;
use mindstellar\billing\gateway\CashOnDeliveryGateway;
use mindstellar\billing\Invoices;
use mindstellar\billing\Order;
use mindstellar\billing\OrderLineStore;
use mindstellar\billing\OrderStore;
use mindstellar\billing\Receipts;
use mindstellar\boot\CoreServices;
use mindstellar\job\JobRegistry;

$admin = api_admin_boot('osc_billing_invoices');
$p     = DB_TABLE_PREFIX;

seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$sue = seed_user($admin, 'sue', 'sue@example.test');
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'billing_enabled' => '1', 'billing_currency' => 'USD', 'billing_receipt_email' => '0', 'billing_receipt_business' => "Sue's Shop Ltd\nVAT GB123"] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();
require_once ABS_PATH . 'oc-includes/osclass/emails.php';
foreach (['osc_get_domain' => 'shop.test', 'osc_mailserver_name_from' => '', 'osc_mailserver_mail_from' => 'noreply@shop.test', 'osc_themes_path' => ABS_PATH . 'oc-content/themes/', 'osc_theme' => ''] as $fn => $value) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '() { return ' . var_export($value, true) . '; }');
    }
}
if (!function_exists('osc_admin_base_url')) {
    function osc_admin_base_url($withIndex = false)
    {
        return 'https://shop.test/oc-admin/' . ($withIndex ? 'index.php' : '');
    }
}
osc_add_filter('init_send_mail', static fn ($mail) => new class (true) extends PHPMailer\PHPMailer\PHPMailer {
    public function send()
    {
        return true;
    }
});
Receipts::$mailer = static fn (array $params): bool => true;

$m      = static fn (float $units): int => (int) round($units * 1_000_000);
$events = CoreServices::dispatcher();
$docs   = static fn (int $orderId): array => DocumentStore::forOrder($orderId);
$count  = static fn (int $orderId, string $type): int => count(array_filter(DocumentStore::forOrder($orderId), static fn (Document $d): bool => $d->type === $type));
$issued = [];
osc_add_hook('billing_document_issued', static function ($docId, $orderId, $type) use (&$issued): void {
    $issued[] = [(int) $orderId, (string) $type];
});

/** A goods order: two tees at 10.00, delivery 5.00 and tax 2.50 on top. */
$goods = static function (string $gateway = 'offline') use ($sue, $m): Order {
    $order = OrderStore::create($sue, $gateway, $m(27.5), 'USD', 0, ['_kind' => Order::KIND_GOODS, '_label' => 'Tee × 2', '_address' => ['name' => 'Sue Smith', 'line1' => '1 Main St', 'city' => 'Springfield', 'country' => 'US']]);
    OrderLineStore::add($order->getId(), [
        ['kind' => 'item', 'title' => 'Tee', 'options' => ['Size' => 'M'], 'sku' => 'TEE-M', 'qty' => 2, 'unit' => $m(10), 'amount' => $m(20)],
        ['kind' => 'shipping', 'title' => 'Standard delivery', 'qty' => 1, 'unit' => $m(5), 'amount' => $m(5)],
        ['kind' => 'tax', 'title' => 'VAT 10%', 'qty' => 1, 'unit' => $m(2.5), 'amount' => $m(2.5), 'meta' => ['id' => 'vat']],
    ]);

    return $order;
};
$itemLine = static fn (Order $o): int => OrderLineStore::itemLines($o->getId())[0]['id'];
$refundPart = static fn (Order $o, int $amount, array $lines, string $reason): CallbackResult => Billing::refundPart(OrderStore::find($o->getId()), $amount, $lines, $reason, false, false);

harness_section('a paid goods order gets one invoice');
$paid = $goods();
check('it is paid', Billing::markPaid($paid, 'bank-1'));
pin('one invoice', 1, $count($paid->getId(), Document::INVOICE));
$inv = DocumentStore::invoiceOf($paid->getId());
pin('numbered in this year\'s series, for the paid amount', ['INV-' . date('Y') . '-00001', $m(27.5), 'USD'], [$inv?->number, $inv?->total, $inv?->currency]);
$data = $inv?->data ?? [];
pin('the copy names the order, method and reference', [$paid->getId(), 'bank-1'], [$data['order']['id'] ?? null, $data['order']['ref'] ?? null]);
pin('the tax point is the paid date', OrderStore::find($paid->getId())?->getPaidDate(), $data['tax_point'] ?? null);
pin('the seller is the site with its business lines', ["Sue's Shop Ltd", 'VAT GB123'], $data['seller']['details'] ?? null);
pin('the buyer has a name, e-mail and address', ['sue@example.test', true], [$data['buyer']['email'] ?? null, in_array('1 Main St', $data['buyer']['address'] ?? [], true)]);
pin('currency with its decimals', ['code' => 'USD', 'decimals' => 2], $data['currency'] ?? null);
pin('every order line is copied with its id, qty and micros', [['item', 2, $m(20)], ['shipping', 1, $m(5)], ['tax', 1, $m(2.5)]], array_map(static fn (array $l): array => [$l['kind'], $l['qty'], $l['amount']], $data['lines'] ?? []));
pin('the item line keeps its line id, options and sku', [$itemLine($paid), 'Size: M', 'TEE-M'], [$data['lines'][0]['line_id'] ?? null, $data['lines'][0]['options'] ?? null, $data['lines'][0]['sku'] ?? null]);
pin('display text is frozen', [$m(27.5), true], [$data['totals']['total'] ?? null, ($data['totals']['total_text'] ?? '') !== '']);
pin('it is titled Invoice, has no refund and no invoice ref', ['Invoice', null, null], [$data['wording']['title'] ?? null, array_key_exists('refund', $data) ? $data['refund'] : 'x', array_key_exists('ref_invoice', $data) ? $data['ref_invoice'] : 'x']);
pin('an order without line tax has none on its lines, no buyer fields, the default code label', [false, [], 'Tax code'], [isset($data['lines'][0]['tax']), $data['buyer']['fields'] ?? null, $data['wording']['code_label'] ?? null]);
pin('the hook heard it once', [[$paid->getId(), Document::INVOICE]], $issued);
pin('the paid write queued the invoice job too', 1, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_job_queue WHERE s_type = '" . Invoices::JOB . "' AND s_unique = 'order:" . $paid->getId() . "'")->fetch_row()[0]);

harness_section('a replayed webhook issues nothing more');
$issued = [];
check('markPaid again does not settle', !Billing::markPaid($paid, 'bank-1'));
pin('issue() again gives the same invoice', $inv?->id, Invoices::issue(OrderStore::find($paid->getId()))?->id);
pin('still one invoice', 1, $count($paid->getId(), Document::INVOICE));
pin('and the hook stays quiet', [], $issued);

harness_section('cash on delivery is invoiced when delivered');
$cod = $goods(CashOnDeliveryGateway::ID);
check('it ships unpaid', OrderStore::ship($cod->getId(), 'DHL', 'C1', ''));
pin('no invoice before the cash', 0, $count($cod->getId(), Document::INVOICE));
check('it is delivered', Billing::deliver(OrderStore::find($cod->getId())));
pin('the delivery collects and invoices', 1, $count($cod->getId(), Document::INVOICE));
check('delivered again changes nothing', !Billing::deliver(OrderStore::find($cod->getId())));
pin('still one invoice', 1, $count($cod->getId(), Document::INVOICE));

harness_section('credit packs and listings are invoiced too');
$pack = OrderStore::create($sue, 'offline', $m(5), 'USD', 100);
check('a credit pack is paid', Billing::markPaid($pack));
$packInv = DocumentStore::invoiceOf($pack->getId());
pin('one line for the pack', [[null, '100 credits', 1, $m(5)]], array_map(static fn (array $l): array => [$l['line_id'], $l['title'], $l['qty'], $l['amount']], $packInv?->data['lines'] ?? []));
$listing = OrderStore::create($sue, 'offline', $m(3), 'USD', 0, ['_kind' => Order::KIND_LISTING, '_label' => 'Listing fee: Blue bike', '_item' => 5]);
check('a listing order is paid', Billing::markPaid($listing));
pin('it is invoiced with its label', 'Listing fee: Blue bike', DocumentStore::invoiceOf($listing->getId())?->data['lines'][0]['title'] ?? null);
$free = OrderStore::create($sue, 'offline', 0, 'USD', 10);
check('a free order is paid', Billing::markPaid($free));
pin('a free order gets none', 0, count($docs($free->getId())));

harness_section('the setting turns invoices off');
Preference::getInstance()->set('billing_invoice_on', '0');
scratchdb_forget_cache();
osc_reset_preferences();
$off = $goods();
check('paid while off', Billing::markPaid($off));
pin('no invoice', 0, count($docs($off->getId())));
Preference::getInstance()->set('billing_invoice_on', '1');
scratchdb_forget_cache();
osc_reset_preferences();

harness_section('the compose filter and event');
$filter = static function ($data, $order, $type) {
    $data['wording']['footer']  = 'Thank you, ' . $type;
    $data['totals']['total']    = 1;
    $data['lines'][0]['amount'] = 1;
    $data['lines'][0]['hsn']    = '6109';

    return $data;
};
osc_add_filter('billing_document_compose', $filter);
$typed = static function (DocumentComposing $e): void {
    $data = $e->data();
    $data['wording']['extra'][] = ['label' => 'Place of supply', 'value' => 'GB'];
    $e->setData($data);
};
$events->listen(DocumentComposing::class, $typed);
$filtered = $goods();
Billing::markPaid($filtered);
$fdata = DocumentStore::invoiceOf($filtered->getId())?->data ?? [];
pin('the old filter and the typed listener both shape it', ['Thank you, invoice', [['label' => 'Place of supply', 'value' => 'GB']], '6109'], [$fdata['wording']['footer'] ?? null, $fdata['wording']['extra'] ?? null, $fdata['lines'][0]['hsn'] ?? null]);
pin('but cannot change the money', [$m(27.5), $m(20)], [$fdata['totals']['total'] ?? null, $fdata['lines'][0]['amount'] ?? null]);
osc_remove_filter('billing_document_compose', $filter);
$events->remove(DocumentComposing::class, $typed);

harness_section('a plugin that throws queues the invoice');
$boom = static function (DocumentComposing $e): void {
    throw new RuntimeException('plugin down');
};
$events->listen(DocumentComposing::class, $boom);
$late = $goods();
check('the payment still settles', Billing::markPaid($late, 'bank-late'));
pin('no invoice yet', 0, count($docs($late->getId())));
$job = static fn (): array => $admin->query("SELECT s_type, s_unique FROM {$p}t_job_queue WHERE s_unique = 'order:" . $late->getId() . "'")->fetch_all(MYSQLI_ASSOC);
pin('one job is queued for it', [['s_type' => Invoices::JOB, 's_unique' => 'order:' . $late->getId()]], $job());
Billing::registerJobs();
$handler = JobRegistry::handler(Invoices::JOB);
check('the job has a handler', $handler !== null);
$threw = false;
try {
    $handler(new \mindstellar\job\Job([], ['order_id' => $late->getId()]));
} catch (Throwable $e) {
    $threw = true;
}
check('while the plugin still throws, the job throws to retry', $threw);
$events->remove(DocumentComposing::class, $boom);
OrderStore::markReceiptSent($late->getId());
$mails           = [];
Receipts::$mailer = static function (array $params) use (&$mails): bool {
    $mails[] = $params;

    return true;
};
$handler(new \mindstellar\job\Job([], ['order_id' => $late->getId()]));
pin('the retry issues it', 1, $count($late->getId(), Document::INVOICE));
$lateInv = DocumentStore::invoiceOf($late->getId());
pin('the receipt went without it, so the buyer gets its link', [1, true], [count($mails), $lateInv !== null && str_contains($mails[0]['body'] ?? '', 'href="' . DocumentPage::url($lateInv) . '"')]);
$handler(new \mindstellar\job\Job([], ['order_id' => $late->getId()]));
pin('a second run issues no other', 1, $count($late->getId(), Document::INVOICE));
pin('and sends no second mail', 1, count($mails));
Receipts::$mailer = static fn (array $params): bool => true;

harness_section('an amount that keeps changing while composing is retried');
$moving = $goods();
$bump   = static function (DocumentComposing $e) use ($admin, $p, $moving): void {
    $admin->query("UPDATE {$p}t_billing_order SET i_amount = i_amount + 1 WHERE pk_i_id = " . $moving->getId());
};
$events->listen(DocumentComposing::class, $bump);
check('the payment still settles', Billing::markPaid($moving, 'bank-moving'));
pin('no invoice yet', 0, count($docs($moving->getId())));
$threw = false;
try {
    $handler(new \mindstellar\job\Job([], ['order_id' => $moving->getId()]));
} catch (Throwable $e) {
    $threw = true;
}
check('the job throws to retry, not a silent success', $threw);
$events->remove(DocumentComposing::class, $bump);
$handler(new \mindstellar\job\Job([], ['order_id' => $moving->getId()]));
pin('the retry issues it for the stored amount', OrderStore::find($moving->getId())?->getAmount(), DocumentStore::invoiceOf($moving->getId())?->total);

harness_section('credit notes take tax in proportion');
$part  = $goods();
Billing::markPaid($part);
$pinv  = DocumentStore::invoiceOf($part->getId());
$line  = $itemLine($part);
pin('an amount above what was paid is refused', CallbackResult::OUTCOME_IGNORED, $refundPart($part, $m(30), [], 'Too much')->getOutcome());
pin('more units than the line has are refused', CallbackResult::OUTCOME_IGNORED, $refundPart($part, $m(10), [$line => 3], 'Too many')->getOutcome());
$issued = [];
pin('a part refund is recorded', CallbackResult::OUTCOME_REFUNDED, $refundPart($part, $m(10), [$line => 1], 'Damaged')->getOutcome());
$note1 = DocumentStore::forOrder($part->getId())[1] ?? null;
pin('a credit note in its own series for the amount', ['CN-' . date('Y') . '-00001', $m(10)], [$note1?->number, $note1?->total]);
$n1 = $note1?->data ?? [];
pin('it refers to the invoice', ['number' => $pinv?->number, 'date' => $pinv?->date], $n1['ref_invoice'] ?? null);
pin('it keeps the reason, and no provider ref when only recorded', ['Damaged', null], [$n1['refund']['reason'] ?? null, array_key_exists('provider_ref', $n1['refund'] ?? []) ? $n1['refund']['provider_ref'] : 'x']);
$taxOf = static fn (array $d): array => array_values(array_map(static fn (array $l): int => $l['amount'], array_filter($d['lines'] ?? [], static fn (array $l): bool => $l['kind'] === 'tax')));
pin('tax is 2.50 × 10/27.50, rounded to cents', [$m(0.91)], $taxOf($n1));
pin('the returned unit is listed with its line id', [[$line, 1]], array_values(array_map(static fn (array $l): array => [$l['line_id'], $l['qty']], array_filter($n1['lines'] ?? [], static fn (array $l): bool => $l['kind'] === 'item'))));
pin('it is titled Credit note', 'Credit note', $n1['wording']['title'] ?? null);
pin('the hook heard it', [[$part->getId(), Document::CREDIT_NOTE]], $issued);
pin('the refunded qty counts it', 1, DocumentStore::refundedQty($part->getId())[$line] ?? 0);
pin('a second unit past the first plus two is refused', CallbackResult::OUTCOME_IGNORED, $refundPart($part, $m(1), [$line => 2], 'x')->getOutcome());
pin('the order is not refunded yet', Order::STATUS_PAID, OrderStore::find($part->getId())?->getStatus());

harness_section('a full refund issues the remainder');
check('the order refunds', Billing::refund(OrderStore::find($part->getId())));
$notes = array_values(array_filter($docs($part->getId()), static fn (Document $d): bool => $d->isCreditNote()));
pin('two notes, the second for the rest', [2, $m(17.5)], [count($notes), $notes[1]->total ?? null]);
pin('its tax is the exact remainder', [$m(1.59)], $taxOf($notes[1]->data ?? []));
pin('it lists the unit not yet refunded', [[$line, 1]], array_values(array_map(static fn (array $l): array => [$l['line_id'], $l['qty']], array_filter($notes[1]->data['lines'] ?? [], static fn (array $l): bool => $l['kind'] === 'item'))));
pin('the notes add up to the paid amount', $m(27.5), DocumentStore::refundedTotal($part->getId()));
check('a replayed refund does nothing', !Billing::refund(OrderStore::find($part->getId())));
pin('still two notes', 2, $count($part->getId(), Document::CREDIT_NOTE));
pin('nothing is left to refund', CallbackResult::OUTCOME_IGNORED, $refundPart($part, $m(1), [], 'x')->getOutcome());

harness_section('a full refund with no earlier note mirrors the invoice');
$whole = $goods();
Billing::markPaid($whole);
check('it refunds', Billing::refund(OrderStore::find($whole->getId())));
$wn = array_values(array_filter($docs($whole->getId()), static fn (Document $d): bool => $d->isCreditNote()));
pin('one note for the whole amount', [1, $m(27.5)], [count($wn), $wn[0]->total ?? null]);
pin('with the invoice\'s lines', array_map(static fn (array $l): array => [$l['kind'], $l['qty'], $l['amount']], DocumentStore::invoiceOf($whole->getId())?->data['lines'] ?? []), array_map(static fn (array $l): array => [$l['kind'], $l['qty'], $l['amount']], $wn[0]->data['lines'] ?? []));
pin('and no reason', null, $wn[0]->data['refund']['provider_ref'] ?? null);

harness_section('switching invoices off still finishes an invoiced order');
$offRef = $goods();
Billing::markPaid($offRef);
$offInv = DocumentStore::invoiceOf($offRef->getId());
$events->listen(DocumentComposing::class, $boom);
check('the refund goes through while composing throws', Billing::refund(OrderStore::find($offRef->getId())));
$events->remove(DocumentComposing::class, $boom);
pin('no credit note yet', 0, $count($offRef->getId(), Document::CREDIT_NOTE));
Preference::getInstance()->set('billing_invoice_on', '0');
scratchdb_forget_cache();
osc_reset_preferences();
pin('issue() still gives the stored invoice', $offInv?->id, Invoices::issue(OrderStore::find($offRef->getId()))?->id);
$handler(new \mindstellar\job\Job([], ['order_id' => $offRef->getId()]));
pin('the job issues the missing credit note', [1, $m(27.5)], [$count($offRef->getId(), Document::CREDIT_NOTE), DocumentStore::refundedTotal($offRef->getId())]);
Preference::getInstance()->set('billing_invoice_on', '1');
scratchdb_forget_cache();
osc_reset_preferences();

harness_section('a credit pack refund');
check('the pack refunds', Billing::refund(OrderStore::find($pack->getId())));
pin('one note for the pack', [1, $m(5)], [$count($pack->getId(), Document::CREDIT_NOTE), DocumentStore::refundedTotal($pack->getId())]);

harness_section('orders without an invoice');
$old = OrderStore::create($sue, 'offline', $m(8), 'USD', 0, ['_kind' => Order::KIND_GOODS]);
OrderStore::settle($old->getId(), Order::STATUS_PAID, 'old-ref');
pin('a part refund needs an invoice', CallbackResult::OUTCOME_IGNORED, $refundPart($old, $m(1), [], 'x')->getOutcome());
check('an old order still refunds', Billing::refund(OrderStore::find($old->getId())));
pin('with no credit note', 0, count($docs($old->getId())));

harness_section('who may view');
pin('the buyer and an admin may, another user may not', [true, true, false, false], [DocumentPage::canView($inv, $sue, false), DocumentPage::canView($inv, 0, true), DocumentPage::canView($inv, $sue + 99, false), DocumentPage::canView($inv, 0, false)]);

exit(harness_result());
