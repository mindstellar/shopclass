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
 * A cancel post to the real billing controller, for tests/billing-order-cancel.php. Run on its
 * own, it posts with no CSRF token, which ends the process; it prints "went on" if it did not.
 *
 * Usage:  php tests/lib/billing-cancel-post.php <database> <user id> <order id>
 */

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    require_once __DIR__ . '/scratchdb.php';
    require_once __DIR__ . '/api-admin-kit.php';
    $admin = scratchdb_bootstrap($argv[1]);
    $admin->select_db($argv[1]);
    api_admin_env();
}

/** The secured base controller, stubbed: a redirect ends the request. */
class WebSecBaseModel
{
    protected $action;

    public function __construct()
    {
        $this->action = Params::getParam('action');
    }

    public function redirectTo($url, $code = null)
    {
        throw new CancelRedirect((string) $url);
    }

    public function do404()
    {
        throw new CancelRedirect('404');
    }

    protected function redirectShopperHome(): void
    {
    }

    public function _exportVariableToView($key, $value)
    {
        View::getInstance()->_exportVariableToView($key, $value);
    }
}

final class CancelRedirect extends RuntimeException
{
    public function __construct(public string $url)
    {
        parent::__construct($url);
    }
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/CWebBilling.php';

foreach (array('error', 'ok', 'warning', 'info') as $kind) {
    if (!function_exists('osc_add_flash_' . $kind . '_message')) {
        eval('function osc_add_flash_' . $kind . '_message($msg, $section = "pubMessages") {'
             . '$GLOBALS["flashes"][] = "' . $kind . ':" . $msg; }');
    }
}

/** POST a cancel for $orderId as $userId, with a good CSRF token unless $token is false. */
function cancel_post(int $userId, $orderId, bool $token = true): array
{
    osc_web_user_apply_identity(User::getInstance()->findByPrimaryKey($userId));
    $csrf  = new \mindstellar\security\Csrf();
    $_GET  = array('page' => 'billing', 'action' => 'cancel');
    $_POST = array('id' => (string) $orderId) + ($token ? array('CSRFName' => $csrf->getCsrfTokenName(), 'CSRFToken' => $csrf->getCsrfTokenValue()) : array());
    $_SERVER['REQUEST_METHOD'] = 'POST';
    Params::init();
    $GLOBALS['flashes'] = array();

    $redirect = null;
    try {
        (new CWebBilling())->doModel();
    } catch (CancelRedirect $r) {
        $redirect = $r->url;
    }

    return array('redirect' => $redirect, 'flashes' => implode(' | ', $GLOBALS['flashes']));
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    cancel_post((int) $argv[2], (int) $argv[3], false);
    echo "went on\n";
}
