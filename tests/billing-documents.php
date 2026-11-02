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
 * Invoice and credit note numbering and storage: the INV-2026-00042 format per type and year,
 * the year rollover, the prefix setting, no gap after a failure, a reseed when the counter row is
 * lost, the unique type and number pair, the read methods, the indexes search() uses, and
 * migration 0092 running twice.
 * Usage: php tests/billing-documents.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\billing\Document;
use mindstellar\billing\DocumentStore;
use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;
use mindstellar\database\Connection;
use mindstellar\database\Db;
use mindstellar\database\DbException;
use mindstellar\model\KeyValue;

$admin = api_admin_boot('osc_billing_documents');
$p     = DB_TABLE_PREFIX;

seed_locale($admin);
seed_currency($admin);
$sam   = seed_user($admin, 'sam', 'sam@example.test');
$order = OrderStore::create($sam, 'offline', 9_000_000, 'USD', 0, array('_kind' => Order::KIND_GOODS));
$other = OrderStore::create($sam, 'offline', 4_000_000, 'USD', 0, array('_kind' => Order::KIND_GOODS));
$third = OrderStore::create($sam, 'offline', 1_000_000, 'USD', 0);
$kv    = new KeyValue();

$counter = static fn (string $key): ?string => $kv->get(DocumentStore::COUNTER_GROUP, $key)['value'] ?? null;
$rows    = static fn (): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}t_billing_document")->fetch_row()[0];
$threw   = static function (callable $fn): string {
    try {
        $fn();
    } catch (Throwable $e) {
        return get_class($e) . ':' . $e->getCode();
    }

    return 'none';
};

harness_section('numbers per type and year');
$a = DocumentStore::insert(Document::INVOICE, $order, 9_000_000, array('lines' => array()), '2026-03-01 10:00:00');
$b = DocumentStore::insert(Document::INVOICE, $other, 4_000_000, array(), '2026-03-02 10:00:00');
$c = DocumentStore::insert(Document::CREDIT_NOTE, $order, 1_500_000, array(), '2026-03-03 10:00:00');
pin('invoices count from 1 with five digits', array('INV-2026-00001', 'INV-2026-00002'), array($a->number, $b->number));
pin('credit notes have their own series', 'CN-2026-00001', $c->number);
pin('the document holds what was stored', array(Document::INVOICE, $order->getId(), 9_000_000, 'USD', '2026-03-01 10:00:00'), array($a->type, $a->orderId, $a->total, $a->currency, $a->date));
pin('s_data gains v, type, number and date', array(1, 'invoice', 'INV-2026-00001', '2026-03-01 10:00:00', array()), array($a->data['v'], $a->data['type'], $a->data['number'], $a->data['date'], $a->data['lines']));
pin('find() reads the same document back', (array) $a, (array) DocumentStore::find($a->id));
pin('the counter row holds the last number', '2', $counter('invoice:2026'));

harness_section('the year rolls over');
pin('the first invoice of 2027 is 00001', 'INV-2027-00001', DocumentStore::insert(Document::INVOICE, $order, 1_000_000, array(), '2027-01-01 00:00:05')->number);
pin('2026 goes on where it was', 'INV-2026-00003', DocumentStore::insert(Document::INVOICE, $order, 1_000_000, array(), '2026-12-31 23:59:59')->number);

harness_section('the prefix setting');
Preference::getInstance()->set('billing_invoice_prefix', 'shop/ x-');
scratchdb_forget_cache();
osc_reset_preferences();
pin('the prefix is upper case, cleaned, and numbering goes on', 'SHOP/X-2026-00004', DocumentStore::insert(Document::INVOICE, $order, 1_000_000, array(), '2026-06-01 10:00:00')->number);
Preference::getInstance()->set('billing_invoice_prefix', '');
scratchdb_forget_cache();
osc_reset_preferences();
pin('an empty prefix falls back to INV-', 'INV-2026-00005', DocumentStore::insert(Document::INVOICE, $order, 1_000_000, array(), '2026-06-02 10:00:00')->number);

