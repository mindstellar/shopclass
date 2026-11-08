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
 * CategoryService tree writes: create under a parent, move, the whole-subtree switch, expiry
 * with keep or new end dates, the delete preview and bulk actions.
 *
 * Usage:  php tests/models/category-service-tree.php
 *         php tests/run-models.php category-service-tree
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\category\CategoryService;
use mindstellar\exception\InvalidException;
use mindstellar\job\CategoryJobs;
use mindstellar\job\JobQueue;
use mindstellar\utility\Utils;

$admin = api_admin_boot('osc_models_category_service_tree');
$p     = DB_TABLE_PREFIX;

$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$user = seed_user($admin, 'sue', 'sue@example.test');
scratchdb_forget_cache();
osc_reset_preferences();

const NEVER = '9999-12-31 23:59:59';

$service = CategoryService::make();
$one     = static fn (string $sql) => $admin->query($sql)->fetch_row()[0] ?? null;
$col     = static fn (string $sql): array => array_map(static fn (array $r) => $r[0], $admin->query($sql)->fetch_all());
$rows    = static fn (): array => $admin->query("SELECT pk_i_id, fk_i_parent_id, i_position, b_enabled, i_expiration_days FROM {$p}t_category ORDER BY pk_i_id")->fetch_all(MYSQLI_ASSOC);
$counts  = static fn (): array => array_map('intval', array_column($admin->query("SELECT pk_i_id, i_num_items FROM {$p}t_category ORDER BY pk_i_id")->fetch_all(MYSQLI_ASSOC), 'i_num_items', 'pk_i_id'));
$group   = static fn (?int $parent): array => array_map('intval', $col("SELECT pk_i_id FROM {$p}t_category WHERE "
    . ($parent === null ? 'fk_i_parent_id IS NULL' : "fk_i_parent_id = $parent") . ' ORDER BY i_position, pk_i_id'));
$positions = static fn (?int $parent): array => array_map('intval', $col("SELECT i_position FROM {$p}t_category WHERE "
    . ($parent === null ? 'fk_i_parent_id IS NULL' : "fk_i_parent_id = $parent") . ' ORDER BY i_position, pk_i_id'));
$expiry  = static fn (int $item): string => (string) $one("SELECT dt_expiration FROM {$p}t_item WHERE pk_i_id = $item");
$pubPlus = static fn (int $item, int $days): string => (string) $one("SELECT DATE_ADD(dt_pub_date, INTERVAL $days DAY) FROM {$p}t_item WHERE pk_i_id = $item");
$refused = static function (callable $fn): string {
    try {
        $fn();
    } catch (InvalidException $e) {
        return $e->pointer() . ' ' . $e->reason();
    }

    return 'allowed';
};
$text = static fn (string $name): array => [$locale => ['s_name' => $name, 's_description' => '']];
$setPos = static function (array $ids) use ($admin, $p): void {
    foreach (array_values($ids) as $i => $id) {
        $admin->query("UPDATE {$p}t_category SET i_position = $i WHERE pk_i_id = $id");
    }
};
$order = [];
osc_add_hook('edited_category_order', static function ($error) use (&$order): void {
    $order[] = $error;
});

// A > A1 > A1a > A1x (4 levels), B > B1, B2, C > C1, C2.
$a   = seed_category($admin, 'Alpha', null, $locale);
$a1  = seed_category($admin, 'Alpha one', $a, $locale);
$a1a = seed_category($admin, 'Alpha one a', $a1, $locale);
$a1x = seed_category($admin, 'Alpha deep', $a1a, $locale);
$b   = seed_category($admin, 'Beta', null, $locale);
$b1  = seed_category($admin, 'Beta one', $b, $locale);
$b2  = seed_category($admin, 'Beta two', $b, $locale);
$c   = seed_category($admin, 'Gamma', null, $locale);
$c1  = seed_category($admin, 'Gamma one', $c, $locale);
$c2  = seed_category($admin, 'Gamma two', $c, $locale);
$setPos([$a, $b, $c]);
$setPos([$b1, $b2]);
$admin->query("UPDATE {$p}t_category SET i_position = 5 WHERE pk_i_id = $c1");
$admin->query("UPDATE {$p}t_category SET i_position = 9 WHERE pk_i_id = $c2");

