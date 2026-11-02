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
 * Invoice numbers under two processes: each issuing 20 at once for its own order gets 40
 * distinct, consecutive numbers, both with the counter row in place and when both have to seed
 * a lost one.
 * Usage: php tests/billing-document-race.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';
require_once __DIR__ . '/lib/race.php';

use mindstellar\billing\Document;
use mindstellar\billing\DocumentStore;
use mindstellar\billing\Order;
use mindstellar\billing\OrderStore;
use mindstellar\model\KeyValue;

$admin = api_admin_boot('osc_billing_document_race');
$p     = DB_TABLE_PREFIX;

seed_locale($admin);
seed_currency($admin);
$sam    = seed_user($admin, 'sam', 'sam@example.test');
$dbName = (string) $admin->query('SELECT DATABASE()')->fetch_row()[0];
$child  = __DIR__ . '/lib/document-number-child.php';
$one    = OrderStore::create($sam, 'offline', 5_000_000, 'USD', 0, array('_kind' => Order::KIND_GOODS));
$two    = OrderStore::create($sam, 'offline', 5_000_000, 'USD', 0, array('_kind' => Order::KIND_GOODS));
$date   = '2026-05-01 10:00:00';

// Every number each child printed, and whether they are $from..$to with none twice.
$numbers = static function (array $out): array {
    $all = array();
    foreach ($out as $printed) {
        foreach (array_filter(explode("\n", $printed)) as $line) {
            $all[] = $line;
        }
    }
    sort($all);

    return $all;
};
$series = static fn (int $from, int $to): array => array_map(static fn (int $n): string => sprintf('INV-2026-%05d', $n), range($from, $to));

harness_section('two issuers with the counter in place');
DocumentStore::insert(Document::INVOICE, $one, 1_000_000, array(), $date);
[$waiting, $out] = race(
    $admin,
    "SELECT s_value FROM {$p}t_key_value WHERE s_group = 'billing_doc_seq' AND s_key = 'invoice:2026' FOR UPDATE",
    $dbName,
    array(array($child, array($one->getId(), 20, $date)), array($child, array($two->getId(), 20, $date))),
    "%{$p}t_key_value%"
);
pin('both waited on the counter row', 2, $waiting);
pin('neither threw', 0, substr_count(implode('', $out), 'threw'));
pin('each printed 20', array(20, 20), array_map(static fn (string $o): int => substr_count($o, "\n"), $out));
pin('40 distinct numbers after the first, with no gap', $series(2, 41), $numbers($out));
pin('the table holds 41', 41, (int) $admin->query("SELECT COUNT(DISTINCT s_number) FROM {$p}t_billing_document")->fetch_row()[0]);

harness_section('two issuers seeding a lost counter row');
(new KeyValue())->delete(DocumentStore::COUNTER_GROUP, 'invoice:2026');
[$waiting, $out] = race(
    $admin,
    "SELECT pk_i_id FROM {$p}t_billing_order WHERE pk_i_id IN ({$one->getId()}, {$two->getId()}) FOR UPDATE",
    $dbName,
    array(array($child, array($one->getId(), 20, $date)), array($child, array($two->getId(), 20, $date))),
    "%{$p}t_billing_order%"
);
pin('both waited on their order', 2, $waiting);
pin('neither threw', 0, substr_count(implode('', $out), 'threw'));
pin('they go on from 42 to 81 with no gap and none twice', $series(42, 81), $numbers($out));

exit(harness_result());
