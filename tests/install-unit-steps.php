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
 * Pins the installer's one-unit-per-request endpoint: the account request only creates the admin,
 * starter categories and places wait for the admin row, a failed or lost unit leaves a flag for the
 * done step, unknown actions do nothing, and the session guard answers fatal.
 *
 * DB-backed (the admin row).  Usage: php tests/install-unit-steps.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$admin = scratchdb_session('osc_install_unit_steps');

define('CONTENT_PATH', sys_get_temp_dir() . '/install-unit-steps-' . getmypid() . '/');
mkdir(CONTENT_PATH . 'themes', 0777, true);
register_shutdown_function(static function (): void {
    @rmdir(CONTENT_PATH . 'themes/storefront');
    @rmdir(CONTENT_PATH . 'themes');
    @rmdir(CONTENT_PATH);
});

if (!function_exists('__')) {
    function __($text)
    {
        return $text;
    }
}

require_once LIB_PATH . 'osclass/install-functions.php';

// The session starts before any output, so its cookie can still be set.
$session = Session::getInstance();
$choose  = static function (string $type, bool $sample = false, array $plugins = array()) use ($session): void {
    $_SESSION = array();
    $session->_set('install_site_type', $type);
    $session->_set('install_sample_data', $sample ? 1 : 0);
    $session->_set('install_shop_plugins', $plugins);
};
$count = static fn (string $table): int => (int) $admin->query('SELECT COUNT(*) FROM ' . DB_TABLE_PREFIX . $table)->fetch_row()[0];

harness_section('the endpoint');
$loc   = (string) file_get_contents(LIB_PATH . 'osclass/install-location.php');
$guard = strpos($loc, 'install_ajax_allowed()');
check('the guard runs before any unit', $guard !== false && $guard < (int) strpos($loc, 'install_run_unit('));
check('the guard answer is fatal', preg_match("/install_ajax_allowed\\(\\).{0,120}'fatal'\\s*=>\\s*true/s", $loc) === 1);
check('the endpoint imports no places itself', strpos($loc, 'osc_install_json_locations') === false);
check('the endpoint seeds nothing itself', strpos($loc, 'install_seed_site') === false && strpos($loc, 'install_seed_starter') === false);
$fn      = new ReflectionFunction('install_account_unit');
$account = implode('', array_slice(file($fn->getFileName()), $fn->getStartLine() - 1, $fn->getEndLine() - $fn->getStartLine() + 1));
check('the account unit imports no places', strpos($account, 'osc_install_json_locations') === false);
check('the account unit checks the site details before basic_info()', (int) strpos($account, 'SiteDetails::problem(') > 0
    && strpos($account, 'SiteDetails::problem(') < (int) strpos($account, 'basic_info()'));
check('the account unit seeds no content', strpos($account, 'install_seed_site') === false && strpos($account, 'install_seed_starter') === false);

harness_section('an unknown action');
$choose('classifieds');
$before = $_SESSION;
$res    = install_run_unit('drop_tables');
check('is refused with a message', $res['status'] === false && $res['message'] !== '');
check('and changes nothing', $_SESSION === $before && $count('t_admin') === 0);

harness_section('before the admin account exists');
// With these inputs each real unit would pass without network, so only the guard can refuse them.
Params::setParam('location-json', '');
mkdir(CONTENT_PATH . 'themes/storefront');
check('no admin row yet', install_admin_exists() === false);
$guarding = '';
register_shutdown_function(static function () use (&$guarding): void {
    if ($guarding !== '') {
        echo "FAIL  {$guarding} ran its real unit before the admin account existed and crashed\n";
    }
});
foreach (array('locations', 'storefront', 'starter') as $action) {
    $guarding = $action;
    $res      = install_run_unit($action);
    $guarding = '';
    check($action . ' is refused', $res['status'] === false && $res['message'] === 'Create the admin account first.');
}
check('no flag was set', $_SESSION === $before);
check('no category was written', $count('t_category') === 0);

harness_section('the account unit');
Params::setParam('s_name', 'bad name!');
Params::setParam('email', 'owner@example.test');
Params::setParam('webtitle', 'Shop');
$res = install_run_unit('account');
check('a bad field answers the field', $res['status'] === false && $res['field'] === 'admin_user' && $res['error'] !== '');
check('and saves no admin', $count('t_admin') === 0);
$admin->query("INSERT INTO " . DB_TABLE_PREFIX . "t_admin (pk_i_id, s_name, s_username, s_password, s_email) VALUES (1, 'Administrator', 'admin', 'x', 'owner@example.test')");