$deep  = [seed_item($admin, $a1x, $user, 'Deep 1'), seed_item($admin, $a1x, $user, 'Deep 2')];
$inA1  = seed_item($admin, $a1, $user, 'In A1');
$inB1  = [seed_item($admin, $b1, $user, 'B1 1'), seed_item($admin, $b1, $user, 'B1 2'), seed_item($admin, $b1, $user, 'B1 3')];
$inC1  = seed_item($admin, $c1, $user, 'In C1');
Utils::updateAllCategoriesStats();

harness_section('create under a parent');
\mindstellar\theme\ThemeSupports::instance()->add('category-depth', 3);
$before = (int) $one("SELECT COUNT(*) FROM {$p}t_category");
pin('under a level-3 parent with a 3-level theme: too deep', '/parent_id too_deep', $refused(static fn () => $service->create($a1a, ['i_expiration_days' => 0, 'b_price_enabled' => 1], $text('Too deep'))));
pin('and nothing is written', $before, (int) $one("SELECT COUNT(*) FROM {$p}t_category"));
$under = $service->create($a1, ['i_expiration_days' => 0, 'b_price_enabled' => 1], $text('Alpha one b'));
pin('under a level-2 parent it lands there', $a1, (int) $one("SELECT fk_i_parent_id FROM {$p}t_category WHERE pk_i_id = $under"));
pin('the service reports the limit', 3, $service->depthLimit());
pin('a move deeper than the limit is refused', '/parent_id too_deep', $refused(static fn () => $service->move($b1, $a1a, 0)));
\mindstellar\theme\ThemeSupports::instance()->remove('category-depth');
pin('with no declaration there is no limit', null, $service->depthLimit());
$off   = seed_category($admin, 'Off', null, $locale, 0);
$child = $service->create($off, ['i_expiration_days' => 0, 'b_price_enabled' => 1], $text('Under off'));
pin('created under a disabled parent, it is off', 0, (int) $one("SELECT b_enabled FROM {$p}t_category WHERE pk_i_id = $child"));
$service->delete($under);
$service->delete($child);
$setPos([$a, $b, $c, $off]);

harness_section('refused moves write nothing');
$snapshot = $rows();
$order    = [];
pin('into itself', '/parent_id inside_itself', $refused(static fn () => $service->move($a, $a, 0)));
pin('into its own grandchild', '/parent_id inside_itself', $refused(static fn () => $service->move($a, $a1a, 0)));
pin('under an unknown parent', '/parent_id unknown', $refused(static fn () => $service->move($a, 999999, 0)));
$missing = 'allowed';
try {
    $service->move(999999, null, 0);
} catch (\mindstellar\exception\NotFoundException $e) {
    $missing = 'not found';
}
pin('an unknown category is not found', 'not found', $missing);
pin('no row changed', $snapshot, $rows());
pin('and no order hook fired', [], $order);

harness_section('a move');
$c1Pos = $positions($c);
$order = [];
$service->move($b1, $a, 0);
pin('it lands first under the new parent', [$b1, $a1], $group($a));
pin('the new group is numbered from 0', [0, 1], $positions($a));
pin('the old group closes the gap', [[$b2], [0]], [$group($b), $positions($b)]);
pin('another group is untouched', $c1Pos, $positions($c));
pin('edited_category_order fired once with 0', [0], $order);

$moved = $counts();
pin('the listing counts moved off B and onto A', [3 + 3, 0, 3], [$moved[$a], $moved[$b], $moved[$b1]]);
$order = [];
$service->move($a1, $c, 99);
pin('a position past the end goes last', [$c1, $c2, $a1], $group($c));
pin('and the group is numbered 0..n', [0, 1, 2], $positions($c));
$moved = $counts();
Utils::updateAllCategoriesStats();
pin('every count matches a full recount', $counts(), $moved);
pin('a move within one group only renumbers it', null, $service->move($a1, $c, 0));
pin('...first now', [$a1, $c1, $c2], $group($c));
pin('two moves, two hooks', [0, 0], $order);