harness_section('a failure after the number was taken leaves no gap');
$before = $rows();
pin('a caller\'s transaction that fails after the insert rolls both back', 'RuntimeException:0', $threw(static function () use ($order): void {
    Db::transaction(static function () use ($order): void {
        DocumentStore::insert(Document::INVOICE, $order, 1_000_000, array(), '2026-06-03 10:00:00');
        throw new RuntimeException('after the insert');
    });
}));
pin('no row and the counter is back', array($before, '5'), array($rows(), $counter('invoice:2026')));
pin('the next invoice takes the number the failed one had', 'INV-2026-00006', DocumentStore::insert(Document::INVOICE, $order, 1_000_000, array(), '2026-06-04 10:00:00')->number);

$admin->query("INSERT INTO {$p}t_billing_document (s_type, s_number, dt_date, fk_i_order_id, i_total, s_currency, s_data) VALUES ('invoice', 'INV-2026-00007', '2026-06-05 10:00:00', 1, 1, 'USD', '{}')");
pin('an insert whose number is taken fails on the unique pair', DbException::class . ':1062', $threw(static fn () => DocumentStore::insert(Document::INVOICE, $order, 1_000_000, array(), '2026-06-05 11:00:00')));
pin('and its counter step is rolled back', '6', $counter('invoice:2026'));
$admin->query("DELETE FROM {$p}t_billing_document WHERE s_number = 'INV-2026-00007'");

harness_section('the unique type and number pair');
pin('the same number twice in one type is refused', 1062, (static function () use ($admin, $p): int {
    try {
        $admin->query("INSERT INTO {$p}t_billing_document (s_type, s_number, dt_date, fk_i_order_id, i_total, s_currency, s_data) VALUES ('invoice', 'INV-2026-00001', '2026-06-05 10:00:00', 1, 1, 'USD', '{}')");
    } catch (mysqli_sql_exception $e) {
        return $e->getCode();
    }

    return 0;
})());
check('the same number in the other type is fine', $admin->query("INSERT INTO {$p}t_billing_document (s_type, s_number, dt_date, fk_i_order_id, i_total, s_currency, s_data) VALUES ('credit_note', 'INV-2026-00001', '2025-06-05 10:00:00', 999999, 1, 'USD', '{}')") === true);
$admin->query("DELETE FROM {$p}t_billing_document WHERE s_type = 'credit_note' AND s_number = 'INV-2026-00001'");

harness_section('bad input takes no number');
pin('an unknown order is refused', InvalidArgumentException::class . ':0', $threw(static fn () => DocumentStore::insert(Document::INVOICE, new Order(999999, $sam, 'offline', null, 1, 'USD', 0, Order::STATUS_PAID), 1, array(), '2026-06-06 10:00:00')));
pin('a zero total is refused', InvalidArgumentException::class . ':0', $threw(static fn () => DocumentStore::insert(Document::INVOICE, $order, 0, array(), '2026-06-06 10:00:00')));
pin('an unknown type is refused', InvalidArgumentException::class . ':0', $threw(static fn () => DocumentStore::insert('receipt', $order, 1, array(), '2026-06-06 10:00:00')));
pin('the counter did not move', '6', $counter('invoice:2026'));

harness_section('a lost counter row is seeded from the stored numbers');
$kv->delete(DocumentStore::COUNTER_GROUP, 'invoice:2026');
pin('numbering goes on after the highest stored one', 'INV-2026-00007', DocumentStore::insert(Document::INVOICE, $order, 1_000_000, array(), '2026-07-01 10:00:00')->number);
pin('and the row is back', '7', $counter('invoice:2026'));
$kv->delete(DocumentStore::COUNTER_GROUP, 'credit_note:2028');
pin('a year with no documents starts at 1', 'CN-2028-00001', DocumentStore::insert(Document::CREDIT_NOTE, $order, 1_000, array(), '2028-02-01 10:00:00')->number);

