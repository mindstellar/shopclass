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
 * Settings -> API driven the way a browser drives it: a key is made only after the password,
 * its secret is shown on the next page and never again, a revoked key stops verifying, a
 * moderator cannot grant admin scopes, a public key only reads, rotating keeps the old key
 * working and is refused for another admin's key, and every action that changes something
 * refuses a request without a valid CSRF token.
 *
 * DB-backed.  Usage:  php tests/admin-api-keys.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$admin = scratchdb_session('osc_admin_api_keys');

// The session lives in memory: started before anything is printed, never sent anywhere.
$_SESSION = array();
Session::newInstance()->_drop('apiKeyIssued');

if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
foreach (array('_m' => 'return $key;', '_e' => 'echo $key;') as $fn => $body) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '($key, $domain = "core") { ' . $body . ' }');
    }
}
if (!function_exists('osc_admin_base_url')) {
    function osc_admin_base_url($index = false)
    {
        return 'https://example.test/oc-admin/index.php';
    }
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($withIndex = false)
    {
        return 'https://example.test/';
    }
}
if (!function_exists('_n')) {
    function _n($single, $plural, $n, $domain = 'core')
    {
        return $n === 1 ? $single : $plural;
    }
}
if (!function_exists('osc_current_admin_locale')) {
    function osc_current_admin_locale()
    {
        return 'en_US';
    }
}
if (!function_exists('osc_logged_admin_id')) {
    function osc_logged_admin_id()
    {
        return (int) $GLOBALS['adminId'];
    }
}
// A refused token stops the request, as the real check does by exiting.
$GLOBALS['csrfRefuse'] = false;
$GLOBALS['csrfChecks'] = array();
if (!function_exists('osc_csrf_check')) {
    function osc_csrf_check($die = true)
    {
        $GLOBALS['csrfChecks'][] = Params::getParam('action');
        if ($GLOBALS['csrfRefuse']) {
            throw new RuntimeException('csrf refused');
        }

        return true;
    }
}
foreach (array('error', 'ok', 'warning', 'info') as $kind) {
    if (!function_exists('osc_add_flash_' . $kind . '_message')) {
        eval('function osc_add_flash_' . $kind . '_message($msg, $section = "pubMessages") {'
             . '$GLOBALS["flashes"][] = "' . $kind . ':" . $msg; }');
    }
}