harness_section('switching a subtree');
$service->move($a1, $b, 1);
$level4 = (int) $one("SELECT COUNT(*) FROM {$p}t_category WHERE pk_i_id = $a1x AND fk_i_parent_id = $a1a");
pin('A1 is level 2 with a level-4 grandchild', 1, $level4);
$switched = $service->setEnabled($a1, false, ['fk_i_parent_id' => $b]);
pin('disable returns the subtree, the category first', $a1, $switched[0] ?? null);
pin('every level below is off', ['0', '0', '0'], $col("SELECT b_enabled FROM {$p}t_category WHERE pk_i_id IN ($a1, $a1a, $a1x) ORDER BY pk_i_id"));
pin('and so are their listings', ['0', '0', '0'], $col("SELECT b_enabled FROM {$p}t_item WHERE pk_i_id IN ({$deep[0]}, {$deep[1]}, $inA1) ORDER BY pk_i_id"));
pin('enable under an off parent changes nothing', null, $service->setEnabled($a1a, true, ['fk_i_parent_id' => $a1]));
pin('...still off', '0', $one("SELECT b_enabled FROM {$p}t_category WHERE pk_i_id = $a1a"));
$service->setEnabled($a1, true, ['fk_i_parent_id' => $b]);
pin('enable turns the whole subtree back on', ['1', '1', '1'], $col("SELECT b_enabled FROM {$p}t_category WHERE pk_i_id IN ($a1, $a1a, $a1x) ORDER BY pk_i_id"));
pin('with its listings', ['1', '1', '1'], $col("SELECT b_enabled FROM {$p}t_item WHERE pk_i_id IN ({$deep[0]}, {$deep[1]}, $inA1) ORDER BY pk_i_id"));
$admin->query("UPDATE {$p}t_item SET b_enabled = 0 WHERE pk_i_id = {$deep[0]}");
$service->setEnabled($a1a, false, ['fk_i_parent_id' => $a1]);
$service->setEnabled($a1a, true, ['fk_i_parent_id' => $a1]);
pin('a listing switched off on its own stays off after off and on', ['0', '1'], $col("SELECT b_enabled FROM {$p}t_item WHERE pk_i_id IN ({$deep[0]}, {$deep[1]}) ORDER BY pk_i_id"));
pin('...and nothing is left noted', '0', $one("SELECT COUNT(*) FROM {$p}t_key_value WHERE s_group = 'category.kept_off'"));
$service->setEnabled($a1x, false, ['fk_i_parent_id' => $a1a]);
$service->setEnabled($a1, false, ['fk_i_parent_id' => $b]);
$service->setEnabled($a1, true, ['fk_i_parent_id' => $b]);
pin('a nested switch-off comes back on with its parent, the lone one stays off', ['0', '1'], $col("SELECT b_enabled FROM {$p}t_item WHERE pk_i_id IN ({$deep[0]}, {$deep[1]}) ORDER BY pk_i_id"));
$admin->query("UPDATE {$p}t_item SET b_enabled = 1 WHERE pk_i_id = {$deep[0]}");
$service->move($b2, $off, 0);
pin('moved under a disabled parent, it is switched off', '0', $one("SELECT b_enabled FROM {$p}t_category WHERE pk_i_id = $b2"));
$service->move($b2, $b, 0);

harness_section('expiry, keeping end dates');
$was = [$expiry($inA1), $expiry($deep[0])];
pin('keep: the category row is written, no listing', ['categories' => 1, 'listings' => 0, 'queued' => false], $service->setExpiry([$a1], 45, false, false));
pin('A1 says 45, the subcategory is left', ['45', '0'], $col("SELECT i_expiration_days FROM {$p}t_category WHERE pk_i_id IN ($a1, $a1a) ORDER BY pk_i_id"));
pin('listing dates are untouched', $was, [$expiry($inA1), $expiry($deep[0])]);
$edited = [];
osc_add_hook('edited_category', static function ($id, $outcome) use (&$edited): void {
    $edited[] = [(int) $id, (int) $outcome];
});
$service->setExpiry([$a1], 30, false, true);
sort($edited);
pin('edited_category fires with 0 for each category written', [[$a1, 0], [$a1a, 0], [$a1x, 0]], $edited);
pin('with subcategories, the whole subtree says 30', ['30', '30', '30'], $col("SELECT i_expiration_days FROM {$p}t_category WHERE pk_i_id IN ($a1, $a1a, $a1x) ORDER BY pk_i_id"));
pin('still no listing written', $was, [$expiry($inA1), $expiry($deep[0])]);

