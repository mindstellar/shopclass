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
 * The admin notices API without a database: id, type, text and link rules, callable text,
 * failures that must not break a page, standing checks outside the admin, and marks().
 *
 * Usage: php tests/admin-notice-api.php
 */

declare(strict_types=1);

namespace mindstellar\model {
    /** An in-memory t_key_value, so this test needs no database. */
    final class KeyValue
    {
        public static bool $broken = false;
        public static int $calls   = 0;

        /** @var array<string,array{value:?string, expires:?int}> */
        public static array $rows = [];

        /** @return array<string,array{value:?string, expires:?int}> */
        public function group(string $group, int $limit = 1000, ?int $now = null): array
        {
            self::touchStore();
            ksort(self::$rows);

            return array_filter(self::$rows, static fn (array $r): bool => $r['expires'] === null || $r['expires'] > ($now ?? time()));
        }

        public function set(string $group, string $key, ?string $value, ?int $expiresAt = null, ?string $state = null, ?int $now = null): void
        {
            self::touchStore();
            self::$rows[$key] = ['value' => $value, 'expires' => $expiresAt];
        }

        public function delete(string $group, string $key): int
        {
            self::touchStore();
            unset(self::$rows[$key]);

            return 1;
        }

        private static function touchStore(): void
        {
            self::$calls++;
            if (self::$broken) {
                throw new \RuntimeException('database is down');
            }
        }
    }
}

namespace {
    define('ABS_PATH', dirname(__DIR__) . '/');

    require_once __DIR__ . '/lib/harness.php';
    foreach (['AdminNotice', 'AdminNoticeView', 'AdminNoticeStore', 'AdminNoticeFeed', 'AdminNotices'] as $class) {
        require_once ABS_PATH . 'oc-includes/classes/admin/' . $class . '.php';
    }
    require_once ABS_PATH . 'oc-includes/classes/core/utility/Sanitize.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminNotices.php';

    use mindstellar\admin\AdminNotice;
    use mindstellar\admin\AdminNotices;
    use mindstellar\admin\AdminNoticeStore;
    use mindstellar\admin\AdminNoticeView;
    use mindstellar\model\KeyValue;

    function osc_base_url($withIndex = false)
    {
        return 'https://example.com/';
    }

    function osc_logged_admin_id()
    {
        return $GLOBALS['testAdminId'];
    }

    function osc_is_moderator()
    {
        $GLOBALS['testRoleReads']++;

        return false;
    }

    /** Core's rows are not in play here, and the list counts as complete. */
    final class CompleteFeed extends \mindstellar\admin\AdminNoticeFeed
    {
        public function notices(AdminNoticeStore $s, int $admin, bool $moderator, int $now): array
        {
            return [[], true];
        }
    }

    $logFile = (string) tempnam(sys_get_temp_dir(), 'notices_');
    ini_set('error_log', $logFile);
    $logged = static fn (): string => (string) file_get_contents($logFile);
    $GLOBALS['testAdminId']   = 0;
    $GLOBALS['testRoleReads'] = 0;

    $now     = 1_800_000_000;
    $clock   = static fn (): int => $now;
    $service = static function (bool $inAdmin = true) use ($clock): AdminNotices {
        return new AdminNotices(new AdminNoticeStore(new KeyValue(), $clock), null, $clock, $inAdmin);
    };
    $reset = static function (): void {
        KeyValue::$rows   = [];
        KeyValue::$broken = false;
        KeyValue::$calls  = 0;
    };
    $stored = static fn (string $id): ?array => isset(KeyValue::$rows['n:' . $id]) ? json_decode((string) KeyValue::$rows['n:' . $id]['value'], true) : null;

    harness_section('ids');
    $ok = ['a', '0', 'stripe:test_mode', 'pay_test:my.gateway-1', str_repeat('a', 101)];
    foreach ($ok as $id) {
        $reset();
        $service()->add(new AdminNotice($id, 'info', 'Text'));
        check('id accepted: ' . substr($id, 0, 30), $stored($id) !== null);
    }
    $bad = ['', '_a', '-a', ':a', 'A', 'aB', 'a b', "a\n", 'a/b', '<a>', str_repeat('a', 102), 'é'];
    foreach ($bad as $id) {
        $reset();
        $service()->add(new AdminNotice($id, 'info', 'Text'));
        pin('id refused: ' . json_encode(substr($id, 0, 30)), [], KeyValue::$rows);
    }
    check('a bad id is logged', str_contains($logged(), 'Admin notice'));

