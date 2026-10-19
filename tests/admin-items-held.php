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
 * The admin Listings screen and editor for a listing that waits for payment: its badge, the
 * "Waiting for payment" filter, "Publish without payment", and the notice when an old
 * Activate link is followed.
 * Usage: php tests/admin-items-held.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/api-admin-kit.php';

use mindstellar\listing\ListingHoldJobs;

$admin = api_admin_boot('osc_admin_items_held');
$p     = DB_TABLE_PREFIX;

seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cat = seed_category($admin, 'Cars', null, 'en_US', 1, 30);
$tom = seed_user($admin, 'tom', 'tom@example.test');
$adminId = api_admin_seed_admin($admin, 'boss');
foreach (['enabled_users' => '1', 'language' => 'en_US', 'currency' => 'USD', 'moderate_items' => '-1'] as $k => $v) {
    Preference::getInstance()->set($k, $v);
}
scratchdb_forget_cache();
osc_reset_preferences();

if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}
foreach (['_e' => 'echo $key;', '_n' => 'return $key;'] as $fn => $body) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '($key, $plural = "", $n = 0) { ' . $body . ' }');
    }
}
if (!function_exists('osc_admin_base_url')) {
    function osc_admin_base_url($index = false)
    {
        return 'https://example.test/oc-admin/' . ($index ? 'index.php' : '');
    }
}
if (!function_exists('osc_logged_admin_id')) {
    function osc_logged_admin_id()
    {
        return $GLOBALS['adminId'];
    }
}
if (!function_exists('osc_is_moderator')) {
    function osc_is_moderator()
    {
        return false;
    }
}
foreach (array('error', 'ok', 'warning', 'info') as $kind) {
    if (!function_exists('osc_add_flash_' . $kind . '_message')) {
        eval('function osc_add_flash_' . $kind . '_message($msg, $section = "pubMessages") {'
             . '$GLOBALS["flashes"][] = "' . $kind . ':" . $msg; }');
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hValidate.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hItems.php';

/** The base controller, stubbed: redirects are recorded, views are not drawn. */
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
    }
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminItems.php';

/** One request to the screen. */
function drive(string $action, array $query = array(), bool $token = true): array
{
    $csrf  = new \mindstellar\security\Csrf();
    $_GET  = $query + array('page' => 'items', 'action' => $action);
    $_POST = $token ? array('CSRFName' => $csrf->getCsrfTokenName(), 'CSRFToken' => $csrf->getCsrfTokenValue()) : array();
    $_REQUEST = $_GET + $_POST;
    Params::init();
    $GLOBALS['flashes']   = array();
    $GLOBALS['redirects'] = array();
    $threw = null;
    try {
        (new CAdminItems())->doModel();
    } catch (\Throwable $e) {
        $threw = $e;
    }

    return array('flashes' => $GLOBALS['flashes'], 'threw' => $threw, 'aData' => __get('aData'));
}

$GLOBALS['adminId'] = $adminId;
$live    = seed_item($admin, $cat, $tom, 'Live one');
$blocked = seed_item($admin, $cat, $tom, 'Blocked one');
$held    = seed_item($admin, $cat, $tom, 'Held one');
$admin->query("UPDATE {$p}t_item SET b_enabled = 0 WHERE pk_i_id = {$blocked}");
$admin->query("UPDATE {$p}t_item SET b_enabled = 0, b_active = 0, dt_first_pub_date = NULL, dt_expiration = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE pk_i_id = {$held}");
ListingHoldJobs::queue($held, 7);

$posted = 0;
osc_add_hook('posted_item', static function () use (&$posted): void {
    $posted++;
});
$row   = static fn (int $id): array => $admin->query("SELECT b_enabled, b_active, dt_first_pub_date IS NULL AS blank FROM {$p}t_item WHERE pk_i_id = {$id}")->fetch_assoc();
$state = static fn (int $id): string => implode('/', array_map('strval', $row($id)));
$cells = static function (array $aData): array {
    $out = array();
    foreach ($aData['aRows'] ?? array() as $r) {
        $out[] = $r;
    }

    return $out;
};
$ids = static function (array $aData) use ($cells): array {
    $found = array();
    foreach ($cells($aData) as $r) {
        if (preg_match('/value="(\d+)"/', (string) ($r['bulkactions'] ?? ''), $m)) {
            $found[] = (int) $m[1];
        }
    }
    sort($found);

    return $found;
};
$rowFor = static function (array $aData, int $id) use ($cells): string {
    foreach ($cells($aData) as $r) {
        if (str_contains((string) ($r['bulkactions'] ?? ''), 'value="' . $id . '"')) {
            return implode(' ', array_map('strval', $r));
        }
    }

    return '';
};

harness_section('the list');
$all = drive('')['aData'];
$heldRow    = $rowFor($all, $held);
$blockedRow = $rowFor($all, $blocked);
pin('the held row reads Waiting for payment', true, str_contains($heldRow, 'Waiting for payment') && !str_contains($heldRow, 'Blocked'));
pin('the blocked row still reads Blocked', true, str_contains($blockedRow, 'Blocked') && !str_contains($blockedRow, 'Waiting for payment'));
pin('the held row offers publish_free and no Activate or Unblock', [true, false, false], [
    str_contains($heldRow, 'action=publish_free'),
    str_contains($heldRow, 'value=ACTIVE'),
    str_contains($heldRow, 'value=ENABLE'),
]);
pin('the filter returns only the held listing', array($held), $ids(drive('', array('status' => 'unpaid'))['aData']));
pin('the raw Block filter is untouched: both rows', array($blocked, $held), $ids(drive('', array('b_enabled' => '0'))['aData']));

harness_section('Publish without payment');
$page = drive('publish_free', array('id' => $held));
$log  = $admin->query("SELECT s_who FROM {$p}t_log WHERE s_section = 'item' AND s_action = 'publish' AND fk_i_id = {$held}")->fetch_assoc();
pin('the listing is live, one posted_item, logged as the admin', ['1/1/0', 1, 'admin'], array($state($held), $posted, $log['s_who'] ?? null));
check('the flash says it is published and the seller was not charged', in_array('ok:The listing is published; the seller was not charged', $page['flashes'], true));
pin('no ledger row', 0, (int) $admin->query("SELECT COUNT(*) FROM {$p}t_billing_ledger")->fetch_row()[0]);
$exp = strtotime((string) $admin->query("SELECT dt_expiration FROM {$p}t_item WHERE pk_i_id = {$held}")->fetch_row()[0]);
check('it runs about 30 days from now', abs($exp - (time() + 30 * 86400)) < 3600);

$page = drive('publish_free', array('id' => $held));
check('again: not waiting for payment, nothing changes', in_array('error:The listing is not waiting for payment', $page['flashes'], true) && $posted === 1);
$page = drive('publish_free', array('id' => $live));
check('a live listing: the same notice', in_array('error:The listing is not waiting for payment', $page['flashes'], true) && $posted === 1);

$held2 = seed_item($admin, $cat, $tom, 'Held two');
$admin->query("UPDATE {$p}t_item SET b_enabled = 0, b_active = 0, dt_first_pub_date = NULL WHERE pk_i_id = {$held2}");
ListingHoldJobs::queue($held2, 7);
$src = harness_method_source(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminItems.php', 'publishFree');
$csrf = strpos($src, 'osc_csrf_check()');
$gone = strpos($src, '->publish(');
check('osc_csrf_check() runs before the listing is touched', $csrf !== false && $gone !== false && $csrf < $gone);
check('and is the first statement', (bool) preg_match('/\{\s*osc_csrf_check\(\);/', $src));

harness_section('an old Activate link');
$page = drive('status', array('id' => $held2, 'value' => 'ACTIVE'));
check('says it is waiting for payment', (bool) array_filter($page['flashes'], static fn ($f) => str_contains($f, 'waiting for payment')), json_encode($page['flashes']) . ($page['threw'] ? $page['threw']->getMessage() : ''));
pin('the row stays held', '0/0/1', $state($held2));

harness_section('the editor');
drive('item_edit', array('id' => $held2));
$pills = __get('itemStatus');
$moves = __get('itemActions');
pin('first pill is unpaid', 'unpaid', $pills[0][0] ?? null);
pin('one routine move: Publish without payment', array('Publish without payment'), array_column($moves['routine'], 'label'));
pin('the only danger move is spam', array('Mark as spam'), array_column($moves['danger'], 'label'));
drive('item_edit', array('id' => $live));
$moves = __get('itemActions');
pin('a live listing keeps Deactivate, Mark as premium, Block listing, Mark as spam', array('Deactivate', 'Mark as premium', 'Block listing', 'Mark as spam'), array_merge(array_column($moves['routine'], 'label'), array_column($moves['danger'], 'label')));
pin('and its first pill is active', 'active', __get('itemStatus')[0][0] ?? null);

exit(harness_result());