require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
if (!function_exists('osc_current_admin_theme_path')) {
    function osc_current_admin_theme_path($file = '')
    {
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hValidate.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hApi.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hHttpCache.php';
// The security helpers, less the real CSRF check: the stand-in above records and refuses instead.
$security = tempnam(sys_get_temp_dir(), 'oschsec_') . '.php';
file_put_contents($security, str_replace('function osc_csrf_check(', 'function osc_csrf_check_unused(', (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php')));
register_shutdown_function(static fn () => @unlink($security));
require_once $security;

/** The base controller, stubbed: redirects are recorded, and the view is drawn into the output. */
class AdminSecBaseModel
{
    protected $action;

    protected $page;

    public function __construct()
    {
        $this->action = Params::getParam('action');
        $this->page   = Params::getParam('page');
    }

    public function isModerator()
    {
        return (int) $GLOBALS['adminId'] === (int) ($GLOBALS['moderatorId'] ?? 0);
    }

    public function redirectTo($url, $code = null)
    {
        $GLOBALS['redirects'][] = $url;
    }

    public function _exportVariableToView($key, $value)
    {
        View::newInstance()->_exportVariableToView($key, $value);
    }

    public function doView($view)
    {
        $GLOBALS['views'][] = $view;
        include ABS_PATH . 'oc-admin/themes/modern/' . $view;
    }

    protected function refuseOnDemo($redirectUrl = null)
    {
        return false;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsApi.php';

use mindstellar\api\auth\ApiKeys;
use mindstellar\api\auth\CredentialKind;
use mindstellar\api\auth\KeyOwner;
use mindstellar\api\auth\Scopes;
use mindstellar\model\ApiCredential;
use mindstellar\utility\SystemClock;

/** One request to the screen. */
function drive(string $action, array $fields = array()): array
{
    $_GET  = array('page' => 'settings', 'action' => $action);
    $_POST = $fields;
    Params::init();
    $GLOBALS['flashes']    = array();
    $GLOBALS['redirects']  = array();
    $GLOBALS['csrfChecks'] = array();
    $GLOBALS['views']      = array();

    ob_start();
    $refused = false;
    try {
        (new CAdminSettingsApi())->doModel();
    } catch (RuntimeException $e) {
        $refused = $e->getMessage() === 'csrf refused';
    }

    return array(
        'flashes'   => $GLOBALS['flashes'],
        'redirects' => $GLOBALS['redirects'],
        'csrf'      => $GLOBALS['csrfChecks'],
        'refused'   => $refused,
        'drawn'     => (string) ob_get_clean(),
    );
}

$table = DB_TABLE_PREFIX . 't_api_credential';
$count = static fn (): int => (int) $admin->query("SELECT COUNT(*) FROM $table")->fetch_row()[0];
$keys  = static fn (): ApiKeys => new ApiKeys(new ApiCredential(), new Scopes(), new SystemClock());
/** The key a page shows once, or '' when it shows none. */
$shown = static fn (string $html): string => preg_match('~id="api-key-token">([^<]+)<~', $html, $m) === 1 ? html_entity_decode($m[1]) : '';

$hash = osc_hash_password('right-password');
$fullId = seed_exec($admin, 'INSERT INTO ' . DB_TABLE_PREFIX . "t_admin (s_name, s_username, s_password, s_email, b_moderator) VALUES ('Full', 'full', ?, 'full@x.test', 0)", 's', [$hash]);
$modId  = seed_exec($admin, 'INSERT INTO ' . DB_TABLE_PREFIX . "t_admin (s_name, s_username, s_password, s_email, b_moderator) VALUES ('Mod', 'mod', ?, 'mod@x.test', 1)", 's', [$hash]);
$GLOBALS['moderatorId'] = $modId;
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
$GLOBALS['adminId'] = $fullId;

harness_section('the screen');

$page = drive('api');
check('draws the settings form', strpos($page['drawn'], 'name="api_form"') !== false);
check('says there are no keys in one quiet line', strpos($page['drawn'], 'No keys yet.') !== false);
check('offers admin scopes to a full admin', strpos($page['drawn'], 'value="admin:users"') !== false);
pin('shows no key', '', $shown($page['drawn']));
check('the revoke dialog asks for the password', str_contains($page['drawn'], 'id="api-revoke-password"'));
check('so does the rotate dialog', str_contains($page['drawn'], 'id="api-rotate-password"'));

harness_section('making a key');

$wrong = drive('api_key_create', array('key_name' => 'Stock sync', 'key_kind' => 'key', 'key_scopes' => array('admin:listings'), 'password' => 'wrong'));
pin('a wrong password makes nothing', 0, $count());
check('...says why', in_array('error:That password is not right. Nothing was started.', $wrong['flashes'], true));
check('...and keeps what was typed', strpos($wrong['drawn'], 'value="Stock sync"') !== false);

$made = drive('api_key_create', array('key_name' => 'Stock sync', 'key_kind' => 'key', 'key_scopes' => array('admin:listings', 'listings:read'), 'key_expires' => '', 'password' => 'right-password'));
pin('the right password makes one key', 1, $count());
pin('...and goes back to the screen', array(CAdminSettingsApi::url()), $made['redirects']);
pin('...checking the CSRF token first', array('api_key_create'), $made['csrf']);

$first  = drive('api');
$token  = $shown($first['drawn']);
$second = drive('api');
check('the next page shows the key', str_starts_with($token, 'sck_'));
pin('the page after that does not', '', $shown($second['drawn']));
check('the secret is nowhere in the table', !str_contains((string) $admin->query("SELECT CONCAT_WS('|', s_token_id, s_secret_hash, s_name, s_scopes) FROM $table")->fetch_row()[0], substr($token, 21)));
check('the list shows its name and prefix', strpos($second['drawn'], 'Stock sync') !== false && strpos($second['drawn'], substr($token, 0, 20)) !== false);
$credential = $keys()->verify($token);
pin('the key verifies with the scopes asked for', array('listings:read', 'admin:listings'), $credential?->scopes());

harness_section('revoking');

$id = (int) $admin->query("SELECT pk_i_id FROM $table WHERE s_name = 'Stock sync'")->fetch_row()[0];
$wrongRevoke = drive('api_key_revoke', array('id' => $id, 'password' => 'wrong'));
check('a wrong password revokes nothing', $keys()->verify($token) !== null);
check('...and says why', in_array('error:That password is not right. Nothing was started.', $wrongRevoke['flashes'], true));
$revoked = drive('api_key_revoke', array('id' => $id, 'password' => 'right-password'));
pin('revoke checks the CSRF token', array('api_key_revoke'), $revoked['csrf']);
pin('a revoked key no longer verifies (the API answers 401)', null, $keys()->verify($token));
check('revoking it again says so', in_array('error:That key is already revoked.', drive('api_key_revoke', array('id' => $id, 'password' => 'right-password'))['flashes'], true));
check('a refresh token id is not a key to revoke here', in_array('error:That key is not in the list any more.', drive('api_key_revoke', array('id' => 999, 'password' => 'right-password'))['flashes'], true));

harness_section('a moderator');

// Settings is closed to moderators; a plugin opening it through moderator_access must not open this screen.
$GLOBALS['adminId'] = $modId;
$before = $count();
foreach (array('api', 'api_key_create', 'api_post') as $action) {
    $run = drive($action, array('key_name' => 'Too much', 'key_kind' => 'key', 'key_scopes' => array('admin:listings'), 'password' => 'right-password', 'api_rate_limit_default' => '5'));
    check('is turned away from ' . $action, $run['redirects'] === array('https://example.test/oc-admin/index.php')
        && $run['drawn'] === '' && in_array("error:You don't have enough permissions", $run['flashes'], true));
}
pin('...and makes no key', $before, $count());
$GLOBALS['adminId'] = $fullId;

$manager = \mindstellar\api\ApiServices::site()->keyService();
$modOwner = KeyOwner::admin($modId, true);
check("a moderator's key is not offered admin:users", !array_key_exists('admin:users', $manager->grantable(CredentialKind::KEY, $modOwner)));
$refusal = '';
try {
    $manager->create($modOwner, 'Too much', CredentialKind::KEY, array('admin:listings', 'admin:users'));
} catch (InvalidArgumentException $e) {
    $refusal = $e->getMessage();
}
pin('a moderator cannot hold an admin scope, and is told which', 'This key cannot hold: admin:users', $refusal);
$modToken = $manager->create($modOwner, 'Moderation bot', CredentialKind::KEY, array('admin:listings'))->token();
pin('a moderator key with the moderator scopes is made', array('admin:listings'), $keys()->verify($modToken)?->scopes());

harness_section('the key waits five minutes at most');

$session = Session::newInstance();
$session->_set(CAdminSettingsApi::ISSUED, array('token' => 'sck_stale', 'name' => 'Old', 'at' => time() - CAdminSettingsApi::ISSUED_TTL - 1));
pin('a key left unshown for over five minutes is not shown', '', $shown(drive('api')['drawn']));
pin('...and is gone from the session', '', $session->_get(CAdminSettingsApi::ISSUED));
$session->_set(CAdminSettingsApi::ISSUED, array('token' => 'sck_fresh', 'name' => 'New', 'at' => time() - 60));
pin('a key a minute old is shown', 'sck_fresh', $shown(drive('api')['drawn']));

harness_section('a public key');

drive('api_key_create', array('key_name' => 'Mobile app', 'key_kind' => 'public', 'key_scopes' => array('admin:users', 'listings:write'), 'password' => 'right-password'));
$public = $shown(drive('api')['drawn']);
check('starts with scp_', str_starts_with($public, 'scp_'));
pin('only gets the public read scope, whatever was ticked', array(Scopes::PUBLIC_READ), $keys()->verify($public)?->scopes());

try {
    \mindstellar\api\ApiServices::site()->keyService()->create(\mindstellar\api\auth\KeyOwner::admin(1), 'Odd', 'key', array('<b>x</b>'));
    $refusal = null;
} catch (\mindstellar\validation\RefusedException $e) {
    $refusal = $e->getMessage();
}
pin('...while the refusal itself is plain text', 'This key cannot hold: <b>x</b>', $refusal);

harness_section('rotating');

$own = drive('api_key_create', array('key_name' => 'CI', 'key_kind' => 'key', 'key_scopes' => array('admin:taxonomy'), 'key_expires' => '2999-01-01', 'password' => 'right-password'));
$old   = $shown(drive('api')['drawn']);
$ownId = (int) $admin->query("SELECT pk_i_id FROM $table WHERE s_name = 'CI'")->fetch_row()[0];
$before = $count();
drive('api_key_rotate', array('id' => $ownId, 'name' => 'CI', 'password' => 'wrong'));
pin('a wrong password rotates nothing', $before, $count());
$rot = drive('api_key_rotate', array('id' => $ownId, 'name' => '<b>Posted</b>', 'password' => 'right-password'));
$rotPage = drive('api')['drawn'];
$new = $shown($rotPage);
check('the banner names the stored key, not what was posted', str_contains($rotPage, 'Your new key &quot;CI&quot;') && !str_contains($rotPage, 'Posted'));
pin('rotate checks the CSRF token', array('api_key_rotate'), $rot['csrf']);
check('rotating shows a new key once', $new !== '' && $new !== $old);
pin('...with the same scopes', array('admin:taxonomy'), $keys()->verify($new)?->scopes());
check('...while the old one still works until revoked', $keys()->verify($old) !== null);
pin('...and the same expiry', strtotime('2999-01-01 23:59:59'), (new ApiCredential())->findByTokenId(substr($new, 4, 16))?->expiresAt());

$modKey = (int) $admin->query("SELECT pk_i_id FROM $table WHERE s_name = 'Moderation bot'")->fetch_row()[0];
$before = $count();
$other  = drive('api_key_rotate', array('id' => $modKey, 'password' => 'right-password'));
pin("another admin's key is not rotated", $before, $count());
check('...and the reason is shown', str_starts_with((string) ($other['flashes'][0] ?? ''), 'error:You can only rotate your own keys'));

harness_section('CSRF');

$GLOBALS['csrfRefuse'] = true;
$before = $count();
$stored = $admin->query("SELECT s_value FROM " . DB_TABLE_PREFIX . "t_preference WHERE s_section = 'api' AND s_name = 'api_rate_limit_default'")->fetch_row();
foreach (array(
    'api_key_create' => array('key_name' => 'X', 'key_kind' => 'key', 'key_scopes' => array('admin:users'), 'password' => 'right-password'),
    'api_key_rotate' => array('id' => $ownId, 'password' => 'right-password'),
    'api_key_revoke' => array('id' => $ownId, 'password' => 'right-password'),
    'api_post'       => array('api_rate_limit_default' => '5'),
) as $action => $fields) {
    $run = drive($action, $fields);
    check($action . ' stops on a bad token', $run['refused'] && $run['redirects'] === array());
}
$GLOBALS['csrfRefuse'] = false;
pin('...and no key was made, rotated or revoked', $before, $count());
check('...the CI key still works', $keys()->verify($old) !== null);
pin('...and no setting changed', $stored, $admin->query("SELECT s_value FROM " . DB_TABLE_PREFIX . "t_preference WHERE s_section = 'api' AND s_name = 'api_rate_limit_default'")->fetch_row());

harness_section('settings');

$bad = drive('api_post', array('api_enabled' => '1', 'api_cors_origins' => "https://app.example.com/\nnot an origin", 'api_rate_limit_default' => '120', 'api_rate_limit_anon' => '60', 'api_rate_limit_write' => '30', 'api_cache_max_age' => '60'));
check('a line that is not an origin is refused', $bad['redirects'] === array() && str_contains(implode(' ', $bad['flashes']), 'not an origin is not an origin'));
$ok = drive('api_post', array('api_enabled' => '1', 'api_cors_origins' => "https://app.example.com/\n\n*\nhttps://app.example.com", 'api_rate_limit_default' => '0', 'api_rate_limit_anon' => '60', 'api_rate_limit_write' => '30', 'api_cache_max_age' => '60', 'api_hide_phone' => '1'));
pin('a good save goes back to the screen', array(CAdminSettingsApi::url()), $ok['redirects']);
$pref = static fn (string $name): ?string => $admin->query("SELECT s_value FROM " . DB_TABLE_PREFIX . "t_preference WHERE s_section = 'api' AND s_name = '$name'")->fetch_row()[0] ?? null;
pin('origins are stored one per line, tidied', "https://app.example.com\n*", $pref('api_cors_origins'));
pin('a zero rate limit is floored at one', '1', $pref('api_rate_limit_default'));
pin('the phone switch is stored', '1', $pref('api_hide_phone'));
pin('a switch left unticked is stored off', '0', $pref('api_public_reads'));

harness_section('every setting on the screen is seeded');

$migration = require ABS_PATH . 'oc-includes/osclass/installer/migrations/0060_api_credential.php';
$seeded    = array();
foreach ((new ReflectionClassConstant($migration, 'PREFERENCES'))->getValue() as $name => $type) {
    $seeded[$name] = array(mindstellar\api\ApiSettings::seedValue($name), $type);
}
preg_match_all("~\\('api', '([a-z_]+)', '([^']*)', '([A-Z]+)'\\)~", (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/basic_data.sql'), $rows, PREG_SET_ORDER);
$installed = array();
foreach ($rows as $row) {
    $installed[$row[1]] = array($row[2], $row[3]);
}
pin('the migration and the installer seed the same rows', $seeded, $installed);
$defaults = array_keys(mindstellar\api\ApiSettings::DEFAULTS);
$names    = array_keys($seeded);
sort($defaults);
sort($names);
pin('the migration seeds every ApiSettings default', $defaults, $names);
foreach (mindstellar\settings\SettingsPageRegistry::instance()->fields(mindstellar\admin\form\ApiSettingsScreen::register()) as $name => $field) {
    $default = is_bool($field['default']) ? (string) (int) $field['default'] : (string) $field['default'];
    pin($name . ' is seeded with the form default', $default, $seeded[$name][0] ?? null);
    if (array_key_exists($name, mindstellar\api\ApiSettings::DEFAULTS)) {
        pin('...which is the ApiSettings default', mindstellar\api\ApiSettings::seedValue($name), $default);
    }
}

exit(harness_result());