    harness_section('type');
    foreach (['error', 'warning', 'info'] as $type) {
        $reset();
        $service()->add(new AdminNotice('t', $type, 'Text'));
        check('type accepted: ' . $type, $stored('t') !== null);
    }
    foreach (['danger', 'ERROR', ''] as $type) {
        $reset();
        $service()->add(new AdminNotice('t', $type, 'Text'));
        pin('type refused: "' . $type . '"', [], KeyValue::$rows);
    }
    pin('error is the action group', 'action', (new AdminNotice('t', 'error', 'x'))->group());
    pin('warning is heads-up', 'headsup', (new AdminNotice('t', 'warning', 'x'))->group());
    pin('info is heads-up', 'headsup', (new AdminNotice('t', 'info', 'x'))->group());

    harness_section('text');
    $reset();
    $service()->add(new AdminNotice('t', 'info', '<b>Bold</b> <script>x()</script>text', '<i>Detail</i>'));
    pin('tags stripped from text', 'Bold x()text', $stored('t')['text']);
    pin('tags stripped from detail', 'Detail', $stored('t')['detail']);
    $reset();
    $service()->add(new AdminNotice('t', 'info', str_repeat('ü', 600), str_repeat('d', 600)));
    pin('text capped at 500 characters', 500, mb_strlen($stored('t')['text'], 'UTF-8'));
    pin('detail capped at 500 characters', 500, mb_strlen($stored('t')['detail'], 'UTF-8'));
    $reset();
    $service()->add(new AdminNotice('t', 'info', '<b></b>'));
    pin('text that is only tags is skipped', [], KeyValue::$rows);
    $reset();
    $service()->add(new AdminNotice('t', 'info', "Bad \xB1 byte"));
    check('invalid UTF-8 is repaired, not refused', $stored('t') !== null);

    harness_section('links');
    $allowed = ['', '?page=tools', '?page=tools&action=backup', 'https://example.com/', 'https://example.com/oc-admin/?page=items'];
    foreach ($allowed as $link) {
        $reset();
        $service()->add(new AdminNotice('t', 'info', 'Text', '', $link, 'Open'));
        pin('link kept: ' . ($link === '' ? '(empty)' : $link), $link, $stored('t')['link']);
    }
    $hostile = [
        'javascript:alert(1)',
        ' javascript:alert(1)',
        'data:text/html,x',
        '//evil.test/',
        'http://example.com/',
        'https://example.com.evil.test/',
        'https://example.comevil.test/',
        'https://evil.test/?https://example.com/',
        'page=tools',
        '?page=x y',
        "?page=x\ny",
        "https://example.com/\nx",
    ];
    foreach ($hostile as $link) {
        $reset();
        $service()->add(new AdminNotice('t', 'info', 'Text', '', $link, 'Open'));
        pin('link dropped: ' . json_encode($link), ['', ''], [$stored('t')['link'], $stored('t')['linkLabel']]);
    }

    harness_section('callables run only at render');
    $reset();
    $ran = 0;
    $s   = $service();
    $s->addCheck(['id' => 'c.one', 'type' => 'warning', 'text' => static function () use (&$ran): string {
        $ran++;

        return 'From a callable';
    }, 'detail' => static fn (): string => 'Detail too', 'link' => '?page=x', 'link_label' => static fn (): string => 'Label too'], static fn (): bool => true);
    pin('not run at registration', 0, $ran);
    $row = $s->view(1, false)->active[0];
    pin('run at render', 1, $ran);
    pin('text, detail and label come from callables', ['From a callable', 'Detail too', 'Label too'], [$row->text, $row->detail, $row->linkLabel]);
    $s->view(1, false);
    pin('the view is memoised', 1, $ran);

    $ran = 0;
    $s   = $service();
    $s->addCheck(['id' => 'c.two', 'type' => 'warning', 'text' => static function () use (&$ran): string {
        $ran++;

        return 'x';
    }], static fn (): bool => false);
    pin('a check that does not fire shows nothing', 0, $s->view(1, false)->count());
    pin('and its text never runs', 0, $ran);