harness_section('expiry, new end dates');
pin('new dates with 0, the category only', ['categories' => 1, 'listings' => 1, 'queued' => false], $service->setExpiry([$a1], 0, true, false));
pin('its listing never ends, the deep ones keep theirs', [NEVER, $was[1]], [$expiry($inA1), $expiry($deep[0])]);
$service->setExpiry([$a1], 0, true, true);
pin('with subcategories, the deep listings never end too', [NEVER, NEVER], [$expiry($deep[0]), $expiry($deep[1])]);
$service->setExpiry([$a1], 10, true, true);
pin('a day count is pub date + N', $pubPlus($deep[1], 10), $expiry($deep[1]));
pin('negative days are refused', '/days invalid', $refused(static fn () => $service->setExpiry([$a1], -1, true, false)));

harness_section('the drawer save');
$service->setExpiry([$a1], 0, true, false);
$service->update($a1, ['i_expiration_days' => 20, 'b_price_enabled' => 1], [$locale => ['s_name' => 'Alpha one', 's_description' => '', 's_slug' => 'alpha-one']], false, 0, false);
pin('keep: the row says 20', '20', $one("SELECT i_expiration_days FROM {$p}t_category WHERE pk_i_id = $a1"));
pin('and no listing date is written', NEVER, $expiry($inA1));
$service->update($a1, ['i_expiration_days' => 20, 'b_price_enabled' => 1], [$locale => ['s_name' => 'Alpha one', 's_description' => '', 's_slug' => 'alpha-one']], false);
pin('the default keeps the dates too', NEVER, $expiry($inA1));
$service->update($a1, ['i_expiration_days' => 20, 'b_price_enabled' => 1], [$locale => ['s_name' => 'Alpha one', 's_description' => '', 's_slug' => 'alpha-one']], false, 0, true);
pin('new dates when asked', $pubPlus($inA1, 20), $expiry($inA1));
$service->update($a1, ['i_expiration_days' => '', 'b_price_enabled' => 1], [$locale => ['s_name' => 'Alpha one', 's_description' => '', 's_slug' => 'alpha-one']], false, 0, true);
pin('empty days leave the row alone', '20', $one("SELECT i_expiration_days FROM {$p}t_category WHERE pk_i_id = $a1"));
pin('...and the listing dates', $pubPlus($inA1, 20), $expiry($inA1));
pin('non-numeric days are refused', '/expiration_days invalid', $refused(static fn () => $service->update($a1, ['i_expiration_days' => 'abc', 'b_price_enabled' => 1], [], false, 0, true)));

harness_section('delete preview');
$e  = seed_category($admin, 'Epsilon', null, $locale);
$e1 = seed_category($admin, 'Epsilon one', $e, $locale);
seed_item($admin, $e, $user, 'Live');
$expired = seed_item($admin, $e, $user, 'Expired');
$admin->query("UPDATE {$p}t_item SET dt_expiration = '2001-01-01 00:00:00' WHERE pk_i_id = $expired");
seed_item($admin, $e, $user, 'Unapproved', 1.0, 0);
seed_item($admin, $e, $user, 'Disabled', 1.0, 1, 0);
seed_item($admin, $e1, $user, 'Child');
pin('every listing counts, a child picked with its parent once', ['categories' => 2, 'listings' => 5, 'queued' => false], $service->deletePreview([$e1, $e]));
pin('unknown ids count nothing', ['categories' => 0, 'listings' => 0, 'queued' => false], $service->deletePreview([999999]));

