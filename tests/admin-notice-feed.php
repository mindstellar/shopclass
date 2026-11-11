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
 * The notice feed without a database: the cached environment and its 15-minute refresh, the
 * lost claim, the row for each check, the moderator row, one row per payment method in test
 * mode, and the backup failure the bell gets through BackupJobs.
 *
 * Usage: php tests/admin-notice-feed.php
 */

declare(strict_types=1);

namespace mindstellar\model {
    /** An in-memory t_key_value, so this test needs no database. */
    final class KeyValue
    {
        public static bool $claimable = true;

        /** @var array<string,array{value:?string, expires:?int}> */
        public static array $rows = [];

        /** @return array<string,array{value:?string, expires:?int}> */
        public function group(string $group, int $limit = 1000, ?int $now = null): array
        {
            return array_filter(self::$rows, static fn (array $r): bool => $r['expires'] === null || $r['expires'] > ($now ?? time()));
        }

        public function set(string $group, string $key, ?string $value, ?int $expiresAt = null, ?string $state = null, ?int $now = null): void
        {
            self::$rows[$key] = ['value' => $value, 'expires' => $expiresAt];
        }

        public function delete(string $group, string $key): int
        {
            unset(self::$rows[$key]);

            return 1;
        }

        public function claim(string $group, string $key, int $expiresAt, string $state = 'locked', ?string $value = null, ?int $now = null): bool
        {
            if (!self::$claimable) {
                return false;
            }
            self::$rows[$key] = ['value' => $value, 'expires' => $expiresAt];

            return true;
        }
    }
}

namespace {
    error_reporting(E_ALL & ~E_DEPRECATED);
    define('ABS_PATH', dirname(__DIR__) . '/');

    require_once __DIR__ . '/lib/harness.php';
    require_once __DIR__ . '/lib/stubs.php';
    require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

    use mindstellar\admin\AdminNotice;
    use mindstellar\admin\AdminNoticeFeed;
    use mindstellar\admin\AdminNotices;
    use mindstellar\admin\AdminNoticeStore;
    use mindstellar\admin\SystemChecks;
    use mindstellar\backup\BackupJobs;
    use mindstellar\model\KeyValue;

    function osc_base_url($withIndex = false)
    {
        return 'https://example.com/';
    }

    ini_set('error_log', (string) tempnam(sys_get_temp_dir(), 'feed_'));

    $now = 1_800_000_000;

    /** A feed with the environment and the test-mode list under the test's control. */
    final class StandInFeed extends AdminNoticeFeed
    {
        public int $reads = 0;
        public bool $readFails = false;
        public bool $modesFail = false;

        /** @var array<string,mixed> */
        public array $env = [];

        /** @var array<string,string> */
        public array $modes = [];

        /** @var callable[] */
        public array $held = [];
        public bool $hold = false;

        /** @var bool[] */
        public array $quick = [];

        protected function afterResponse(callable $work): void
        {
            if ($this->hold) {
                $this->held[] = $work;
            } else {
                $work();
            }
        }

        protected function readEnvironment(bool $quick): array
        {
            $this->reads++;
            $this->quick[] = $quick;
            if ($this->readFails) {
                throw new \RuntimeException('read failed');
            }

            return $this->env;
        }

        protected function testModes(): array
        {
            if ($this->modesFail) {
                throw new \RuntimeException('modes failed');
            }

            return $this->modes;
        }
    }

