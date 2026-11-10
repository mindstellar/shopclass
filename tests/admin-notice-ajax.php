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
 * The notice_hide and notice_show endpoints: POST and CSRF only, the admin from the session,
 * a moderator kept off admin-only rows, and the reply shape. DB-free, the real controller and service.
 *
 * Usage: php tests/admin-notice-ajax.php
 */

declare(strict_types=1);

namespace mindstellar\model {
    /** An in-memory t_key_value. */
    final class KeyValue
    {
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

        public function claim(string $group, string $key, int $ttl, ?int $now = null): bool
        {
            return true;
        }
    }
}

namespace {

    use mindstellar\admin\AdminNotice;
    use mindstellar\admin\AdminNoticeFeed;
    use mindstellar\admin\AdminNotices;
    use mindstellar\admin\AdminNoticeStore;
    use mindstellar\admin\ajax\AjaxRegistry;
    use mindstellar\model\KeyValue;

    // The harness prints as it goes, so AjaxResponse's headers-already-sent notice is expected.
    error_reporting(E_ALL & ~E_USER_NOTICE);

    define('ABS_PATH', dirname(__DIR__) . '/');
    define('OC_ADMIN', true);
    define('OSC_DEBUG', false);

    require ABS_PATH . 'oc-includes/vendor/autoload.php';
    require_once __DIR__ . '/lib/harness.php';

    /** What a missing or wrong token ends in; the real check exits. */
    class CsrfRefused extends RuntimeException
    {
    }

    function osc_csrf_check(): void
    {
        if (Params::getParam('CSRFToken') !== 'good') {
            throw new CsrfRefused();
        }
    }
    function osc_logged_admin_id(): int
    {
        return $GLOBALS['admin'];
    }
    function osc_is_moderator(): bool
    {
        return $GLOBALS['moderator'];
    }
    function osc_base_url(): string
    {
        return 'https://example.test/';
    }
    function osc_format_date(string $date): string
    {
        return $date;
    }
    function _e(string $s): void
    {
        echo $s;
    }
    function osc_current_admin_theme_path(string $file): void
    {
        require ABS_PATH . 'oc-admin/themes/modern/' . $file;
    }
    require_once __DIR__ . '/lib/stubs.php';
    require_once __DIR__ . '/lib/admin-ui-stubs.php';
    require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminNotices.php';