    $reset();
    $s = $service();
    $s->raise('info', 'strtoupper', ['id' => 'plain.word']);
    pin('a string that names a function stays text', 'strtoupper', $stored('plain.word')['text']);
    $s->raise('info', static fn (): string => 'One-off text', ['id' => 'oneoff.call']);
    pin('a one-off resolves its callable when stored', 'One-off text', $stored('oneoff.call')['text']);

    harness_section('failures never break a page');
    $reset();
    $s = $service();
    $s->addCheck(['id' => 'boom', 'type' => 'error', 'text' => 'x'], static function (): bool {
        throw new RuntimeException('plugin bug');
    });
    $s->addCheck(['id' => 'boom.text', 'type' => 'error', 'text' => static function (): string {
        throw new RuntimeException('text bug');
    }], static fn (): bool => true);
    $s->addCheck(['id' => 'fine', 'type' => 'info', 'text' => 'Fine'], static fn (): bool => true);
    pin('a throwing when or text counts as not firing', ['fine'], array_map(static fn (AdminNotice $n): string => $n->id, $s->view(1, false)->active));
    check('the throw is logged', str_contains($logged(), 'plugin bug'));

    $reset();
    $s = new AdminNotices(new AdminNoticeStore(new KeyValue(), $clock), new CompleteFeed(), $clock, true);
    $s->addCheck(['id' => 'boom', 'type' => 'error', 'text' => 'x'], static function (): bool {
        throw new RuntimeException('plugin bug');
    });
    KeyValue::$rows['since:boom']    = ['value' => '{"at":5}', 'expires' => null];
    KeyValue::$rows['since:gone']    = ['value' => '{"at":5}', 'expires' => null];
    KeyValue::$rows['snooze:1:boom'] = ['value' => '{"until":' . ($now + 99) . '}', 'expires' => null];
    $s->view(1, false);
    check('a throwing check keeps its since row and every hide', isset(KeyValue::$rows['since:boom'], KeyValue::$rows['snooze:1:boom']));
    check('...while a check that stopped is ended', !isset(KeyValue::$rows['since:gone']));

    $reset();
    KeyValue::$broken = true;
    $s = $service();
    $s->addCheck(['id' => 'chk', 'type' => 'error', 'text' => 'x'], static fn (): bool => true);
    pin('a broken store gives an empty view', [0, 0], [$s->view(1, false)->count(), count($s->view(1, false)->hidden)]);
    $threw = false;
    try {
        $s->add(new AdminNotice('t', 'info', 'Text'));
        $s->clear('t');
        check('hide on a broken store is false', !$s->hide(1, false, 't'));
        check('show on a broken store is false', !$s->show(1, 't'));
    } catch (Throwable $e) {
        $threw = true;
    }
    check('nothing throws on a broken store', !$threw);

    harness_section('bad input is logged and skipped');
    $reset();
    $s = $service();
    osc_add_admin_notice('warning', 'x', ['id' => 'no.when', 'when' => 'not_a_function_anywhere']);
    pin('a when that cannot be called is skipped', [], array_keys(KeyValue::$rows));
    check('...and logged', str_contains($logged(), 'when is not callable'));
    $s->addCheck(['type' => 'warning', 'text' => 'x'], static fn (): bool => true);
    pin('a check with no id is skipped', 0, $s->view(1, false)->count());
    $s = $service();
    $s->addCheck(['id' => 'a', 'type' => 'nonsense', 'text' => 'x'], static fn (): bool => true);
    pin('a check with a bad type is skipped', 0, $s->view(1, false)->count());
    $reset();
    $s = $service();
    $s->raise('info', 'Same text');
    $s->raise('info', 'Same text');
    $s->raise('info', 'Other text');
    pin('auto ids: the same notice replaces itself', 2, count(KeyValue::$rows));
    foreach (array_keys(KeyValue::$rows) as $key) {
        check('an auto id is a valid id: ' . $key, preg_match('/^n:[a-z0-9][a-z0-9_.:-]{0,100}$/D', $key) === 1);
    }
    $reset();
    $s = $service();
    $s->raise('info', 'x', ['id' => 'BAD ID']);
    pin('a bad given id does not fall back to an auto one', [], KeyValue::$rows);

