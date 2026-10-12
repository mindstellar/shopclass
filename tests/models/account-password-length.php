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
 * The shortest password: sign-up, an admin-added user, a change and a reset all refuse one
 * under AccountService::MIN_PASSWORD_LENGTH.
 * Usage:  php tests/models/account-password-length.php
 */

if (!function_exists('_m')) {
    function _m($text)
    {
        return $text;
    }
}

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';
require_once dirname(__DIR__, 2) . '/oc-includes/osclass/helpers/hSecurity.php';
require_once dirname(__DIR__, 2) . '/oc-includes/osclass/helpers/hValidate.php';

use mindstellar\auth\Actor;
use mindstellar\exception\InvalidException;
use mindstellar\user\AccountService;

$admin = scratchdb_session('osc_models_account_password_length');
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
$p    = DB_TABLE_PREFIX;
$uma  = seed_user($admin, 'uma', 'uma@example.test');
$hash = $admin->real_escape_string(osc_hash_password('right-password'));
$admin->query("UPDATE {$p}t_user SET s_password = '$hash', b_active = 1, b_enabled = 1 WHERE pk_i_id = $uma");
$row = static fn (): array => $admin->query("SELECT * FROM {$p}t_user WHERE pk_i_id = $uma")->fetch_assoc();

$refusedBy = static function (callable $run): array {
    try {
        $run();

        return ['accepted', ''];
    } catch (InvalidException $e) {
        return ['refused', $e->pointer()];
    }
};

harness_section('too short');
pin('the minimum is 5', 5, AccountService::MIN_PASSWORD_LENGTH);
pin('a change refuses 4 characters', ['refused', '/new_password'], $refusedBy(
    static fn () => (new AccountService())->changePassword($row(), 'right-password', 'abcd', 'abcd')
));
pin('a reset refuses 4 characters and stores nothing', [['refused', '/new_password'], true], (static function () use ($refusedBy, $uma, $row): array {
    $code = User::getInstance()->issuePassCode($uma, User::PASS_CODE_RESET);
    $res  = $refusedBy(static fn () => AccountService::resetPassword($uma, 'abcd', $code));

    return [$res, osc_verify_password('right-password', $row()['s_password'])];
})());
pin('a reset takes 5', true, (static function () use ($uma, $row): bool {
    $code = User::getInstance()->issuePassCode($uma, User::PASS_CODE_RESET);

    return AccountService::resetPassword($uma, 'abcde', $code) && osc_verify_password('abcde', $row()['s_password']);
})());

$form = ['s_name' => 'Newt', 's_email' => 'newt@example.test', 's_password' => 'abcd', 's_password2' => 'abcd'];
$err  = static function (array $f) use ($admin): string {
    try {
        (new AccountService())->register($f, Actor::admin(1, '127.0.0.1'));

        return '';
    } catch (InvalidException $e) {
        return $e->getMessage();
    } catch (Throwable $e) {
        return 'went on to create the user: ' . $e->getMessage();
    }
};
check('register refuses 4 characters, naming the minimum', str_contains($err($form), (string) AccountService::MIN_PASSWORD_LENGTH));
pin('and made no user', 0, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_user WHERE s_email = 'newt@example.test'")->fetch_row()[0]);
check('register takes 5: no length message', !str_contains($err(['s_name' => '', 's_password' => 'abcde', 's_password2' => 'abcde'] + $form), AccountService::tooShortMessage()));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