harness_section('bulk');
$tooMany = range(1, CategoryService::BULK_LIMIT + 1);
pin('1001 ids are refused', '/ids too_many', $refused(static fn () => $service->bulk('disable', $tooMany)));
pin('an unknown action is refused', '/action invalid', $refused(static fn () => $service->bulk('rename', [$e])));
$result = $service->bulk('disable', [$e1, $e, 999999]);
pin('disable [child, parent]: one done, the top-most', [[$e], [999999]], [$result['done'], array_keys($result['failed'])]);
pin('both are off', ['0', '0'], $col("SELECT b_enabled FROM {$p}t_category WHERE pk_i_id IN ($e, $e1) ORDER BY pk_i_id"));
$result = $service->bulk('enable', [$e1]);
pin('enable under an off parent fails with a reason', [$e1], array_keys($result['failed']));
$service->bulk('enable', [$e]);
$order  = [];
$result = $service->bulk('move', [$c2, $c1], ['parent' => $e, 'position' => 0]);
pin('bulk move keeps the list order', [$c2, $c1, $e1], $group($e));
pin('one hook per move', [0, 0], $order);
$result = $service->bulk('move', [$e], ['parent' => $c2, 'position' => 0]);
pin('a refused move is reported, not thrown', [[], [$e]], [$result['done'], array_keys($result['failed'])]);
$result = $service->bulk('expiry', [$c2, $e], ['days' => 7, 'newDates' => false, 'toSubcategories' => true]);
pin('bulk expiry: the top-most done', [$e], $result['done']);
pin('the whole subtree says 7', ['7', '7', '7', '7'], $col("SELECT i_expiration_days FROM {$p}t_category WHERE pk_i_id IN ($e, $e1, $c1, $c2) ORDER BY pk_i_id"));

harness_section('over the inline limit');
$queue = JobQueue::getInstance();
$admin->query("DELETE FROM {$p}t_job_queue");
$big   = seed_category($admin, 'Big', null, $locale);
$small = seed_category($admin, 'Small', null, $locale);
$tmpl  = seed_item($admin, $big, $user, 'Big 0');
$admin->query("INSERT INTO {$p}t_item (fk_i_user_id, fk_i_category_id, dt_pub_date, dt_first_pub_date, dt_mod_date, f_price, i_price,
    fk_c_currency_code, s_contact_name, s_contact_email, s_ip, b_premium, b_enabled, b_active, b_spam, s_secret, dt_expiration)
    SELECT fk_i_user_id, fk_i_category_id, dt_pub_date, dt_first_pub_date, dt_mod_date, f_price, i_price, fk_c_currency_code,
    s_contact_name, s_contact_email, s_ip, b_premium, b_enabled, b_active, b_spam, s_secret, dt_expiration
    FROM {$p}t_item, (SELECT seq FROM (SELECT 0 AS seq" . str_repeat(' UNION ALL SELECT 0', CategoryJobs::INLINE_LIMIT - 1) . ") s) n
    WHERE pk_i_id = $tmpl");
seed_item($admin, $small, $user, 'Small 1');
pin('the big category is over the limit', CategoryJobs::INLINE_LIMIT + 1, (int) $one("SELECT COUNT(*) FROM {$p}t_item WHERE fk_i_category_id = $big"));
$bigWas = $expiry($tmpl);
$result = $service->setExpiry([$big], 0, true, false);
pin('new dates over the limit are queued', ['categories' => 1, 'listings' => CategoryJobs::INLINE_LIMIT + 1, 'queued' => true], $result);
pin('as one category.expiry job', 1, $queue->count(JobQueue::STATUS_PENDING, CategoryJobs::EXPIRY));
pin('no listing is re-dated in the request', $bigWas, $expiry($tmpl));
pin('the preview says queued', true, $service->deletePreview([$big, $small])['queued']);
$result = $service->bulk('delete', [$big, $small]);
pin('bulk delete over the limit queues every id', [[], [$big, $small]], [$result['done'], $result['queued']]);
pin('two delete jobs', 2, $queue->count(JobQueue::STATUS_PENDING, CategoryJobs::TYPE));
pin('and the small one is still there for its job', 1, (int) $one("SELECT COUNT(*) FROM {$p}t_category WHERE pk_i_id = $small"));
$admin->query("DELETE FROM {$p}t_job_queue");
$result = $service->bulk('delete', [$e]);
pin('a small bulk delete runs now', [[$e], []], [$result['done'], $result['queued']]);
pin('with its subtree', 0, (int) $one("SELECT COUNT(*) FROM {$p}t_category WHERE pk_i_id IN ($e, $e1, $c1, $c2)"));

exit(harness_result());
