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
 * The admin Messages screen against a real database: the Held and Reported tabs, Release,
 * Remove, Close and Clear, the demo refusal, and both templates drawn.
 *
 * Usage:  php tests/models/messaging-admin-screen.php      (standalone, own scratch database)
 *         php tests/run-models.php messaging-admin-screen  (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

use mindstellar\auth\Actor;
use mindstellar\container\Container;
use mindstellar\event\DispatcherInterface;
use mindstellar\messaging\event\MessageSending;
use mindstellar\messaging\MessagingService;

$admin = api_admin_boot('osc_models_messaging_admin_screen');
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';
$p = DB_TABLE_PREFIX;

$set = static function (string $key, string $value): void {
    Preference::getInstance()->replace($key, $value);
    scratchdb_forget_cache();
    osc_reset_preferences();
};
$col = static fn (string $sql) => $admin->query($sql)->fetch_row()[0];

seed_locale($admin);
$buyer  = seed_user($admin, 'bea', 'bea@example.test');
$seller = seed_user($admin, 'sam', 'sam@example.test');
$cat    = seed_category($admin);
$bike   = seed_item($admin, $cat, $seller, 'Bike');
$lamp   = seed_item($admin, $cat, $seller, 'Lamp');
$desk   = seed_item($admin, $cat, $seller, 'Desk');
$set('enabled_users', '1');
$set('enabled_messaging', '1');
$set('throttle_message_send', '0');
$set('throttle_message_start', '0');

if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}
foreach (['_e' => 'echo $key;', '_n' => 'return $key;'] as $fn => $body) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '($key, $plural = "", $n = 0) { ' . $body . ' }');
    }
}
foreach ([
    'osc_admin_base_url'    => static fn ($index = false) => 'https://example.test/oc-admin/' . ($index ? 'index.php' : ''),
    'osc_logged_admin_id'   => static fn () => 1,
    'osc_is_moderator'      => static fn () => false,
    'osc_admin_date_format' => static fn ($dateOnly = false) => 'Y-m-d',
] as $name => $impl) {
    if (!function_exists($name)) {
        $GLOBALS['stubs'][$name] = $impl;
        eval('function ' . $name . '(...$a) { return ($GLOBALS["stubs"]["' . $name . '"])(...$a); }');
    }
}
foreach (['error', 'ok', 'warning', 'info'] as $kind) {
    if (!function_exists('osc_add_flash_' . $kind . '_message')) {
        eval('function osc_add_flash_' . $kind . '_message($msg, $section = "pubMessages") { $GLOBALS["flashes"][] = "' . $kind . ':" . $msg; }');
    }
}
if (!function_exists('osc_current_admin_theme_path')) {
    function osc_current_admin_theme_path($file)
    {
        if (strpos($file, 'parts/') === 0) {
            return;
        }
        require ABS_PATH . 'oc-admin/themes/modern/' . $file;
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';

/** The base controller, stubbed: redirects are recorded and views are drawn into a buffer. */
class AdminSecBaseModel
{
    protected $action;

    protected $page;

    public function __construct()
    {
        $this->action = Params::getParam('action');
        $this->page   = Params::getParam('page');
    }

    public function doModel()
    {
    }

    public function redirectTo($url, $code = null)
    {
        $GLOBALS['redirects'][] = $url;
    }

    public function _exportVariableToView($key, $value)
    {
        View::getInstance()->_exportVariableToView($key, $value);
    }

    public function doView($view)
    {
        ob_start();
        try {
            osc_current_admin_theme_path($view);
        } finally {
            $GLOBALS['html'] = ob_get_clean();
        }
    }
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminMessages.php';

/** One request to the screen. */
function drive(string $action, array $query = array(), bool $token = true): array
{
    $csrf  = new \mindstellar\security\Csrf();
    $_GET  = $query + array('page' => 'messages', 'action' => $action);
    $_POST = $token ? array('CSRFName' => $csrf->getCsrfTokenName(), 'CSRFToken' => $csrf->getCsrfTokenValue()) : array();
    $_REQUEST = $_GET + $_POST;
    Params::init();
    $GLOBALS['flashes']   = array();
    $GLOBALS['redirects'] = array();
    $GLOBALS['html']      = '';
    $threw = null;
    try {
        (new CAdminMessages())->doModel();
    } catch (\Throwable $e) {
        $threw = $e;
    }

    return array('flashes' => $GLOBALS['flashes'], 'redirects' => $GLOBALS['redirects'], 'html' => $GLOBALS['html'], 'threw' => $threw);
}

harness_reset_container();
$c      = Container::root();
$svc    = $c->get(MessagingService::class);
$events = $c->get(DispatcherInterface::class);
$hold   = static function (MessageSending $e): void {
    $e->hold('test');
};
$events->listen(MessageSending::class, $hold);
$held = $svc->start($bike, "Cheap <b>bike</b>?\nTomorrow", Actor::user($buyer, '10.0.0.1'));
$spare = $svc->start($lamp, 'Is the lamp new?', Actor::user($buyer, '10.0.0.1'));
$events->remove(MessageSending::class, $hold);
$sent = $svc->start($desk, 'Hello again', Actor::user($buyer, '10.0.0.1'));
$svc->report($sent->conversationId(), 'spam', Actor::user($seller, '10.0.0.2'));
$state = static fn (string $table, int $id): string => (string) $col("SELECT s_state FROM {$p}t_$table WHERE pk_i_id = $id");

harness_section('Held tab');
$r = drive('');
check('it draws', $r['threw'] === null && $r['html'] !== '', (string) $r['threw']);
check('it lists the message escaped', strpos($r['html'], 'Cheap &lt;b&gt;bike&lt;/b&gt;?') !== false && strpos($r['html'], '<b>bike</b>') === false);
check('it links the sender to the user editor', strpos($r['html'], 'page=users&amp;action=edit&amp;id=' . $buyer) !== false);
check('it links the listing to the admin editor', strpos($r['html'], 'page=items&amp;action=item_edit&amp;id=' . $bike) !== false);
check('it offers Ban sender with the address', strpos($r['html'], 'action=create_ban_rule&amp;email=bea%40example.test') !== false);
check('both tabs carry a count', strpos($r['html'], 'Held') !== false && strpos($r['html'], 'Reported') !== false);

harness_section('Reported tab and thread');
$r = drive('reported');
check('it lists the reported thread with its reason', strpos($r['html'], 'Spam') !== false && strpos($r['html'], 'sam') !== false);
$r = drive('thread', array('id' => $sent->conversationId()));
check('the thread shows every message and the Close button', strpos($r['html'], 'Hello again') !== false && strpos($r['html'], 'closeModal') !== false);
$r = drive('thread', array('id' => 999999));
check('a missing thread flashes and goes back', $r['redirects'] !== array() && $r['flashes'] !== array());

harness_section('changes');
$r = drive('release_post', array('id' => $held->messageId()));
pin('Release sends the message and opens the thread', array('sent', 'open'), array($state('message', $held->messageId()), $state('conversation', $held->conversationId())));
check('it flashes ok and goes back to Held', $r['flashes'] !== array() && strpos($r['flashes'][0], 'ok:') === 0 && $r['redirects'] === array('https://example.test/oc-admin/index.php?page=messages'));
$r = drive('release_post', array('id' => $held->messageId()));
check('releasing twice flashes an error', strpos($r['flashes'][0] ?? '', 'error:') === 0);
$r = drive('remove_post', array('id' => $spare->messageId()));
pin('Remove drops the message and closes a lone held thread', array('removed', 'closed'), array($state('message', $spare->messageId()), $state('conversation', $spare->conversationId())));
$r = drive('clear_post', array('id' => $sent->conversationId()));
pin('Clear opens the thread again', 'open', $state('conversation', $sent->conversationId()));
$svc->report($sent->conversationId(), 'scam', Actor::user($seller, '10.0.0.2'));
$r = drive('close_post', array('id' => $sent->conversationId()));
pin('Close closes the thread', 'closed', $state('conversation', $sent->conversationId()));
pin('and returns to the Reported tab', array('https://example.test/oc-admin/index.php?page=messages&action=reported'), $r['redirects']);
$r = drive('close_post', array('id' => $sent->conversationId()));
check('closing a thread that is not reported flashes an error', strpos($r['flashes'][0] ?? '', 'error:') === 0);

harness_section('empty tabs');
$r = drive('');
check('Held says so when empty', strpos($r['html'], 'No held messages') !== false);

harness_section('demo site');
define('DEMO', true);
$r = drive('remove_post', array('id' => $held->messageId()));
check('an admin gets the demo refusal', strpos($r['flashes'][0] ?? '', 'error:This action cannot be done because it is a demo site') === 0, implode('|', $r['flashes']));

exit(harness_result());
