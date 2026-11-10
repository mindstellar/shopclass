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
 * The admin notices store and service on t_key_value: one-offs, standing checks, hide per admin,
 * who may hide what, what a cleared check takes with it, and the one-read query budget.
 *
 * Usage:  php tests/models/admin-notices.php       (standalone, own scratch database)
 *         php tests/run-models.php admin-notices   (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\admin\AdminNotice;
use mindstellar\admin\AdminNoticeFeed;
use mindstellar\admin\AdminNotices;
use mindstellar\admin\AdminNoticeStore;
use mindstellar\model\KeyValue;

$admin = scratchdb_session('osc_models_admin_notices');
$table = DB_TABLE_PREFIX . 't_key_value';
$kv    = new KeyValue();
$now   = 1_800_000_000;
$clock = static function () use (&$now): int {
    return $now;
};

/** Core's feed has no rows here, so each case sees only what it adds. */
final class EmptyFeed extends AdminNoticeFeed
{
    public function notices(AdminNoticeStore $s, int $admin, bool $moderator, int $now): array
    {
        return [[], true];
    }
}

// A new service is a new request: nothing is memoised between two calls of this.
$request = static fn (?AdminNoticeFeed $feed = null): AdminNotices => new AdminNotices(new AdminNoticeStore(new KeyValue(), $clock), $feed ?? new EmptyFeed(), $clock, true);

$keys = static function (string $prefix = '') use ($admin, $table): array {
    $out = [];
    foreach ($admin->query("SELECT s_key FROM $table WHERE s_group = 'admin_notice' AND s_key LIKE '$prefix%' ORDER BY s_key") as $row) {
        $out[] = $row['s_key'];
    }

    return $out;
};
$reset = static function () use ($admin, $table): void {
    $admin->query("DELETE FROM $table WHERE s_group = 'admin_notice'");
};
$ids     = static fn (array $rows): array => array_map(static fn (AdminNotice $n): string => $n->id, $rows);
$oneOff  = static fn (string $id, string $text = 'Text', bool $moderator = false): AdminNotice => new AdminNotice($id, 'warning', $text, moderator: $moderator);
$expires = static fn (string $key): ?int => $kv->get('admin_notice', $key, $now)['expires'] ?? null;

harness_section('one-offs: add, replace, clear');
$reset();
$request()->add($oneOff('job.one', 'First text'));
pin('stored under n:<id>', ['n:job.one'], $keys());
pin('stored with the 30-day expiry', $now + 30 * 86400, $expires('n:job.one'));
$view = $request()->view(1, false);
pin('an admin sees it', ['job.one'], $ids($view->active));
pin('since is the stored time', $now, $view->active[0]->since);
$now += 10;
$request()->add($oneOff('job.one', 'Second text'));
pin('the same id replaces', ['Second text'], array_map(static fn (AdminNotice $n): string => $n->text, $request()->view(1, false)->active));
pin('a replace leaves one row', ['n:job.one'], $keys());
$request()->clear('job.one');
pin('clear removes the row', [], $keys());
pin('a cleared notice is gone', 0, $request()->view(1, false)->count());

harness_section('one-offs: expiry and the 100-row cap');
$reset();
$request()->add($oneOff('short'), 60);
pin('a ttl sets the expiry', $now + 60, $expires('n:short'));
$now += 61;
pin('an expired one-off is absent', 0, $request()->view(1, false)->count());
$reset();
$service = $request();
for ($i = 0; $i < 100; $i++) {
    $now++;
    $service->add($oneOff('row-' . $i));
}
pin('100 rows fit', 100, count($keys('n:')));
$now++;
$service->add($oneOff('row-100'));
$left = $keys('n:');
pin('the 101st keeps the count at 100', 100, count($left));
check('the oldest went', !in_array('n:row-0', $left, true));
check('the second oldest stayed', in_array('n:row-1', $left, true));
check('the new row is in', in_array('n:row-100', $left, true));
$now++;
$service->add($oneOff('row-50', 'Replaced'));
pin('replacing at the cap keeps the count', 100, count($keys('n:')));
check('replacing at the cap drops nothing', in_array('n:row-1', $keys('n:'), true));

harness_section('a repeated one-off');
$reset();
$request()->add($oneOff('rep.one', 'Same'));
$start = $now;
$now += 100;
$request()->add($oneOff('rep.one', 'Same'));
pin('a fresh repeat changes nothing', $start + AdminNotices::DEFAULT_TTL, $expires('n:rep.one'));
$now += 5 * 86400;
$request()->add($oneOff('rep.one', 'Same'));
pin('an old repeat extends the life', $now + AdminNotices::DEFAULT_TTL, $expires('n:rep.one'));
pin('...and keeps the time', $start, $request()->view(1, false)->active[0]->since);

harness_section('standing checks: since');
$reset();
$fires = true;
$check = static function (AdminNotices $s) use (&$fires): void {
    $s->addCheck(['id' => 'chk.a', 'type' => 'error', 'text' => 'Check text'], static function () use (&$fires): bool {
        return $fires;
    });
};
$page = static function () use ($request, $check): AdminNotices {
    $s = $request();
    $check($s);

    return $s;
};
$first = $now;
pin('a check that fires shows', ['chk.a'], $ids($page()->view(1, false)->active));
pin('since: is written', ['since:chk.a'], $keys());
pin('since: has no expiry', null, $expires('since:chk.a'));
$now += 500;
pin('since stays at the first time', $first, $page()->view(1, false)->active[0]->since);
$fires = false;
pin('a check that stops is gone', 0, $page()->view(1, false)->count());
pin('since: is deleted when it stops', [], $keys());

harness_section('a check that throws');
$reset();
$kv->set('admin_notice', 'since:chk.boom', '{"at":5}', null);
$kv->set('admin_notice', 'snooze:1:chk.boom', '{"until":' . ($now + 99) . '}', $now + 99);
$s = $request();
$s->addCheck(['id' => 'chk.boom', 'type' => 'error', 'text' => 'x'], static function (): bool {
    throw new RuntimeException('plugin bug');
});
$s->view(1, false);
pin('a throw keeps since: and the hide', ['since:chk.boom', 'snooze:1:chk.boom'], $keys());

harness_section('hide and show');
$reset();
$fires = true;
$s     = $page();
check('hide succeeds', $s->hide(1, false, 'chk.a'));
pin('snooze key', ['since:chk.a', 'snooze:1:chk.a'], $keys());
pin('snooze ends after 7 days', $now + 7 * 86400, $expires('snooze:1:chk.a'));
$view = $page()->view(1, false);
pin('hidden from this admin', [[], ['chk.a']], [$ids($view->active), $ids($view->hidden)]);
pin('comes back date is set', $now + 7 * 86400, $view->hidden[0]->hiddenUntil);
pin('a second admin still sees it', ['chk.a'], $ids($page()->view(2, false)->active));
pin('admin 11 is not admin 1', ['chk.a'], $ids($page()->view(11, false)->active));
check('a hidden id cannot be hidden again', !$page()->hide(1, false, 'chk.a'));
check('an unknown id is refused', !$page()->hide(1, false, 'no.such'));
pin('a refused hide writes nothing', ['since:chk.a', 'snooze:1:chk.a'], $keys());
check('show of a row not hidden is refused', !$page()->show(2, 'chk.a'));
check('show succeeds', $page()->show(1, 'chk.a'));
pin('show deletes the snooze', ['since:chk.a'], $keys());
pin('shown again', ['chk.a'], $ids($page()->view(1, false)->active));
$s = $page();
$s->hide(1, false, 'chk.a');
pin('the view after a hide reads nothing more', 0, harness_query_count(static fn () => $s->view(1, false)));
pin('hide is seen at once in the same request', [[], ['chk.a']], [$ids($s->view(1, false)->active), $ids($s->view(1, false)->hidden)]);
$s->show(1, 'chk.a');
pin('show is seen at once in the same request', [['chk.a'], []], [$ids($s->view(1, false)->active), $ids($s->view(1, false)->hidden)]);

harness_section('moderators');
$reset();
$service = $request();
$service->add($oneOff('adm.only'));
$service->add($oneOff('mod.too', 'For both', true));
pin('a moderator sees only marked rows', ['mod.too'], $ids($request()->view(3, true)->active));
pin('an admin sees both', ['adm.only', 'mod.too'], $ids($request()->view(1, false)->active));
check('a moderator cannot hide an admin-only row', !$request()->hide(3, true, 'adm.only'));
pin('that wrote no snooze', [], $keys('snooze:'));
check('a moderator can hide their own row', $request()->hide(3, true, 'mod.too'));

harness_section('snooze expiry and re-fire');
$reset();
$fires = true;
$page()->hide(1, false, 'chk.a');
$now += 7 * 86400 - 1;
pin('still hidden just before', 1, count($page()->view(1, false)->hidden));
$now += 2;
$view = $page()->view(1, false);
pin('comes back after 7 days', [['chk.a'], []], [$ids($view->active), $ids($view->hidden)]);

$reset();
$request()->add($oneOff('oneoff.x'));
$request()->hide(1, false, 'oneoff.x');
$request()->clear('oneoff.x');
pin('clear removes every snooze', [], $keys('snooze:'));
$request()->add($oneOff('oneoff.x'));
pin('a re-fire after a clear is not still hidden', ['oneoff.x'], $ids($request()->view(1, false)->active));

$reset();
$fires = true;
$page()->hide(1, false, 'chk.a');
$page()->hide(2, false, 'chk.a');
$fires = false;
$page()->view(1, false);
pin('a stopped check takes every snooze with it', [], $keys());
$fires = true;
pin('a re-fire after it stopped is not still hidden', ['chk.a'], $ids($page()->view(1, false)->active));

$reset();
$service = $request();
$service->add($oneOff('other:bar'));
$service->add($oneOff('bar'));
$service->hide(1, false, 'other:bar');
$service->hide(1, false, 'bar');
$request()->clear('bar');
pin('clearing bar leaves the hide of other:bar', ['snooze:1:other:bar'], $keys('snooze:'));

$reset();
$fires = true;
$page()->hide(1, false, 'chk.a');
$request()->clear('chk.a');
pin('clear of a standing id removes since: and snooze:', [], $keys());

$reset();
$early = $request();
$early->view(1, false);
$request()->add($oneOff('later.row'));
$early->clear('later.row');
pin('clear reads the rows again, for a long-running process', [], $keys());

harness_section('feed: incomplete deletes nothing');
$incomplete = new class () extends AdminNoticeFeed {
    public function notices(AdminNoticeStore $s, int $admin, bool $moderator, int $now): array
    {
        return [[], false];
    }
};
$reset();
$fires = true;
$page()->hide(1, false, 'chk.a');
$before = $keys();
pin('an incomplete feed leaves since: and snooze: rows', $before, (static function () use ($request, $incomplete, $keys): array {
    $request($incomplete)->view(1, false);

    return $keys();
})());

harness_section('a moderator page never ends a notice');
$reset();
$fires = true;
$page()->view(1, false);
$fires = false;
$page()->view(3, true);
pin('since: survives a moderator page', ['since:chk.a'], $keys());

harness_section('checks row and refresh claim');
$reset();
$store = new AdminNoticeStore(new KeyValue(), $clock);
pin('no snapshot yet', null, $store->checks());
$store->saveChecks(['cron_last' => 5], $now);
pin('the snapshot reads back', ['at' => $now, 'env' => ['cron_last' => 5]], (new AdminNoticeStore(new KeyValue(), $clock))->checks());
pin('the checks key', ['checks'], $keys('checks'));
pin('checks has no expiry', null, $expires('checks'));
$store->forgetChecks();
pin('forgetChecks deletes it', [null, []], [$store->checks(), $keys('checks')]);
check('the first claim wins', $store->claimRefresh($now));
check('a second claim loses', !(new AdminNoticeStore(new KeyValue(), $clock))->claimRefresh($now));
pin('the claim key', ['checks:lock'], $keys('checks'));
pin('the claim lasts 60 s', $now + 60, $expires('checks:lock'));
$store->releaseRefresh();
pin('release deletes it', [], $keys('checks'));

harness_section('query budget');
$reset();
$fires = true;
$service = $request();
$service->add($oneOff('oneoff.q'));
$page()->view(1, false);
$s = $page();
pin('two views with no changes cost one read', 1, harness_query_count(static function () use ($s): void {
    $s->view(1, false);
    $s->view(1, false);
}));
$s = $request();
pin('clearing an id with no rows writes nothing', 1, harness_query_count(static fn () => $s->clear('nothing.here')));

$reset();
if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