    $healthy = static fn (array $over = []): array => $over + [
        'admin_url' => 'https://example.com/oc-admin/index.php', 'version' => '7.1.0', 'php' => '8.2.10',
        'db_server' => '10.6.12-MariaDB', 'extensions' => SystemChecks::EXTENSIONS, 'imagick' => true, 'gd' => true,
        'opcache' => true, 'uploads_writable' => true, 'memory' => 256 * 1024 * 1024, 'upload' => 16 * 1024 * 1024,
        'post' => 20 * 1024 * 1024, 'max_files' => 20, 'photos' => 12, 'free_disk' => 20 * 1024 * 1024 * 1024,
        'cron_last' => 1_800_000_000 - 300, 'cache_driver' => 'default', 'cache_supported' => true, 'cache_working' => null,
        'jobs' => ['error' => 0, 'stuck' => 0, 'overdue' => null, 'orphans' => []],
        'admins' => [['id' => 1, 'username' => 'owner', 'two_factor' => true]],
        'throttle' => ['enabled' => true, 'blocked' => 0], 'debug' => false, 'config_writable' => false, 'maintenance' => '',
        'proxy' => null, 'backup_last' => ['date' => date('c', 1_800_000_000 - 86400), 'what' => 'everything', 'where' => 'server'],
        'backup_probe' => false,
    ];

    $reset = static function (): void {
        KeyValue::$rows      = [];
        KeyValue::$claimable = true;
    };
    $feed = static function (array $env, array $modes = []) use ($healthy): StandInFeed {
        $f        = new StandInFeed();
        $f->env   = $healthy($env);
        $f->modes = $modes;

        return $f;
    };
    $store = static fn (): AdminNoticeStore => new AdminNoticeStore(new KeyValue(), static fn (): int => 1_800_000_000);
    $ids   = static fn (array $rows): array => array_map(static fn (AdminNotice $n): string => $n->id, $rows);

    harness_section('the cached environment and its refresh');
    $reset();
    $f = $feed(['debug' => true]);
    [$rows, $complete] = $f->notices($store(), 1, false, $now);
    pin('no copy yet: a quick read shows the row, the full read follows', [[true, false], false, ['debug_on']], [$f->quick, $complete, $ids($rows)]);
    pin('the copy is saved with no expiry', null, KeyValue::$rows['checks']['expires']);
    pin('the lock is released', false, isset(KeyValue::$rows['checks:lock']));
    [$rows, $complete] = $f->notices($store(), 1, false, $now + 100);
    pin('a fresh copy: no more reads, and complete', [2, true], [$f->reads, $complete]);
    pin('...and the same row', ['debug_on'], $ids($rows));
    $f->hold = true;
    [$rows]  = $f->notices($store(), 1, false, $now + 901);
    pin('a copy over 900 s old: the page does not wait', [2, ['debug_on']], [$f->reads, $ids($rows)]);
    $f->notices($store(), 1, false, $now + 901);
    pin('...the read is scheduled once', 1, count($f->held));
    $f->env = $healthy();
    $f->held[0]();
    pin('...and runs after the response', 3, $f->reads);
    $f->held = [];
    [$rows]  = $f->notices($store(), 1, false, $now + 901 + 100);
    pin('the problem gone: no row after the refresh', [3, []], [$f->reads, $ids($rows)]);

    harness_section('a lost claim, a failed read');
    $reset();
    KeyValue::$claimable = false;
    $f = $feed(['debug' => true], ['PayPal' => 'https://example.com/oc-admin/index.php?page=settings&action=custom&id=paypal']);
    [$rows, $complete] = $f->notices($store(), 1, false, $now);
    pin('no copy and the claim lost: not complete, no read, only the test-mode row', [false, 0, ['pay_test:paypal']], [$complete, $f->reads, $ids($rows)]);
    $f->env = $healthy(['debug' => true]);
    KeyValue::$claimable = true;
    $f->notices($store(), 1, false, $now);
    KeyValue::$claimable = false;
    [$rows, $complete] = $f->notices($store(), 1, false, $now + 2000);
    pin('an old copy and the claim lost: the old copy is used, complete', [true, 2], [$complete, $f->reads]);
    check('...with its row', in_array('debug_on', $ids($rows), true));
    $reset();
    $f = $feed(['debug' => true]);
    $f->readFails = true;
    [$rows, $complete] = $f->notices($store(), 1, false, $now);
    pin('a failed read with no copy: not complete, the lock stays so retries wait', [false, [], true], [$complete, $ids($rows), isset(KeyValue::$rows['checks:lock'])]);
    $f->readFails = false;
    $f->notices($store(), 1, false, $now);
    $f->readFails = true;
    [$rows, $complete] = $f->notices($store(), 1, false, $now + 2000);
    pin('a failed read keeps the old copy', [true, ['debug_on']], [$complete, $ids($rows)]);