harness_section('reads');
$admin->query("DELETE FROM {$p}t_billing_document WHERE fk_i_order_id = {$order->getId()} AND s_type = 'credit_note'");
$n1 = DocumentStore::insert(Document::CREDIT_NOTE, $order, 2_000_000, array('lines' => array(array('line_id' => 11, 'qty' => 1), array('line_id' => 12, 'qty' => 2), array('line_id' => null, 'qty' => 1))), '2026-08-01 10:00:00');
$n2 = DocumentStore::insert(Document::CREDIT_NOTE, $order, 500_000, array('lines' => array(array('line_id' => 11, 'qty' => 2))), '2026-08-02 10:00:00');
pin('invoiceOf() is the order\'s first invoice', $a->id, DocumentStore::invoiceOf($order->getId())?->id);
pin('an order without documents has no invoice', null, DocumentStore::invoiceOf(999999));
pin('refundedTotal() sums the credit notes', 2_500_000, DocumentStore::refundedTotal($order->getId()));
pin('refundedTotal() of an order with none is 0', 0, DocumentStore::refundedTotal($other->getId()));
pin('refundedQty() sums each line', array(11 => 3, 12 => 2), DocumentStore::refundedQty($order->getId()));
$types = array_map(static fn (Document $d): string => $d->type, DocumentStore::forOrder($order->getId()));
pin('forOrder() lists invoices then notes, oldest first', array('invoice', 'invoice', 'invoice', 'invoice', 'invoice', 'invoice', 'invoice', 'credit_note', 'credit_note'), $types);

$numbers = static fn (array $docs): array => array_map(static fn (Document $d): string => $d->number, $docs);
pin('search() by type and days is newest first', array($n2->number, $n1->number), $numbers(DocumentStore::search(array('type' => Document::CREDIT_NOTE, 'from' => '2026-08-01', 'to' => '2026-08-02'))));
pin('the "to" day is included to its end', 1, DocumentStore::count(array('type' => Document::CREDIT_NOTE, 'from' => '2026-08-01', 'to' => '2026-08-01')));
pin('search() pages', array($n1->number), $numbers(DocumentStore::search(array('type' => Document::CREDIT_NOTE, 'from' => '2026-08-01'), 1, 1)));
pin('search() by order', 1, DocumentStore::count(array('order_id' => $other->getId())));
pin('a malformed day is ignored', DocumentStore::count(array()), DocumentStore::count(array('from' => "2026-01-01' OR 1=1")));

harness_section('search() uses the indexes');
$filler = array();
for ($i = 1; $i <= 400; $i++) {
    $filler[] = sprintf("('credit_note', 'CN-2020-%05d', '2020-01-01 00:00:00' + INTERVAL %d HOUR, %d, 1, 'USD', '{}')", $i, $i, 500000 + $i);
}
$admin->query("INSERT INTO {$p}t_billing_document (s_type, s_number, dt_date, fk_i_order_id, i_total, s_currency, s_data) VALUES " . implode(', ', $filler));
$admin->query("ANALYZE TABLE {$p}t_billing_document");
$explain = static function (array $filters) use ($admin): string {
    $q    = DocumentStore::filtered($filters)->orderBy('dt_date', 'DESC')->limit(25);
    $sql  = $q->toSql();
    foreach ($q->getBindings() as $v) {
        $sql = preg_replace('/\?/', is_int($v) ? (string) $v : "'" . $admin->real_escape_string((string) $v) . "'", $sql, 1);
    }

    return (string) ($admin->query('EXPLAIN ' . $sql)->fetch_assoc()['key'] ?? '');
};
pin('a day range reads idx_date', 'idx_date', $explain(array('from' => '2026-08-01', 'to' => '2026-08-02')));
pin('an order reads idx_order', 'idx_order', $explain(array('order_id' => $order->getId())));

harness_section('no date given');
check('an invoice without a date takes today\'s year', str_starts_with(DocumentStore::insert(Document::INVOICE, $third, 1_000_000, array())->number, 'INV-' . date('Y') . '-'));

harness_section('migration 0092');
$mig  = require ABS_PATH . 'oc-includes/osclass/installer/migrations/0092_billing_document.php';
$conn = Connection::getInstance();
$shape = static fn (): string => (string) $admin->query("SHOW CREATE TABLE {$p}t_billing_document")->fetch_row()[1];
$fresh = $shape();
$admin->query("DROP TABLE {$p}t_billing_document");
$mig->up($conn);
$mig->up($conn);
pin('it builds the table struct.sql has, and runs twice', preg_replace('/AUTO_INCREMENT=\d+ /', '', $fresh), $shape());

exit(harness_result());