    harness_section('a one-off raised on every page');
    $reset();
    $at    = 1_800_000_000;
    $tick  = static function () use (&$at): int {
        return $at;
    };
    $page = static fn (): AdminNotices => new AdminNotices(new AdminNoticeStore(new KeyValue(), $tick), null, $tick, true);
    $page()->raise('info', 'Same', ['id' => 'same.one']);
    $first = KeyValue::$rows['n:same.one'];
    $at   += 100;
    $s     = $page();
    $calls = KeyValue::$calls;
    $s->raise('info', 'Same', ['id' => 'same.one']);
    pin('a fresh repeat is one read and no write', [1, $first], [KeyValue::$calls - $calls, KeyValue::$rows['n:same.one']]);
    $at += 4 * 86400;
    $page()->raise('info', 'Same', ['id' => 'same.one']);
    pin('an old repeat keeps its since', 1_800_000_000, $stored('same.one')['since']);
    pin('...and extends its life', $at + AdminNotices::DEFAULT_TTL, KeyValue::$rows['n:same.one']['expires']);
    $page()->raise('warning', 'Changed', ['id' => 'same.one']);
    pin('changed content is a new notice', ['Changed', $at], [$stored('same.one')['text'], $stored('same.one')['since']]);

    $reset();
    $at = 1_800_000_000;
    $s  = $page();
    $s->raise('info', 'Real one', ['id' => 'real.one']);
    $at += 10;
    for ($i = 0; $i < 100; $i++) {
        $s->raise('info', 'Count is ' . $i);
    }
    check('changing text without an id cannot push out a real notice', $stored('real.one') !== null);
    pin('...and the cap holds', 100, count(KeyValue::$rows));

    harness_section('outside the admin');
    $reset();
    $s   = $service(false);
    $ran = 0;
    $s->addCheck(['id' => 'off', 'type' => 'info', 'text' => 'x'], static function () use (&$ran): bool {
        $ran++;

        return true;
    });
    pin('no query at registration', 0, KeyValue::$calls);
    pin('the check is not kept', 0, $s->view(1, false)->count());
    pin('its when never ran', 0, $ran);

    $reset();
    $ran = 0;
    pin('this process is not an admin request', false, defined('OC_ADMIN') && OC_ADMIN);
    osc_add_admin_notice('info', 'x', ['id' => 'off', 'when' => static function () use (&$ran): bool {
        $ran++;

        return true;
    }]);
    pin('the helper runs no query for a when', 0, KeyValue::$calls);
    osc_add_admin_check('off.two', 'info', 'x', static function () use (&$ran): bool {
        $ran++;

        return true;
    });
    pin('nor for osc_add_admin_check, and the check is not kept', [0, 0], [KeyValue::$calls, $ran]);

    harness_section('the helpers');
    $reset();
    $GLOBALS['testAdminId'] = 0;
    pin('nobody signed in: an empty view', 0, osc_admin_notices()->count());
    pin('and no query', 0, KeyValue::$calls);
    $GLOBALS['testRoleReads'] = 0;
    osc_add_admin_notice('warning', 'Helper text', ['id' => 'h.one', 'link' => '?page=x', 'link_label' => 'Go', 'menu' => 'tools', 'submenu' => 'tools_backup', 'source' => 'Acme', 'moderator' => true, 'ttl' => 90]);
    pin('a one-off is stored with its options', ['Go', 'tools', 'tools_backup', 'Acme', true], (static function () use ($stored): array {
        $row = $stored('h.one');

        return [$row['linkLabel'], $row['menu'], $row['submenu'], $row['source'], $row['moderator']];
    })());
    pin('the ttl option sets the expiry', true, abs((int) KeyValue::$rows['n:h.one']['expires'] - time() - 90) < 5);
    $GLOBALS['testAdminId'] = 7;
    pin('a signed-in admin gets the view', 1, osc_admin_notices()->count());
    osc_admin_notices();
    pin('the role is read once a request', 1, $GLOBALS['testRoleReads']);
    osc_clear_admin_notice('h.one');
    pin('clear removes it', [], KeyValue::$rows);

