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
 * Invoices and credit notes with line tax: the invoice freezes each line's own tax and the buyer's
 * invoice fields; a note returning units takes that line's own tax per part, the last unit the
 * exact rest; amount-only notes stay proportional; no tax line goes negative or past its amount.
 * Usage: php tests/billing-line-tax-refunds.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\Billing;
use mindstellar\billing\CallbackResult;
use mindstellar\billing\Document;
use mindstellar\billing\DocumentStore;
use mindstellar\billing\event\DocumentComposing;
use mindstellar\billing\Invoices;
use mindstellar\billing\Order;
use mindstellar\billing\OrderLineStore;
use mindstellar\billing\OrderStore;
use mindstellar\billing\Receipts;
use mindstellar\boot\CoreServices;

$admin = api_admin_boot('osc_billing_line_tax_refunds');

seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$sue = seed_user($admin, 'sue', 'sue@example.test');
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'billing_enabled' => '1', 'billing_currency' => 'USD', 'billing_receipt_email' => '0'] as $k => $v) {
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

$m = static fn (float $units): int => (int) round($units * 1_000_000);

/** A paid order from lines; `tax` on an item or shipping line is its LineTax array. */
$place = static function (array $lines, array $meta = []) use ($sue): Order {
    $total = 0;
    foreach ($lines as $line) {
        $total += $line['amount'];
    }
    $o = OrderStore::create($sue, 'offline', $total, 'USD', 0, ['_kind' => Order::KIND_GOODS, '_label' => 'Goods'] + $meta);
    OrderLineStore::add($o->getId(), array_map(static function (array $l): array {
        if (isset($l['tax'])) {
            $l['meta'] = ['tax' => $l['tax']] + ($l['meta'] ?? []);
            unset($l['tax']);
        }

        return $l;
    }, $lines));
    Billing::markPaid($o, 'ref-' . $o->getId());

    return OrderStore::find($o->getId());
};
$lineTax = static fn (bool $included, int $base, array $parts, ?string $code = null): array => ['included' => $included, 'base' => $base, 'amount' => array_sum(array_column($parts, 'amount')), 'code' => $code, 'parts' => $parts];
$lineOf  = static function (Order $o, string $title): int {
    foreach (OrderLineStore::ofOrder($o->getId()) as $line) {
        if ($line['title'] === $title) {
            return (int) $line['id'];
        }
    }

    return 0;
};
$notes   = static fn (Order $o): array => array_values(array_filter(DocumentStore::forOrder($o->getId()), static fn (Document $d): bool => $d->isCreditNote()));
$taxOf   = static fn (?Document $d): array => array_values(array_map(static fn (array $l): array => [$l['title'], $l['amount'], !empty($l['included'])], array_filter($d?->data['lines'] ?? [], static fn (array $l): bool => $l['kind'] === 'tax')));
$fresh   = static fn (Order $o): Order => OrderStore::find($o->getId());
/** The tax per invoice tax line id over all the order's notes. */
$noteTax = static function (Order $o) use ($notes): array {
    $sum = [];
    foreach ($notes($o) as $n) {
        foreach ($n->data['lines'] as $l) {
            if ($l['kind'] === 'tax') {
                $sum[$l['line_id']] = ($sum[$l['line_id']] ?? 0) + $l['amount'];
            }
        }
    }
    ksort($sum);

    return $sum;
};

/** Mixed rates: a book at 5%, a gadget and delivery at 19%. 86.45 in all. */
$mixed = static fn (array $meta = []): Order => $place([
    ['kind' => 'item', 'title' => 'Book', 'qty' => 1, 'unit' => $m(20), 'amount' => $m(20), 'tax' => $lineTax(false, $m(20), [['tax' => 'vat.5', 'label' => 'VAT 5%', 'bp' => 500, 'amount' => $m(1)]], 'B-49')],
    ['kind' => 'item', 'title' => 'Gadget', 'qty' => 1, 'unit' => $m(50), 'amount' => $m(50), 'tax' => $lineTax(false, $m(50), [['tax' => 'vat.19', 'label' => 'VAT 19%', 'bp' => 1900, 'amount' => $m(9.5)]], '8471')],
    ['kind' => 'shipping', 'title' => 'Delivery', 'qty' => 1, 'unit' => $m(5), 'amount' => $m(5), 'meta' => ['id' => 'flat'], 'tax' => $lineTax(false, $m(5), [['tax' => 'vat.19', 'label' => 'VAT 19%', 'bp' => 1900, 'amount' => $m(0.95)]])],
    ['kind' => 'tax', 'title' => 'VAT 5%', 'qty' => 1, 'unit' => $m(1), 'amount' => $m(1), 'meta' => ['id' => 'vat.5']],
    ['kind' => 'tax', 'title' => 'VAT 19%', 'qty' => 1, 'unit' => $m(10.45), 'amount' => $m(10.45), 'meta' => ['id' => 'vat.19']],
], $meta);

harness_section('the invoice freezes line tax and buyer fields');
$a   = $mixed(['_fields' => ['tax_id' => ['label' => 'VAT number', 'value' => 'DE123456789', 'invoice' => true], 'po' => ['label' => 'PO', 'value' => '77', 'invoice' => false]]]);
$inv = DocumentStore::invoiceOf($a->getId());
$by  = [];
foreach ($inv?->data['lines'] ?? [] as $l) {
    $by[$l['title']] = $l;
}
$gadgetTax = $by['Gadget']['tax'] ?? [];
pin('the gadget line carries its tax', [false, $m(50), $m(9.5), '8471', '19%'], [$gadgetTax['included'] ?? null, $gadgetTax['base'] ?? null, $gadgetTax['amount'] ?? null, $gadgetTax['code'] ?? null, $gadgetTax['rate_text'] ?? null]);
pin('its part names the invoice tax line', [['line_id' => $lineOf($a, 'VAT 19%'), 'label' => 'VAT 19%', 'bp' => 1900, 'amount' => $m(9.5)]], array_map(static fn (array $p): array => array_diff_key($p, ['amount_text' => 1]), $gadgetTax['parts'] ?? []));
pin('with display text', [true, true, true], [($gadgetTax['amount_text'] ?? '') !== '', ($gadgetTax['base_text'] ?? '') !== '', ($gadgetTax['parts'][0]['amount_text'] ?? '') !== '']);
pin('shipping carries its tax, tax lines none', [$m(0.95), false], [$by['Delivery']['tax']['amount'] ?? null, isset($by['VAT 19%']['tax'])]);
pin('the buyer lists the invoice fields only', [['label' => 'VAT number', 'value' => 'DE123456789']], $inv?->data['buyer']['fields'] ?? null);
pin('the code label defaults to Tax code', 'Tax code', $inv?->data['wording']['code_label'] ?? null);

harness_section('a compose listener cannot change line tax');
$listener = static function (DocumentComposing $e): void {
    $data = $e->data();
    foreach ($data['lines'] as $i => $l) {
        $data['lines'][$i]['tax'] = ['amount' => 1];
    }
    $e->setData($data);
};
CoreServices::dispatcher()->listen(DocumentComposing::class, $listener);
$b = $mixed();
CoreServices::dispatcher()->remove(DocumentComposing::class, $listener);
$binv = DocumentStore::invoiceOf($b->getId());
pin('the book keeps its tax, the tax line gets none', [$m(1), false], [$binv?->data['lines'][0]['tax']['amount'] ?? null, isset($binv?->data['lines'][3]['tax'])]);

harness_section('a mixed-rate order refunds only the returned line\'s tax');
$gadget = $lineOf($a, 'Gadget');
pin('linesAmount is the gadget and its own tax', $m(59.5), Invoices::linesAmount($a->getId(), [$gadget => 1]));
$r = Billing::refundPart($a, 0, [$gadget => 1], 'Faulty', false, false);
pin('refunded', CallbackResult::OUTCOME_REFUNDED, $r->getOutcome());
$n = $notes($a);
pin('the note is 59.50', $m(59.5), $n[0]->total ?? null);
pin('only the 19% line, with the gadget\'s tax', [['VAT 19%', $m(9.5), false]], $taxOf($n[0] ?? null));
$noteGadget = array_values(array_filter($n[0]->data['lines'] ?? [], static fn (array $l): bool => $l['kind'] === 'item'))[0] ?? [];
pin('the note line carries the tax it returns', [$m(9.5), $m(50)], [$noteGadget['tax']['amount'] ?? null, $noteGadget['tax']['base'] ?? null]);
check('a full refund closes with the rest per tax line', Billing::refund($fresh($a)));
pin('every tax line is refunded exactly', [$lineOf($a, 'VAT 5%') => $m(1), $lineOf($a, 'VAT 19%') => $m(10.45)], $noteTax($a));
pin('the notes add up to the paid amount', $m(86.45), DocumentStore::refundedTotal($a->getId()));

harness_section('three units refunded one by one sum to the line\'s tax per part');
$c = $place([
    ['kind' => 'item', 'title' => 'Kurta', 'qty' => 3, 'unit' => $m(10), 'amount' => $m(30), 'tax' => $lineTax(false, $m(30), [['tax' => 'cgst', 'label' => 'CGST', 'bp' => 333, 'amount' => $m(1)], ['tax' => 'sgst', 'label' => 'SGST', 'bp' => 333, 'amount' => $m(1)]], '6211')],
    ['kind' => 'tax', 'title' => 'CGST', 'qty' => 1, 'unit' => $m(1), 'amount' => $m(1), 'meta' => ['id' => 'cgst']],
    ['kind' => 'tax', 'title' => 'SGST', 'qty' => 1, 'unit' => $m(1), 'amount' => $m(1), 'meta' => ['id' => 'sgst']],
]);
$kurta = $lineOf($c, 'Kurta');
$amts  = [];
for ($i = 0; $i < 3; $i++) {
    $amts[] = Invoices::linesAmount($c->getId(), [$kurta => 1]);
    Billing::refundPart($fresh($c), 0, [$kurta => 1], 'Return', false, false);
}
$n = $notes($c);
pin('three notes', 3, count($n));
pin('each takes its cumulative share', [[['CGST', $m(0.33), false], ['SGST', $m(0.33), false]], [['CGST', $m(0.34), false], ['SGST', $m(0.34), false]], [['CGST', $m(0.33), false], ['SGST', $m(0.33), false]]], array_map($taxOf, $n));
pin('linesAmount matched each note', array_map(static fn (Document $d): int => $d->total, $n), $amts);
pin('the parts sum to the line\'s tax exactly', [$lineOf($c, 'CGST') => $m(1), $lineOf($c, 'SGST') => $m(1)], $noteTax($c));
pin('the order is refunded', [Order::STATUS_REFUNDED, $m(32)], [$fresh($c)->getStatus(), DocumentStore::refundedTotal($c->getId())]);

harness_section('an amount-only note then a line note never overshoots a tax line');
$d = $mixed();
Billing::refundPart($d, $m(10), [], 'Goodwill', false, false);
pin('the amount-only note is proportional', [['VAT 5%', $m(0.12), false], ['VAT 19%', $m(1.21), false]], $taxOf($notes($d)[0] ?? null));
$gadget = $lineOf($d, 'Gadget');
pin('linesAmount caps the gadget\'s tax at what is left', $m(59.24), Invoices::linesAmount($d->getId(), [$gadget => 1]));
Billing::refundPart($fresh($d), 0, [$gadget => 1], 'Return', false, false);
pin('the line note takes what is left of the 19% line', [['VAT 19%', $m(9.24), false]], $taxOf($notes($d)[1] ?? null));
check('the closing note', Billing::refund($fresh($d)));
$last = $notes($d)[2] ?? null;
pin('takes the exact rest, no negative line', [['VAT 5%', $m(0.88), false]], $taxOf($last));
pin('every tax line is refunded exactly', [$lineOf($d, 'VAT 5%') => $m(1), $lineOf($d, 'VAT 19%') => $m(10.45)], $noteTax($d));

harness_section('a typed amount beside lines adds an adjustment');
$e      = $mixed();
$gadget = $lineOf($e, 'Gadget');
Billing::refundPart($e, $m(55), [$gadget => 1], 'Part', false, false);
$ln = array_map(static fn (array $l): array => [$l['kind'], $l['amount']], $notes($e)[0]->data['lines'] ?? []);
pin('the gadget, its own tax, then the adjustment', [['item', $m(50)], ['tax', $m(9.5)], ['adjustment', $m(-4.5)]], $ln);

harness_section('tax included in the price');
$f = $place([
    ['kind' => 'item', 'title' => 'Mug', 'qty' => 2, 'unit' => $m(11.9), 'amount' => $m(23.8), 'tax' => $lineTax(true, $m(20), [['tax' => 'vat', 'label' => 'VAT 19%', 'bp' => 1900, 'amount' => $m(3.8)]])],
    ['kind' => 'tax', 'title' => 'VAT 19%', 'qty' => 1, 'unit' => $m(3.8), 'amount' => 0, 'meta' => ['id' => 'vat', 'included' => true]],
]);
$mug = $lineOf($f, 'Mug');
pin('linesAmount is the price alone', $m(11.9), Invoices::linesAmount($f->getId(), [$mug => 1]));
Billing::refundPart($f, 0, [$mug => 1], 'Return', false, false);
pin('the note shows its included tax', [$m(11.9), [['VAT 19%', $m(1.9), true]]], [$notes($f)[0]->total ?? null, $taxOf($notes($f)[0] ?? null)]);
check('closing', Billing::refund($fresh($f)));
pin('the closing note takes the included rest', [['VAT 19%', $m(1.9), true]], $taxOf($notes($f)[1] ?? null));

harness_section('a line tax that no longer adds up is not frozen');
$g = $place([
    ['kind' => 'item', 'title' => 'Lamp', 'qty' => 2, 'unit' => $m(10), 'amount' => $m(20), 'tax' => $lineTax(false, $m(20), [['tax' => 'vat', 'label' => 'VAT', 'bp' => 1000, 'amount' => $m(1.99)]])],
    ['kind' => 'tax', 'title' => 'VAT', 'qty' => 1, 'unit' => $m(2), 'amount' => $m(2), 'meta' => ['id' => 'vat']],
]);
$ginv = DocumentStore::invoiceOf($g->getId());
pin('no line carries tax', [false, false], [isset($ginv?->data['lines'][0]['tax']), isset($ginv?->data['lines'][1]['tax'])]);
pin('refunds fall back to proportional tax', $m(11), Invoices::linesAmount($g->getId(), [$lineOf($g, 'Lamp') => 1]));

exit(harness_result());