    harness_section('remember() and forget()');
    $reset();
    $f = $feed([]);
    $f->remember($store(), $healthy(['debug' => true, 'pending' => ['x'], 'signins' => [1, 2]]), $now);
    check('System info saves the trimmed env', isset(KeyValue::$rows['checks']) && !str_contains((string) KeyValue::$rows['checks']['value'], 'signins') && !str_contains((string) KeyValue::$rows['checks']['value'], 'pending'));
    [$rows] = $f->notices($store(), 1, false, $now + 10);
    pin('it feeds the bell without a read', [0, ['debug_on']], [$f->reads, $ids($rows)]);
    $store()->forgetChecks();
    pin('forgetting drops it', false, isset(KeyValue::$rows['checks']));

    harness_section('what drops the copy');
    $reset();
    KeyValue::$rows['checks'] = ['value' => (string) json_encode(['at' => time(), 'env' => ['cron_last' => time() - 60]]), 'expires' => null];
    AdminNoticeFeed::cronRan();
    check('cron running as usual keeps the copy', isset(KeyValue::$rows['checks']));
    KeyValue::$rows['checks'] = ['value' => (string) json_encode(['at' => time(), 'env' => ['cron_last' => 0]]), 'expires' => null];
    AdminNoticeFeed::cronRan();
    check('the first run after cron had stopped drops it', !isset(KeyValue::$rows['checks']));

    harness_section('one row for each check');
    $reset();
    $firing = $healthy([
        'db_server' => '5.5.1-MySQL', 'debug' => true, 'cron_last' => 0, 'backup_last' => null, 'backup_probe' => true,
        'maintenance' => 'locked', 'config_writable' => true, 'cache_driver' => 'redis', 'cache_supported' => false,
        'jobs' => ['error' => 2, 'stuck' => 0, 'overdue' => null, 'orphans' => []],
        'throttle' => ['enabled' => false, 'blocked' => 4],
        'admins' => [['id' => 1, 'username' => 'owner', 'two_factor' => false], ['id' => 2, 'username' => 'mod', 'two_factor' => false]],
        'live' => ['mode' => 'nchan', 'last' => ['ok' => false, 'at' => $now - 60, 'error' => 'x']],
    ]);
    $f = $feed($firing);
    [$rows] = $f->notices($store(), 1, false, $now);
    $by = [];
    foreach ($rows as $n) {
        $by[$n->id] = $n;
    }
    check('the fixture fires many checks', count($by) >= 10);
    foreach ($by as $id => $n) {
        check("$id has a place, a source, a link and text", in_array($n->menu, ['tools', 'users'], true) && $n->source === 'System info' && $n->link !== '' && $n->linkLabel !== '' && $n->text !== '');
    }
    pin('a red line is an error', 'error', $by['backups_open']->type);
    pin('an amber line is a warning', 'warning', $by['debug_on']->type);
    pin('backups_open sits under Backup', ['tools', 'tools_backup'], [$by['backups_open']->menu, $by['backups_open']->submenu]);
    pin('backup_none sits under Backup', 'tools_backup', $by['backup_none']->submenu);
    pin('maintenance_on sits under Maintenance', 'tools_maintenance', $by['maintenance_on']->submenu);
    pin('jobs_failed sits under System info', 'tools_system_info', $by['jobs_failed']->submenu);
    pin('cron_never sits under System info', 'tools_system_info', $by['cron_never']->submenu);
    pin('cache_unsupported sits under System info', 'tools_system_info', $by['cache_unsupported']->submenu);
    pin('signin_protection_off sits under Users', ['users', ''], [$by['signin_protection_off']->menu, $by['signin_protection_off']->submenu]);
    pin('config_writable sits under System info', ['tools', 'tools_system_info'], [$by['config_writable']->menu, $by['config_writable']->submenu]);
    check('signin_blocked is not here', !isset($by['signin_blocked']));
    pin('the first sentence is the text, the rest the detail', ['config.php can be written by the web server.', 'It holds your database password; make it read-only.'], [$by['config_writable']->text, $by['config_writable']->detail]);
    pin('a line with no action points at System info', ['https://example.com/oc-admin/index.php?page=tools&action=system-info', 'See details'], [$by['config_writable']->link, $by['config_writable']->linkLabel]);
    pin('a link out of the admin points at System info too', ['https://example.com/oc-admin/index.php?page=tools&action=system-info', 'See details'], [$by['backups_open']->link, $by['backups_open']->linkLabel]);
    pin('an action inside the admin keeps its link and label', ['https://example.com/oc-admin/index.php?page=tools&action=maintenance', 'Maintenance mode'], [$by['maintenance_on']->link, $by['maintenance_on']->linkLabel]);