    harness_section('view order and marks');
    $n = static fn (string $id, string $type, int $since, string $menu = 'tools', string $sub = ''): AdminNotice => new AdminNotice($id, $type, 'x', menu: $menu, submenu: $sub, since: $since);
    $view = new AdminNoticeView([$n('old-info', 'info', 1), $n('new-warn', 'warning', 9), $n('old-err', 'error', 2), $n('new-err', 'error', 8)], [$n('hid-w', 'warning', 9), $n('hid-e', 'error', 1)]);
    pin('active: action first, then newest', ['new-err', 'old-err', 'new-warn', 'old-info'], array_map(static fn (AdminNotice $x): string => $x->id, $view->active));
    pin('hidden sorted the same way', ['hid-e', 'hid-w'], array_map(static fn (AdminNotice $x): string => $x->id, $view->hidden));
    pin('count is every active row', 4, $view->count());
    pin('actionCount is errors only', 2, $view->actionCount());

    $marks = (new AdminNoticeView(
        [$n('a', 'error', 1, 'tools', 'tools_backup'), $n('b', 'warning', 2, 'tools', 'tools_system'), $n('c', 'info', 3, 'users'), $n('d', 'warning', 4, 'tools', 'tools_backup')],
        [$n('e', 'error', 5, 'stats'), $n('f', 'error', 5, 'users')]
    ))->marks();
    pin('a section adds up its rows', ['group' => 'action', 'count' => 3, 'action' => 1], $marks['tools']);
    pin('a sub key carries its own group', ['group' => 'action', 'count' => 2, 'action' => 1], $marks['tools/tools_backup']);
    pin('a heads-up sub key', ['group' => 'headsup', 'count' => 1, 'action' => 0], $marks['tools/tools_system']);
    pin('active wins over hidden', ['group' => 'headsup', 'count' => 1, 'action' => 0], $marks['users']);
    pin('hidden-only: group is empty', ['group' => '', 'count' => 0, 'action' => 0], $marks['stats']);
    pin('no sub key without a submenu', false, isset($marks['users/']));

    pin('actionCount leaves out heads-up rows', 1, (new AdminNoticeView([$n('a', 'error', 1), $n('b', 'warning', 1), $n('c', 'info', 1)]))->actionCount());

    harness_section('a hide that has run out');
    $reset();
    KeyValue::$rows['n:x']        = ['value' => '{"id":"x","type":"info","text":"Fine"}', 'expires' => null];
    KeyValue::$rows['snooze:1:x'] = ['value' => '{"until":' . ($now - 1) . '}', 'expires' => null];
    pin('a past until does not hide the row', 1, $service()->view(1, false)->count());

    harness_section('toArray and fromArray');
    $full = new AdminNotice('a.b', 'error', 'T', 'D', '?page=x', 'L', 'tools', 'sub', 'S', true, 11, 22);
    pin('a notice round-trips', $full->toArray(), AdminNotice::fromArray($full->toArray())->toArray());
    pin('withSince changes only since', array_merge($full->toArray(), ['since' => 5]), $full->withSince(5)->toArray());
    pin('withHiddenUntil changes only hiddenUntil', array_merge($full->toArray(), ['hiddenUntil' => 6]), $full->withHiddenUntil(6)->toArray());
    pin('a wrong-typed row falls back', ['', 'dash', 0], (static function (): array {
        $x = AdminNotice::fromArray(['text' => ['not', 'a', 'string'], 'menu' => null, 'since' => 'nope']);

        return [$x->text, $x->menu, $x->since];
    })());

    harness_section('a malformed stored row');
    $reset();
    KeyValue::$rows['n:bad']   = ['value' => 'not json', 'expires' => null];
    KeyValue::$rows['n:wrong'] = ['value' => '{"id":"other","type":"info","text":"x"}', 'expires' => null];
    KeyValue::$rows['n:ok']    = ['value' => '{"id":"ok","type":"info","text":"Fine"}', 'expires' => null];
    pin('only the good row reads', ['ok'], array_map(static fn (AdminNotice $x): string => $x->id, $service()->view(1, false)->active));

    harness_section('Boot loads the helper file');
    $boot = (string) file_get_contents(ABS_PATH . 'oc-includes/classes/core/boot/Boot.php');
    check('in Boot::HELPERS', str_contains($boot, "'osclass/helpers/hAdminNotices.php'"));

    @unlink($logFile);
    exit(harness_result());
}