    // The helper lives in functions.php, which cannot load without the whole core.
    preg_match('~function osc_admin_notice_name\(.*?\n}\n~s', (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/functions.php'), $nameSource);
    eval($nameSource[0]);

    class AdminSecBaseModel
    {
        protected $action;
        protected $ajax;

        public function __construct()
        {
            $this->action = Params::getParam('action');
        }

        public function isModerator()
        {
            return $GLOBALS['moderator'];
        }

        protected function refuseOnDemo($redirectUrl = null)
        {
            return false;
        }
    }

    class Session
    {
        public static function getInstance()
        {
            return new self();
        }

        public function _dropKeepForm()
        {
        }

        public function _clearVariables()
        {
        }
    }

    require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/ajax/CAdminAjax.php';

    /** A new service each run, with no standing checks, so only stored notices count. */
    function notice_service(): AdminNotices
    {
        $service = new AdminNotices(new AdminNoticeStore(), new AdminNoticeFeed(), null, true);
        (new ReflectionProperty(AdminNotices::class, 'instance'))->setValue(null, $service);

        return $service;
    }

    function notice_reset(): void
    {
        KeyValue::$rows = [];
        $service        = notice_service();
        $service->add(new AdminNotice('backups_open', 'error', 'Backups are open.', 'Detail.', '?page=tools', 'Open', 'tools', 'tools_backup'));
        $service->add(new AdminNotice('admin_only', 'warning', 'Debug is on.', moderator: false));
        $service->add(new AdminNotice('for_mods', 'warning', 'Check your sign-in.', moderator: true));
        notice_service();
    }

    /** Run the endpoint; returns its output, or how it stopped. */
    function notice_drive(string $action, array $params, string $method = 'POST', int $admin = 5, bool $moderator = false): string
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_GET                      = $method === 'GET' ? ['action' => $action] + $params : ['action' => $action];
        $_POST                     = $method === 'POST' ? $params : [];
        $_REQUEST                  = $_GET + $_POST;
        Params::init();
        $GLOBALS['admin']     = $admin;
        $GLOBALS['moderator'] = $moderator;
        ob_start();
        try {
            (new CAdminAjax())->doModel();
        } catch (CsrfRefused) {
            ob_end_clean();

            return 'csrf refused';
        }

        return (string) ob_get_clean();
    }

    harness_section('the actions are registered');
    foreach (['notice_hide', 'notice_show'] as $action) {
        check("$action needs the CSRF token", (AjaxRegistry::route($action)['csrf'] ?? false) === true);
        check("$action is open to moderators", AjaxRegistry::moderatorMay($action));
    }

    harness_section('refused requests');
    notice_reset();
    pin('no token: refused', 'csrf refused', notice_drive('notice_hide', ['id' => 'backups_open']));
    pin('wrong token: refused', 'csrf refused', notice_drive('notice_hide', ['id' => 'backups_open', 'CSRFToken' => 'bad']));
    $get = json_decode(notice_drive('notice_hide', ['id' => 'backups_open', 'CSRFToken' => 'good'], 'GET'), true);
    check('GET with a good token: refused', is_array($get) && isset($get['error']) && !isset($get['ok']), json_encode($get));
    pin('...and nothing was hidden', [], array_keys(array_filter(KeyValue::$rows, static fn (string $k): bool => str_starts_with($k, 'snooze:'), ARRAY_FILTER_USE_KEY)));

    harness_section('hide and show');
    notice_reset();
    $reply = json_decode(notice_drive('notice_hide', ['id' => 'backups_open', 'CSRFToken' => 'good']), true);
    pin('hide works', 1, $reply['ok'] ?? null);
    pin('the count drops', 2, $reply['count'] ?? null);
    pin('no action row is left', 0, $reply['action'] ?? null);
    pin('one is hidden', 1, $reply['hidden'] ?? null);
    pin('the bell name is rebuilt', 'Notices, 2 notices', $reply['name'] ?? null);
    check('the panel has the hidden list', str_contains((string) ($reply['panel'] ?? ''), 'data-osc-notice-show="backups_open"'));
    check('the row left the active list', !str_contains((string) ($reply['panel'] ?? ''), 'data-osc-notice-hide="backups_open"'));
    pin('the mark keeps its keys with no group', ['group' => '', 'name' => ''], $reply['marks']['tools'] ?? null);
    pin('the sub key too', ['group' => '', 'name' => ''], $reply['marks']['tools/tools_backup'] ?? null);
    check('the snooze is keyed by the session admin', isset(KeyValue::$rows['snooze:5:backups_open']));

    notice_service();
    $other = json_decode(notice_drive('notice_show', ['id' => 'backups_open', 'CSRFToken' => 'good'], 'POST', 9), true);
    pin('another admin cannot show it', 0, $other['ok'] ?? null);
    check('...and the row stays hidden', isset(KeyValue::$rows['snooze:5:backups_open']));

    notice_service();
    $shown = json_decode(notice_drive('notice_show', ['id' => 'backups_open', 'CSRFToken' => 'good']), true);
    pin('show works', 1, $shown['ok'] ?? null);
    pin('the mark is live again', ['group' => 'action', 'name' => '1 notice, 1 needs action'], $shown['marks']['tools'] ?? null);
    check('the snooze is gone', !isset(KeyValue::$rows['snooze:5:backups_open']));

    harness_section('rows that are not there');
    notice_reset();
    $missing = json_decode(notice_drive('notice_hide', ['id' => 'nope', 'CSRFToken' => 'good']), true);
    pin('an unknown id is not hidden', 0, $missing['ok'] ?? null);
    check('...the panel still comes back', is_string($missing['panel'] ?? null));
    pin('an array id is not an id', 0, json_decode(notice_drive('notice_hide', ['id' => ['backups_open'], 'CSRFToken' => 'good']), true)['ok'] ?? null);

    harness_section('moderators');
    notice_reset();
    $mod = json_decode(notice_drive('notice_hide', ['id' => 'backups_open', 'CSRFToken' => 'good'], 'POST', 7, true), true);
    pin('a moderator cannot hide an admin-only row', 0, $mod['ok'] ?? null);
    pin('...no snooze is written', false, isset(KeyValue::$rows['snooze:7:backups_open']));
    check('...the panel shows only their row', str_contains((string) $mod['panel'], 'for_mods') && !str_contains((string) $mod['panel'], 'backups_open'));

    notice_service();
    $mine = json_decode(notice_drive('notice_hide', ['id' => 'for_mods', 'CSRFToken' => 'good'], 'POST', 7, true), true);
    pin('a moderator can hide their own row', 1, $mine['ok'] ?? null);

    exit(harness_result());
}