    harness_section('the owner and the moderator');
    pin('an admin who is in the list keeps "Turn on yours"', 'Turn on yours', $by['admins_no_2fa']->linkLabel);
    check('...and the list of names', str_contains($by['admins_no_2fa']->text, 'owner, mod'));
    [$mod] = $feed($firing)->notices($store(), 2, true, $now);
    $modBy = [];
    foreach ($mod as $n) {
        $modBy[$n->id] = $n;
    }
    pin('a moderator in the list sees a row about themselves', ['You sign in with a password only.', 'Turn on yours', 'https://example.com/oc-admin/index.php?page=admins&action=edit', true], [
        $modBy['admins_no_2fa']->text, $modBy['admins_no_2fa']->linkLabel, $modBy['admins_no_2fa']->link, $modBy['admins_no_2fa']->moderator,
    ]);
    pin('the names stay off the moderator row', '', $modBy['admins_no_2fa']->detail);
    $reset();
    [$other] = $feed($healthy(['admins' => [['id' => 1, 'username' => 'owner', 'two_factor' => false], ['id' => 2, 'username' => 'mod', 'two_factor' => true]]]))->notices($store(), 2, true, $now);
    check('a moderator who is not in the list gets no such row', !in_array('admins_no_2fa', $ids($other), true));
    check('...and an admin row is the only one a moderator may see', array_diff($ids(array_filter($by, static fn (AdminNotice $n): bool => $n->moderator)), ['admins_no_2fa']) === []);

    harness_section('every service view: moderators');
    $reset();
    $clock = static fn (): int => 1_800_000_000;
    $service = new AdminNotices(new AdminNoticeStore(new KeyValue(), $clock), $feed($firing), $clock, true);
    pin('a moderator sees only the 2FA row', ['admins_no_2fa'], $ids($service->view(2, true)->active));
    check('an admin sees many', count($service->view(1, false)->active) > 5);