harness_section('once the admin account exists');
check('the guard sees the admin row', install_admin_exists() === true);
check('a unit runs', install_run_unit('locations')['status'] === true);
rmdir(CONTENT_PATH . 'themes/storefront');

harness_section('places');
Params::setParam('skip-location-input', 'skip');
$res = install_run_unit('locations');
check('skip imports nothing and passes', $res['status'] === true && $session->_get('install_location_failed') === '');
Params::setParam('skip-location-input', '');
Params::setParam('location-json', '');
check('no country passes', install_run_unit('locations')['status'] === true);
$asked = array();
$res   = install_locations_unit('XX-No-Such', static function (string $code) use (&$asked): bool {
    $asked[] = $code;

    return false;
});
check('a failed import answers false with a message', $res['status'] === false && $res['message'] !== '');
check('and keeps the code for the done step', $session->_get('install_location_failed') === 'XX-No-Such' && $asked === array('XX-No-Such'));
$res = install_locations_unit('XX', static function (): bool {
    throw new RuntimeException('down');
});
check('an import that throws is a failure too', $res['status'] === false && $session->_get('install_location_failed') === 'XX');
check('an import that works clears the flag', install_locations_unit('MT', static fn (): bool => true)['status'] === true
    && $session->_get('install_location_failed') === '');
$asked = array();
$res   = install_locations_unit('../../etc/passwd', static function (string $code) use (&$asked): bool {
    $asked[] = $code;

    return true;
});
check('a path never reaches the download', $res['status'] === false && $asked === array() && $session->_get('install_location_failed') === 'lost');
Params::setParam('location-json', '../x.json');
check('nor through the endpoint', install_run_unit('locations')['status'] === false);

harness_section('starter categories and Storefront');
$res = install_starter_unit(static fn (string $type): bool => false);
check('failed starter categories answer a message', $res['status'] === false && $res['message'] !== '' && $session->_get('install_starter_failed') === 1);
check('a retry that works clears it', install_starter_unit(static fn (string $type): bool => true)['status'] === true && $session->_get('install_starter_failed') === 0);
$type = '';
install_starter_unit(static function (string $t) use (&$type): bool {
    $type = $t;

    return true;
});
check('the starter unit seeds the chosen site type', $type === 'classifieds');
$res = install_storefront_unit(static fn (): bool => false);
check('a failed Storefront download answers a message', $res['status'] === false && $res['message'] !== '' && $session->_get('install_storefront_failed') === 1);
install_storefront_unit(static function (): bool {
    throw new RuntimeException('offline');
});
check('a download that throws is a failure', $session->_get('install_storefront_failed') === 1);
check('a download that works clears it', install_storefront_unit(static fn (): bool => true)['status'] === true && $session->_get('install_storefront_failed') === 0);

harness_section('example content');
$choose('classifieds');
$res = install_run_unit('sample_batch');
check('a sample batch that was not chosen answers false with a message', $res['status'] === false && $res['message'] !== '');
$res = install_run_unit('sample_photos');
check('so do example photos', $res['status'] === false && $res['message'] !== '');

harness_section('requests the browser got no answer to');
$choose('classifieds');
install_record_lost_steps(array('locations', 'starter', 'storefront', 'plugin:evil', 'theme:aisle'));
check('lost places are flagged without trusting a posted code', $session->_get('install_location_failed') === 'lost');
check('lost starter categories are flagged', $session->_get('install_starter_failed') === 1);
check('a lost Storefront download is flagged', $session->_get('install_storefront_failed') === 1);
check('a plugin that was not chosen sets nothing', (array) $session->_get('install_shop_failed') === array());
$choose('shop', false, array('shipping'));
install_record_lost_steps(array('plugin:evil'));
check('not on a shop install either', (array) $session->_get('install_shop_failed') === array());
mkdir(CONTENT_PATH . 'themes/storefront');
$session->_set('install_storefront_failed', 0);
install_record_lost_steps(array('storefront'));
check('Storefront on disk is not reported missing', !$session->_get('install_storefront_failed'));

exit(harness_result());