    harness_section('one row for each payment method in test mode');
    $reset();
    $modes = ['PayPal' => 'https://example.com/oc-admin/index.php?page=settings&action=custom&id=paypal', 'Razorpay' => 'https://example.com/oc-admin/index.php?page=settings&action=custom&id=razorpay'];
    $f     = $feed([], $modes);
    [$rows] = $f->notices($store(), 1, false, $now);
    pin('one row each', ['pay_test:paypal', 'pay_test:razorpay'], $ids($rows));
    pin('the text, detail and link', [
        'warning', 'PayPal is in test mode: no real money is taken.', 'Switch it to live mode before the site takes payments.', $modes['PayPal'], 'PayPal settings', 'billing', 'billing_settings', 'PayPal',
    ], [$rows[0]->type, $rows[0]->text, $rows[0]->detail, $rows[0]->link, $rows[0]->linkLabel, $rows[0]->menu, $rows[0]->submenu, $rows[0]->source]);
    $service = new AdminNotices(new AdminNoticeStore(new KeyValue(), $clock), $f, $clock, true);
    pin('both are active', ['pay_test:paypal', 'pay_test:razorpay'], $ids($service->view(1, false)->active));
    pin('hiding one works', true, $service->hide(1, false, 'pay_test:paypal'));
    $service = new AdminNotices(new AdminNoticeStore(new KeyValue(), $clock), $f, $clock, true);
    $view    = $service->view(1, false);
    pin('...and leaves the other', [['pay_test:razorpay'], ['pay_test:paypal']], [$ids($view->active), $ids($view->hidden)]);
    pin('a method that goes live is no longer listed', [], $ids((new StandInFeed())->notices($store(), 1, false, $now)[0]));
    $f->modes = ['<b>Bad</b> & "name"' => 'javascript:alert(1)'];
    $service = new AdminNotices(new AdminNoticeStore(new KeyValue(), $clock), $f, $clock, true);
    $bad     = $service->view(1, false)->active[0] ?? null;
    check('a hostile name loses its tags and the hostile link is dropped', $bad !== null && !str_contains($bad->text, '<b>') && $bad->link === '', $bad?->text ?? '');
    $f->modes = ['日本' => ''];
    pin('a name with no Latin letters still gets a valid id', 1, count((new AdminNotices(new AdminNoticeStore(new KeyValue(), $clock), $f, $clock, true))->view(1, false)->active));
    $f->modes = ['Pay-Pal' => '', 'Pay Pal' => ''];
    pin('two names with one slug get two ids', 2, count(array_unique($ids($f->notices($store(), 1, false, $now)[0]))));

    harness_section('a test-mode list that cannot be read');
    $reset();
    $f = $feed([], ['PayPal' => '']);
    $f->notices($store(), 1, false, $now);
    $f->modesFail = true;
    [$rows, $complete] = $f->notices($store(), 1, false, $now);
    pin('the list is not complete, so no hide is lost', [false, []], [$complete, $ids($rows)]);
    KeyValue::$rows['since:pay_test:paypal']    = ['value' => '{"at":5}', 'expires' => null];
    KeyValue::$rows['snooze:1:pay_test:paypal'] = ['value' => '{"until":' . ($now + 99) . '}', 'expires' => null];
    (new AdminNotices(new AdminNoticeStore(new KeyValue(), $clock), $f, $clock, true))->view(1, false);
    check('since and hide of the method survive', isset(KeyValue::$rows['since:pay_test:paypal'], KeyValue::$rows['snooze:1:pay_test:paypal']));

    harness_section('a backup or restore that fails');
    $reset();
    $effect = new \ReflectionMethod(BackupJobs::class, 'effect');
    $effect->invoke(null, 'failed', ['restore' => false, 'message' => 'Disk full']);
    $raised = json_decode((string) (KeyValue::$rows['n:backup_failed']['value'] ?? ''), true);
    pin('a failed backup raises backup_failed', ['error', 'Backup failed', 'Disk full', 'tools_backup'], [$raised['type'] ?? null, $raised['text'] ?? null, $raised['detail'] ?? null, $raised['submenu'] ?? null]);
    $effect->invoke(null, 'failed', ['restore' => false, 'message' => 'Disk full again']);
    pin('a second failure replaces it', ['n:backup_failed'], array_keys(KeyValue::$rows));
    $effect->invoke(null, 'failed', ['restore' => true, 'message' => 'Bad file']);
    pin('a failed restore has its own id', ['n:backup_failed', 'n:restore_failed'], array_keys(KeyValue::$rows));

    exit(harness_result());
}
