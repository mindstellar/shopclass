<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The administrator account screen, saved through its declaration rather than through a
 * hand-written Params::getParam / validate / DAO block. It is a privilege boundary: every
 * row of t_admin is an account that can reach every screen in the admin, so the ways this
 * can go wrong are worse than a settings page's.
 *
 * What this is guarding, in the order it would go wrong:
 *
 *  - The current-password box is re-authentication, not decoration. It is the only thing
 *    standing between a walked-away-from session and a second administrator account, and
 *    it is a field that is deliberately never stored -- so the easiest way to lose it in a
 *    migration is to declare it and never check it, which looks identical on screen.
 *  - The account type is not the edited admin's to set on their own row. Declared for a
 *    self-edit, an administrator could demote themselves and a moderator could promote
 *    themselves, and the only sign would be in the column.
 *  - A blank new-password box means "leave it alone". Written through, it stores the hash
 *    of an empty string and every edit of a name silently changes a password.
 *  - The password that is stored has to be a hash of what was typed, and the plaintext has
 *    to survive as far as the welcome email, which is the one thing that still needs it.
 *  - The row a save writes is the one the controller named. A key taken from the body, or
 *    coerced with (int) from ' 12 ', is another administrator's account.
 *  - A key that is not a row must write nothing: the dangerous shape is not an error page
 *    but a silent insert, which turns the edit screen into one that creates administrators.
 *  - Both unique columns are refused before the statement runs, because a duplicate that
 *    reaches MySQL is an exception the admin cannot read after their form was thrown away.
 *  - A value longer than its column is stored truncated under a success message unless it
 *    is refused first: STRICT_TRANS_TABLES is off unless OSC_DB_STRICT_MODE says otherwise.
 *  - And what lands in the row has to be unchanged, which for the name means the purified
 *    form the edit path stores.
 *
 * The guards at the foot are the plan's "no regression to hand-rolled". Each says which
 * kind it is: a behavioural assertion drives the code and reads what it did, a source scan
 * only reads the file. Scans are the cheap extra, never the proof.
 *
 * DB-backed: the row is the point, so rows are read back with raw SQL on the admin
 * connection rather than through the code under test.
 *
 * Usage:  php tests/admin-account-form.php
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$admin = scratchdb_session('osc_admin_account_form');

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

if (!function_exists('__')) {
    function __($key, $domain = 'core')
    {
        return $key;
    }
}
if (!function_exists('_m')) {
    function _m($key)
    {
        return $key;
    }
}
if (!function_exists('_e')) {
    function _e($key, $domain = 'core')
    {
        echo $key;
    }
}
if (!function_exists('osc_admin_base_url')) {
    function osc_admin_base_url($index = false)
    {
        return 'https://example.test/oc-admin/index.php';
    }
}
if (!function_exists('osc_add_admin_submenu_page')) {
    function osc_add_admin_submenu_page($menu, $title, $url, $id, $capability = 'administrator')
    {
    }
}

/*
 * The password helpers, at a work factor a test can afford. Same algorithm and same
 * verification as hSecurity's -- that file cannot be loaded here because it declares
 * osc_csrf_check() unguarded, and this file has to record CSRF checks rather than run them.
 */
if (!defined('BCRYPT_COST')) {
    define('BCRYPT_COST', 4);
}
if (!function_exists('osc_hash_password')) {
    function osc_hash_password($password)
    {
        return password_hash($password, PASSWORD_BCRYPT, array('cost' => BCRYPT_COST));
    }
}
if (!function_exists('osc_verify_password')) {
    function osc_verify_password($password, $hash)
    {
        return password_verify($password, $hash);
    }
}

// Who is doing the saving. Every write on this screen is authorised against this account's
// own password, so it is a fixture and not an implementation detail.
$GLOBALS['loggedAdminId'] = 0;
if (!function_exists('osc_logged_admin_id')) {
    function osc_logged_admin_id()
    {
        return (int)$GLOBALS['loggedAdminId'];
    }
}

// Recorded rather than enforced: whether the check runs at all, and on which actions, is
// one of the things this file asserts.
$GLOBALS['csrfChecks'] = array();
if (!function_exists('osc_csrf_check')) {
    function osc_csrf_check($die = true)
    {
        $GLOBALS['csrfChecks'][] = Params::getParam('action');

        return true;
    }
}
if (!function_exists('osc_add_flash_error_message')) {
    function osc_add_flash_error_message($msg, $section = 'pubMessages')
    {
        $GLOBALS['flashes'][] = array('error', $msg);
    }
}
if (!function_exists('osc_add_flash_ok_message')) {
    function osc_add_flash_ok_message($msg, $section = 'pubMessages')
    {
        $GLOBALS['flashes'][] = array('ok', $msg);
    }
}
if (!function_exists('osc_add_flash_warning_message')) {
    function osc_add_flash_warning_message($msg, $section = 'pubMessages')
    {
        $GLOBALS['flashes'][] = array('warning', $msg);
    }
}

// The page shell the theme draws around the form. Not stubbed: osc_admin_page() is what
// carries the screen's title into the browser title, which is chrome the migration had to
// keep.
require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
if (!function_exists('osc_current_admin_theme_path')) {
    function osc_current_admin_theme_path($file = '')
    {
    }
}
if (!function_exists('osc_current_admin_theme_url')) {
    function osc_current_admin_theme_url($file = '')
    {
        return 'https://example.test/oc-admin/themes/modern/' . $file;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hValidate.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

/**
 * The controller's base class, stubbed: a redirect is recorded rather than taken (the real
 * one exits), and the section permission check has already happened by the time doModel()
 * runs.
 */
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

    public function isModerator()
    {
        return !empty($GLOBALS['isModerator']);
    }

    public function redirectTo($url, $code = null)
    {
        $GLOBALS['redirects'][] = $url;
    }

    public function _exportVariableToView($key, $value)
    {
        View::newInstance()->_exportVariableToView($key, $value);
    }

    /** The admin theme's view for this screen, drawn where the real one would draw it. */
    public function doView($view)
    {
        include ABS_PATH . 'oc-admin/themes/modern/' . $view;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminAdmins.php';

use mindstellar\admin\form\AdminAccountForm;
use mindstellar\settings\SettingsPageRegistry;

$GLOBALS['effects']     = array();
$GLOBALS['flashes']     = array();
$GLOBALS['redirects']   = array();
$GLOBALS['isModerator'] = false;

osc_add_hook('admin_form_after_save', static function ($pageId, $values, $id) {
    $GLOBALS['effects'][] = array($pageId, $id);
});

// The last thing the save pipeline does before handing the values to the store, so a
// request that got this far reached the store. Recorded so the controller's own checks can
// be pinned apart from the store's: both refuse a key with no row behind it.
$GLOBALS['attempts'] = array();
osc_add_filter('admin_form_before_save', static function ($values, $pageId) {
    $GLOBALS['attempts'][] = $pageId;

    return $values;
});

// The one hook a plugin has ever had inside this form, and the welcome email's payload.
$GLOBALS['profileHook'] = array();
osc_add_hook('admin_profile_form', static function ($row) {
    $GLOBALS['profileHook'][] = $row;
    echo '<!--plugin-row-->';
});
$GLOBALS['emails'] = array();
osc_add_hook('hook_email_new_admin', static function ($data) {
    $GLOBALS['emails'][] = $data;
});
$GLOBALS['edited'] = array();
osc_add_hook('admin_edit_completed', static function ($id, $updated) {
    $GLOBALS['edited'][] = array($id, $updated);
});

// Everything the four lifecycle hooks are handed, so what a plugin can see is assertable
// rather than inferred. A secret in any of these is the site owner's own password.
$GLOBALS['hookPayloads'] = array();
osc_add_filter('admin_form_before_save', static function ($values, $pageId) {
    $GLOBALS['hookPayloads']['admin_form_before_save'][] = $values;

    return $values;
});
osc_add_hook('admin_form_after_save', static function ($pageId, $values, $id) {
    $GLOBALS['hookPayloads']['admin_form_after_save'][] = $values;
});
osc_add_hook('settings_page_saved', static function ($pageId, $values) {
    $GLOBALS['hookPayloads']['settings_page_saved'][] = $values;
});
osc_add_hook('admin_form_save_failed', static function ($pageId, $errors, $values) {
    $GLOBALS['hookPayloads']['admin_form_save_failed'][] = $values;
});

/**
 * Put a request in front of the real controller the way a browser would, and hand back
 * what it did with it.
 */
function drive(string $action, array $fields = array(), array $query = array()): array
{
    $_GET  = $query + array('page' => 'admins', 'action' => $action);
    $_POST = $fields;
    Params::init();
    $GLOBALS['effects']     = array();
    $GLOBALS['flashes']     = array();
    $GLOBALS['redirects']   = array();
    $GLOBALS['csrfChecks']  = array();
    $GLOBALS['attempts']    = array();
    $GLOBALS['profileHook'] = array();
    $GLOBALS['emails']      = array();
    $GLOBALS['edited']      = array();
    $GLOBALS['hookPayloads'] = array();

    ob_start();
    $controller = new CAdminAdmins();
    $controller->doModel();
    $drawn = (string)ob_get_clean();

    return array(
        'flashes'   => $GLOBALS['flashes'],
        'redirects' => $GLOBALS['redirects'],
        'effects'   => $GLOBALS['effects'],
        'csrf'      => $GLOBALS['csrfChecks'],
        'attempts'  => $GLOBALS['attempts'],
        'profile'   => $GLOBALS['profileHook'],
        'emails'    => $GLOBALS['emails'],
        'edited'    => $GLOBALS['edited'],
        'drawn'     => $drawn,
    );
}

/** One row by primary key, read with raw SQL so the code under test is not asked to agree. */
function row(mysqli $admin, int $id): array
{
    $res = $admin->query('SELECT * FROM ' . DB_TABLE_PREFIX . 't_admin WHERE pk_i_id = ' . $id);
    $out = $res ? ($res->fetch_assoc() ?: array()) : array();
    if ($res) {
        $res->free();
    }

    return $out;
}

function rows(mysqli $admin): int
{
    $res = $admin->query('SELECT COUNT(*) c FROM ' . DB_TABLE_PREFIX . 't_admin');
    $n   = $res ? (int)($res->fetch_assoc()['c'] ?? 0) : -1;
    if ($res) {
        $res->free();
    }

    return $n;
}

/** An account put in place with raw SQL, so the fixture owes nothing to the code under test. */
function seed_admin(mysqli $admin, string $username, string $email, string $password, int $moderator = 0): int
{
    return seed_exec(
        $admin,
        'INSERT INTO ' . DB_TABLE_PREFIX
        . 't_admin (s_name, s_username, s_password, s_email, b_moderator) VALUES (?, ?, ?, ?, ?)',
        'ssssi',
        array(ucfirst($username), $username, osc_hash_password($password), $email, $moderator)
    );
}

/** Every request key a rendered form carries, de-duplicated and sorted. */
function input_names(string $html): array
{
    preg_match_all('/<(?:input|select|textarea)[^>]*\sname="([^"]*)"/', $html, $matches);
    $names = array_values(array_unique($matches[1]));
    sort($names);

    return $names;
}

/** The value of one hidden field in the rendered form, or null when it carries none. */
function hidden(string $html, string $name): ?string
{
    return preg_match(
        '/<input type="hidden" name="' . preg_quote($name, '/') . '" value="([^"]*)"\/>/',
        $html,
        $m
    ) ? $m[1] : null;
}

/** The value one text or password control was drawn with, or null when it is not there. */
function control_value(string $html, string $name): ?string
{
    return preg_match(
        '/<input type="(?:text|email|password)" id="field-' . preg_quote($name, '/') . '" name="'
        . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/',
        $html,
        $m
    ) ? $m[1] : null;
}

/** The <form> element and everything in it. */
function form_of(string $html): string
{
    $open = strpos($html, '<form ');

    return $open === false ? '' : substr($html, $open, strpos($html, '</form>') + 7 - $open);
}

/** How a refused key reads in an assertion label. Not the harness's describe(). */
function key_label($value): string
{
    return is_array($value) ? 'an array-valued key' : "'" . (string)$value . "'";
}

/** The submission a valid save needs, with $extra layered over it. */
function submission(array $extra = array()): array
{
    return $extra + array(
        's_name'       => 'Someone New',
        's_username'   => 'someone',
        's_email'      => 'someone@example.test',
        'b_moderator'  => '0',
        's_password'   => 'first-password',
        's_password2'  => 'first-password',
        'old_password' => 'topsecret',
    );
}

/* ------------------------------------------------------------------------- */

$root                     = seed_admin($admin, 'root', 'root@example.test', 'topsecret');
$GLOBALS['loggedAdminId'] = $root;

harness_section('the screen is declared once for each of the three forms it is');

$addId  = AdminAccountForm::register(false, true);
$editId = AdminAccountForm::register(true, true);
$selfId = AdminAccountForm::register(true, false);

pin('adding has a page of its own', 'core.admin_account.add', $addId);
pin('editing another account another', 'core.admin_account.edit', $editId);
pin('and editing your own a third', 'core.admin_account.self', $selfId);

foreach (array($addId, $editId, $selfId) as $pageId) {
    $spec = SettingsPageRegistry::instance()->get($pageId);
    pin(
        $pageId . ' is bound to a row of t_admin',
        array('type' => 'table', 'table' => 't_admin', 'pk' => 'pk_i_id'),
        $spec['store']
    );
    pin($pageId . ' is reached from the list rather than from a menu', '', $spec['menu']);
    pin($pageId . ' is open to an administrator', 'administrator', $spec['capability']);
}

pin(
    'the add form declares no confirmation box, and demands a password',
    array('form_js', 's_name', 's_username', 's_email', 'b_moderator', 's_password',
        'password_separator', 'old_password', 'profile_hook'),
    array_keys(SettingsPageRegistry::instance()->fields($addId))
);
pin(
    'the edit form adds one, and does not demand a password',
    array('form_js', 's_name', 's_username', 's_email', 'b_moderator', 's_password', 's_password2',
        'password_separator', 'old_password', 'profile_hook'),
    array_keys(SettingsPageRegistry::instance()->fields($editId))
);
// Not a layout decision: a field the page does not declare is a column the store cannot
// write, so this is what stops an administrator changing their own account type.
pin(
    'and your own account has no account type on it at all',
    array('form_js', 's_name', 's_username', 's_email', 's_password', 's_password2',
        'password_separator', 'old_password', 'profile_hook'),
    array_keys(SettingsPageRegistry::instance()->fields($selfId))
);
pin(
    'the password is required only where there is no password yet',
    array(true, null, null),
    array(
        SettingsPageRegistry::instance()->fields($addId)['s_password']['required'] ?? null,
        SettingsPageRegistry::instance()->fields($editId)['s_password']['required'] ?? null,
        SettingsPageRegistry::instance()->fields($selfId)['s_password']['required'] ?? null,
    )
);
// The two boxes that are not the account: one confirms the new password, the other proves
// who is doing the saving. Declared as columns they would be written, and t_admin has
// neither -- the save would be refused outright, on every submission.
foreach (array('s_password2', 'old_password') as $name) {
    pin(
        $name . ' is declared as no column at all',
        false,
        SettingsPageRegistry::instance()->fields($editId)[$name]['persist'] ?? null
    );
}

$columns = array();
$res     = $admin->query('SHOW COLUMNS FROM ' . DB_TABLE_PREFIX . 't_admin');
while ($res && ($column = $res->fetch_assoc())) {
    $columns[] = $column['Field'];
}
if ($res) {
    $res->free();
}
pin(
    'the table is the shape the page declares',
    array('pk_i_id', 's_name', 's_username', 's_password', 's_email', 's_secret', 'b_moderator'),
    $columns
);
foreach (SettingsPageRegistry::instance()->fields($editId) as $name => $field) {
    if ($field['type'] === 'custom' || ($field['persist'] ?? null) === false) {
        continue;
    }
    check('the column ' . $name . ' writes to is one the table has', in_array($name, $columns, true));
}

harness_section('the declared widths are the schema\'s widths');

// A cap that is not the column's is either a limit nobody can reach or a rejection of
// values the database would take, so it is read back rather than transcribed.
$widths = array();
$res    = $admin->query(
    'SELECT column_name AS col, character_maximum_length AS len FROM information_schema.columns'
    . " WHERE table_schema = DATABASE() AND table_name = '" . DB_TABLE_PREFIX . "t_admin'"
);
while ($res && ($column = $res->fetch_assoc())) {
    $widths[strtolower($column['col'])] = (int)$column['len'];
}
if ($res) {
    $res->free();
}
$declared = AdminAccountForm::WIDTHS;
$actual   = array_intersect_key($widths, $declared);
ksort($declared);
ksort($actual);
pin('every column the form caps is as wide as it says it is', $declared, $actual);
foreach (AdminAccountForm::WIDTHS as $name => $width) {
    pin(
        'and the ' . $name . ' field carries that number',
        $width,
        SettingsPageRegistry::instance()->fields($addId)[$name]['maxlength'] ?? null
    );
}

harness_section('adding an administrator');

$before = rows($admin);
$driven = drive('add_post', submission());
pin('one account more than there was', $before + 1, rows($admin));
pin('the admin is told it was added', array(array('ok', 'The admin has been added')), $driven['flashes']);
pin(
    'and an added account is sent back to the list',
    array('https://example.test/oc-admin/index.php?page=admins'),
    $driven['redirects']
);
pin('the CSRF check ran, for this action', array('add_post'), $driven['csrf']);
pin('nothing was drawn, because it redirected', '', $driven['drawn']);

$new = (int)($driven['effects'][0][1] ?? 0);
check('the effects were handed the key of the row that was written', $new > 0, var_export($driven['effects'], true));
pin('and the page they name is the add form', array(array('core.admin_account.add', $new)), $driven['effects']);

$stored = row($admin, $new);
pin('the name is stored as submitted', 'Someone New', $stored['s_name'] ?? null);
pin('the username too', 'someone', $stored['s_username'] ?? null);
pin('and the address', 'someone@example.test', $stored['s_email'] ?? null);
pin('the account type is the one that was chosen', '0', (string)($stored['b_moderator'] ?? null));

// The column takes the hash and never the password. A migration that let the plaintext
// through would look identical on every screen in the admin and would be visible only in
// the table -- and every admin password on the install would be readable from a backup.
check(
    'the password column holds a bcrypt hash',
    strpos((string)($stored['s_password'] ?? ''), '$2y$') === 0,
    (string)($stored['s_password'] ?? '')
);
check(
    'which is the hash of what was typed',
    password_verify('first-password', (string)($stored['s_password'] ?? '')),
    (string)($stored['s_password'] ?? '')
);
check(
    'and not the password itself',
    (string)($stored['s_password'] ?? '') !== 'first-password'
);

// The welcome email is the one thing that still needs the plaintext, so it has to survive
// the save that turned it into a hash.
pin('the welcome email is sent once', 1, count($driven['emails']));
pin('carrying the plaintext password, which is the only reason it is still in hand', 'first-password', $driven['emails'][0]['s_password'] ?? null);
pin('and the account it is about', 'someone', $driven['emails'][0]['s_username'] ?? null);
pin('with the name', 'Someone New', $driven['emails'][0]['s_name'] ?? null);
pin('and the address it goes to', 'someone@example.test', $driven['emails'][0]['s_email'] ?? null);

harness_section('what lands in the row is what landed before');

// The edit path read the name through Params::getParam(), whose default runs HTMLPurifier
// over the value and takes every tag out, contents and all. The declared path reads the
// request raw, so the screen declares the same stripping.
$count  = rows($admin);
$driven = drive('add_post', submission(array(
    's_name'     => '<script>alert(1)</script> tagged  ',
    's_username' => 'tagged',
    's_email'    => 'tagged@example.test',
)));
$tagged    = (int)($driven['effects'][0][1] ?? 0);
$wasStored = Params::getParam('s_name');
pin('the old path stripped the tag and what it contained', ' tagged  ', $wasStored);
pin('the declared path stores the same text', 'tagged', row($admin, $tagged)['s_name'] ?? null);
pin('differing from the old row only in the space around it', trim($wasStored), row($admin, $tagged)['s_name']);
pin('and it is one row, saved', $count + 1, rows($admin));

// An ampersand is escaped on the way in, exactly as the edit path already stored it, and
// osc_esc_html() carries an already-escaped entity through untouched.
$driven = drive('add_post', submission(array(
    's_name'     => 'Q&A; Session',
    's_username' => 'ampersand',
    's_email'    => 'ampersand@example.test',
)));
$stored = row($admin, (int)($driven['effects'][0][1] ?? 0))['s_name'] ?? null;
pin('an ampersand is stored the way the edit path stored it', Params::getParam('s_name'), $stored);
pin('which is escaped', 'Q&amp;A; Session', $stored);
pin('and comes back out of osc_esc_html() as the admin typed it', 'Q&amp;A; Session', osc_esc_html($stored));

harness_section('a value the column cannot hold is refused, not cut short');

$count  = rows($admin);
$long   = str_repeat('a', 101);
$driven = drive('add_post', submission(array(
    's_name'     => $long,
    's_username' => 'toolong',
    's_email'    => 'toolong@example.test',
)));
pin('nothing was inserted for an over-long field', $count, rows($admin));
pin(
    'the admin is told which field is too long, and how long it may be',
    array(array('warning', 'Name must be 100 characters or fewer')),
    $driven['flashes']
);
pin('no effect ran for the refused add', array(), $driven['effects']);
pin('no welcome email went out', array(), $driven['emails']);
pin('the admin was not sent away', array(), $driven['redirects']);
check('the form was drawn again', strpos($driven['drawn'], '<form ') !== false, $driven['drawn']);
pin('with all 101 characters still in it, to be shortened', $long, control_value($driven['drawn'], 's_name'));

// Each field is held to its own column, not to one width for the screen: s_username is 40
// where s_name is 100.
$count  = rows($admin);
$driven = drive('add_post', submission(array(
    's_username' => str_repeat('u', 41),
    's_email'    => 'longuser@example.test',
)));
pin(
    'the username is held to the 40 its column has',
    array(array('warning', 'Username must be 40 characters or fewer')),
    $driven['flashes']
);
pin('and nothing was inserted for it either', $count, rows($admin));

harness_section('every problem at once, not the first one only');

// The improvement the declared path brings, and the reason the phase exists: the
// hand-written block answered each failure with a flash and an immediate redirect, so the
// admin saw one problem at a time and lost everything they had typed on each round.
$count  = rows($admin);
$driven = drive('add_post', array(
    's_name'       => '',
    's_username'   => 'not a username',
    's_email'      => 'not-an-address',
    'b_moderator'  => '0',
    's_password'   => '',
    'old_password' => 'topsecret',
));
pin('nothing was inserted for a form of invalid fields', $count, rows($admin));
// In declaration order, which is the order they appear on screen: an admin reading a list
// of problems should be able to walk down the form.
pin('and all four problems are reported together', array(
    array('warning', 'Name cannot be left empty'),
    array('warning', 'Username invalid'),
    array('warning', 'E-mail is not a valid email address'),
    array('warning', 'New password cannot be left empty'),
), $driven['flashes']);
check('with the form drawn again', strpos($driven['drawn'], '<form ') !== false, $driven['drawn']);
pin('carrying the username that was rejected', 'not a username', control_value($driven['drawn'], 's_username'));
pin('and still posting as an add', 'add_post', hidden($driven['drawn'], 'action'));

harness_section('an address the screen cannot store is refused, not rewritten')

// FILTER_SANITIZE_EMAIL deletes the illegal characters and hands back something that then
// validates, so a mistyped address was stored as a *different* address and the screen said
// the admin had been added. An address is the login identifier here.
;
$count  = rows($admin);
foreach (array('john doe@example.test', '<b>joe</b>@example.test', 'joe@@example.test') as $typed) {
    $driven = drive('add_post', submission(array(
        's_username' => 'rewritten',
        's_email'    => $typed,
    )));
    pin(
        'it is refused, in the screen\'s own words (' . $typed . ')',
        array(array('warning', 'E-mail is not a valid email address')),
        $driven['flashes']
    );
    pin('nothing was inserted for it (' . $typed . ')', $count, rows($admin));
    pin('no welcome email went out (' . $typed . ')', array(), $driven['emails']);
    // Escaped in the markup, which is where it belongs -- what matters is that it is the
    // address the admin typed and not one the server edited into shape for them.
    pin(
        'and it comes back on screen as typed, to be corrected (' . $typed . ')',
        osc_esc_html($typed),
        control_value($driven['drawn'], 's_email')
    );
}
// The addresses that were always fine still are, spaces around them and all.
$driven = drive('add_post', submission(array(
    's_username' => 'trimmed',
    's_email'    => '  trimmed@example.test  ',
)));
pin('a good address is still trimmed and stored', 'trimmed@example.test', row($admin, (int)($driven['effects'][0][1] ?? 0))['s_email'] ?? null);

harness_section('no hook is handed the password the acting administrator typed');

// Four hooks see the submission, on the saved path and the rejected one alike. The
// current-password box is the acting administrator's own login password, so a plugin
// logging what it is handed would be recording it on every attempt.
$secrets = array('s_password', 's_password2', 'old_password');
$driven  = drive('add_post', submission(array(
    's_username' => 'hookwatch',
    's_email'    => 'hookwatch@example.test',
    's_password' => 'a-new-password',
)));
pin('the account was added', 1, count($driven['effects']));
// Named rather than walked: the loop below only asserts about hooks that fired, so a hook
// that stopped firing altogether would take its own assertions with it and the suite would
// still be green, one count shorter.
$hooks = array('admin_form_before_save', 'admin_form_after_save', 'settings_page_saved');
pin('the three saved-path hooks fired and no others', $hooks, array_keys($GLOBALS['hookPayloads']));
foreach ($GLOBALS['hookPayloads'] as $hook => $seen) {
    pin($hook . ' fired once for it', 1, count($seen));
    foreach ($secrets as $secret) {
        check(
            $hook . ' was handed no ' . $secret,
            !array_key_exists($secret, $seen[0]),
            $hook . ': ' . implode(', ', array_keys($seen[0]))
        );
    }
    pin('while the address it is about is there (' . $hook . ')', 'hookwatch@example.test', $seen[0]['s_email'] ?? null);
}

$driven = drive('add_post', submission(array(
    's_username' => 'rejected user',
    's_email'    => 'rejected@example.test',
)));
pin('the submission is rejected', 1, count($driven['flashes']));
$failed = $GLOBALS['hookPayloads']['admin_form_save_failed'] ?? array();
pin('and admin_form_save_failed fired once for it', 1, count($failed));
foreach ($secrets as $secret) {
    check('carrying no ' . $secret . ' either', !array_key_exists($secret, $failed[0]), implode(', ', array_keys($failed[0])));
}

// The other direction: a filter that is not shown the password must not be able to set one,
// or every rule this screen enforces is bypassable from a plugin.
$hijack = static function ($values, $pageId) {
    $values['s_password'] = 'set-by-a-plugin';

    return $values;
};
osc_add_filter('admin_form_before_save', $hijack);
$driven = drive('add_post', submission(array(
    's_username' => 'hijacked',
    's_email'    => 'hijacked@example.test',
    's_password' => 'typed-by-the-admin',
)));
osc_remove_filter('admin_form_before_save', $hijack);
$hijacked = (int)($driven['effects'][0][1] ?? 0);
check(
    'the password stored is the one the admin typed',
    password_verify('typed-by-the-admin', (string)row($admin, $hijacked)['s_password'])
);
check(
    'and not the one a listener tried to set',
    !password_verify('set-by-a-plugin', (string)row($admin, $hijacked)['s_password'])
);

// The add screen does not declare the confirmation box, so the loop above says nothing
// about s_password2: it is absent from the payload because it was never a field, not
// because it was withheld. The edit screen is where it exists, so that is where the claim
// can be made. An unchanged edit, so the row this section borrows is left as it was.
check(
    'the edit screen does declare the confirmation box',
    array_key_exists('s_password2', SettingsPageRegistry::instance()->fields($editId)),
    implode(', ', array_keys(SettingsPageRegistry::instance()->fields($editId)))
);
$driven = drive('edit_post', submission(array(
    's_password'  => '',
    's_password2' => '',
)), array('id' => (string)$new));
pin('the edit is accepted', array(array('ok', 'The admin has been updated')), $driven['flashes']);
pin('the same three hooks fired for it', $hooks, array_keys($GLOBALS['hookPayloads']));
foreach ($GLOBALS['hookPayloads'] as $hook => $seen) {
    pin($hook . ' fired once for the edit', 1, count($seen));
    foreach ($secrets as $secret) {
        check(
            $hook . ' on the edit screen was handed no ' . $secret,
            !array_key_exists($secret, $seen[0]),
            $hook . ': ' . implode(', ', array_keys($seen[0]))
        );
    }
    pin('while the name it is about is there (' . $hook . ')', 'Someone New', $seen[0]['s_name'] ?? null);
}

harness_section('the current-password box is re-authentication, and it is checked');

// The gate on the whole screen: without it, any unattended admin session mints a second
// administrator account. It is a field that is never stored, which is exactly how it would
// be lost in a migration -- declared, rendered, and never read.
$count = rows($admin);
foreach (array('' => 'blank', 'wrong-password' => 'wrong', 'TOPSECRET' => 'nearly right') as $typed => $label) {
    $driven = drive('add_post', submission(array(
        's_username'   => 'sneaked',
        's_email'      => 'sneaked@example.test',
        'old_password' => $typed,
    )));
    pin('a ' . $label . ' current password creates no account', $count, rows($admin));
    pin(
        'and is answered the way this screen has always answered it (' . $label . ')',
        array(array('warning', 'Incorrent current password')),
        $driven['flashes']
    );
    pin('no effect ran (' . $label . ')', array(), $driven['effects']);
    pin('and no welcome email went out (' . $label . ')', array(), $driven['emails']);
}

// The same gate on the edit path, where it guards a password change rather than a new
// account.
$victim = seed_admin($admin, 'victim', 'victim@example.test', 'victim-password');
$before = row($admin, $victim);
$driven = drive('edit_post', submission(array(
    's_name'       => 'Taken Over',
    's_username'   => 'victim',
    's_email'      => 'victim@example.test',
    's_password'   => 'attacker-password',
    's_password2'  => 'attacker-password',
    'old_password' => '',
)), array('id' => (string)$victim));
pin('an edit with no current password changes nothing, byte for byte', $before, row($admin, $victim));
pin(
    'and says why',
    array(array('warning', 'Incorrent current password')),
    $driven['flashes']
);
check(
    'so the password is still the one it was',
    password_verify('victim-password', (string)row($admin, $victim)['s_password'])
);

harness_section('editing the account the route names');

$count  = rows($admin);
$driven = drive('edit_post', submission(array(
    's_name'      => 'Someone Else',
    's_username'  => 'someone',
    's_email'     => 'someone@example.test',
    'b_moderator' => '1',
    's_password'  => '',
    's_password2' => '',
)), array('id' => (string)$new));
pin('no second row: the edit updated one', $count, rows($admin));
pin('the admin is told it updated', array(array('ok', 'The admin has been updated')), $driven['flashes']);
pin(
    'and an edited account is sent back to the list',
    array('https://example.test/oc-admin/index.php?page=admins'),
    $driven['redirects']
);
pin('the effects name the row that was already there', array(array('core.admin_account.edit', $new)), $driven['effects']);
pin('no welcome email is sent for an edit', array(), $driven['emails']);
pin('and the edit hook is handed the row and the count', array(array($new, 1)), $driven['edited']);
$stored = row($admin, $new);
pin('an edit changes the name', 'Someone Else', $stored['s_name'] ?? null);
pin('the account type changed', '1', (string)($stored['b_moderator'] ?? null));
pin('the key did not', $new, (int)($stored['pk_i_id'] ?? 0));

harness_section('a blank new-password box leaves the password alone');

// Why the field declares what its column takes: written through, a blank box stores the
// hash of an empty string and every edit of a name changes a password.
$was    = (string)row($admin, $new)['s_password'];
$driven = drive('edit_post', submission(array(
    's_name'      => 'Renamed Again',
    's_username'  => 'someone',
    's_email'     => 'someone@example.test',
    'b_moderator' => '1',
    's_password'  => '',
    's_password2' => '',
)), array('id' => (string)$new));
pin('the save reports success', array(array('ok', 'The admin has been updated')), $driven['flashes']);
pin('the name changed alongside the blank password box', 'Renamed Again', row($admin, $new)['s_name'] ?? null);
pin('and the password column is untouched, to the byte', $was, (string)row($admin, $new)['s_password']);
check('so the old password still verifies', password_verify('first-password', $was));

// And a filled one replaces it.
$driven = drive('edit_post', submission(array(
    's_name'      => 'Renamed Again',
    's_username'  => 'someone',
    's_email'     => 'someone@example.test',
    'b_moderator' => '1',
    's_password'  => 'second-password',
    's_password2' => 'second-password',
)), array('id' => (string)$new));
pin('a filled box saves', array(array('ok', 'The admin has been updated')), $driven['flashes']);
$now = (string)row($admin, $new)['s_password'];
check('and the column holds the hash of the new password', password_verify('second-password', $now));
check('which is not the old one', $now !== $was);

harness_section('the confirmation box has to agree with the password');

$was    = (string)row($admin, $new)['s_password'];
$driven = drive('edit_post', submission(array(
    's_name'      => 'Renamed Again',
    's_username'  => 'someone',
    's_email'     => 'someone@example.test',
    'b_moderator' => '1',
    's_password'  => 'third-password',
    's_password2' => 'mistyped',
)), array('id' => (string)$new));
pin(
    'a mistyped confirmation is refused',
    array(array('warning', "The password couldn't be updated. Passwords don't match")),
    $driven['flashes']
);
pin('and the password column is untouched', $was, (string)row($admin, $new)['s_password']);
pin('nothing else on the row was written either', 'Renamed Again', row($admin, $new)['s_name'] ?? null);
pin('no effect ran for the refused password change', array(), $driven['effects']);

harness_section('an unchanged save is a save');

$driven = drive('edit_post', submission(array(
    's_name'      => 'Renamed Again',
    's_username'  => 'someone',
    's_email'     => 'someone@example.test',
    'b_moderator' => '1',
    's_password'  => '',
    's_password2' => '',
)), array('id' => (string)$new));
// An update that changed nothing affects zero rows. The hand-written block answered that
// with no message at all -- the admin was redirected to the list and told nothing, which
// reads exactly like a save that failed.
pin('the admin is told it updated, not nothing', array(array('ok', 'The admin has been updated')), $driven['flashes']);
pin('and the edit hook still sees the row, with a count of zero', array(array($new, 0)), $driven['edited']);
pin('and it still counts as one save for the effects', 1, count($driven['effects']));

harness_section('both unique columns are refused before the statement runs');

$count  = rows($admin);
$driven = drive('add_post', submission(array(
    's_username' => 'brandnew',
    's_email'    => 'someone@example.test',
)));
pin('an address another account holds is refused on an add', array(array('warning', 'Email already in use')), $driven['flashes']);
pin('and no row was added', $count, rows($admin));

$driven = drive('add_post', submission(array(
    's_username' => 'someone',
    's_email'    => 'brandnew@example.test',
)));
pin('a username another account holds too', array(array('warning', 'Username already in use')), $driven['flashes']);
pin('and still no row was added', $count, rows($admin));

// On an edit the same condition has its own wording, and the row being edited is not
// another account: an admin saving their own address unchanged must not be told it is taken.
$driven = drive('edit_post', submission(array(
    's_name'      => 'Renamed Again',
    's_username'  => 'someone',
    's_email'     => 'root@example.test',
    'b_moderator' => '1',
    's_password'  => '',
    's_password2' => '',
)), array('id' => (string)$new));
pin('an address the root account holds is refused on an edit', array(array('warning', 'Existing email')), $driven['flashes']);
$driven = drive('edit_post', submission(array(
    's_name'      => 'Renamed Again',
    's_username'  => 'root',
    's_email'     => 'someone@example.test',
    'b_moderator' => '1',
    's_password'  => '',
    's_password2' => '',
)), array('id' => (string)$new));
pin('and a username it holds too', array(array('warning', 'Existing username')), $driven['flashes']);
$driven = drive('edit_post', submission(array(
    's_name'      => 'Renamed Again',
    's_username'  => 'someone',
    's_email'     => 'someone@example.test',
    'b_moderator' => '1',
    's_password'  => '',
    's_password2' => '',
)), array('id' => (string)$new));
pin(
    'while the account\'s own address and username are not somebody else\'s',
    array(array('ok', 'The admin has been updated')),
    $driven['flashes']
);

harness_section('nobody sets their own account type');

// The screen has never offered it, and the reason is the column: a moderator who could
// write b_moderator = 0 on their own row would be an administrator a moment later.
$GLOBALS['loggedAdminId'] = $new;
$was                      = row($admin, $new);
$driven                   = drive('edit_post', array(
    's_name'       => 'Renamed Again',
    's_username'   => 'someone',
    's_email'      => 'someone@example.test',
    'b_moderator'  => '0',
    's_password'   => '',
    's_password2'  => '',
    'old_password' => 'second-password',
), array('id' => (string)$new));
pin('the save is accepted', array(array('ok', 'The admin has been updated')), $driven['flashes']);
pin('through the form that has no account type on it', array(array('core.admin_account.self', $new)), $driven['effects']);
pin('and the account type is exactly what it was', $was['b_moderator'], row($admin, $new)['b_moderator']);
$GLOBALS['loggedAdminId'] = $root;

// And the same submission against somebody else's row does set it, so the assertion above
// is about the row and not about the field being broken.
$driven = drive('edit_post', submission(array(
    's_name'      => 'Renamed Again',
    's_username'  => 'someone',
    's_email'     => 'someone@example.test',
    'b_moderator' => '0',
    's_password'  => '',
    's_password2' => '',
)), array('id' => (string)$new));
pin('another administrator may set it', '0', (string)row($admin, $new)['b_moderator']);

harness_section('the row is the one the controller passed, not one the body names');

$other       = seed_admin($admin, 'bystander', 'bystander@example.test', 'bystander-password');
$otherBefore = row($admin, $other);
$count       = rows($admin);

$driven = drive('edit_post', submission(array(
    's_name'      => 'Rewritten',
    's_username'  => 'someone',
    's_email'     => 'someone@example.test',
    'b_moderator' => '0',
    's_password'  => '',
    's_password2' => '',
    'pk_i_id'     => (string)$other,
    'PK_I_ID'     => (string)$other,
)), array('id' => (string)$new));
pin('the account the body named is untouched, to the byte', $otherBefore, row($admin, $other));
pin('the account the controller named is the one that changed', 'Rewritten', row($admin, $new)['s_name'] ?? null);
pin('no row was added on the way', $count, rows($admin));
pin('and the effects name the row the controller passed', array(array('core.admin_account.edit', $new)), $driven['effects']);

harness_section('a key that is not a row writes nothing');

// The shape to be afraid of is not the error page: it is the silent insert. Refuse the key,
// fall through to the save, and an edit screen creates administrator accounts.
$gone = seed_admin($admin, 'deleted', 'deleted@example.test', 'deleted-password');
$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . 't_admin WHERE pk_i_id = ' . $gone);

foreach (array('', '0', '-1', 'abc', ' ' . $new . ' ', $new . 'abc', (string)$gone, array((string)$new)) as $key) {
    $count  = rows($admin);
    $before = row($admin, $new);
    $driven = drive('edit_post', submission(array(
        's_name'      => 'Should not land',
        's_username'  => 'shouldnot',
        's_email'     => 'shouldnot@example.test',
        's_password'  => '',
        's_password2' => '',
    )), array('id' => $key));

    pin('no row is written for ' . key_label($key), $count, rows($admin));
    pin('and no row is changed for ' . key_label($key), $before, row($admin, $new));
    pin(
        'the admin is told the account is gone for ' . key_label($key),
        array(array('error', "This admin doesn't exist")),
        $driven['flashes']
    );
    pin(
        'and sent back to the list for ' . key_label($key),
        array('https://example.test/oc-admin/index.php?page=admins'),
        $driven['redirects']
    );
    pin('no effect ran for ' . key_label($key), array(), $driven['effects']);
    // The store refuses a key with no row behind it as well, so the message and the redirect
    // above read the same whether the controller checked or not. This is the controller's
    // half on its own: it turned the request away without the store being asked anything.
    pin('and the store was never reached for ' . key_label($key), array(), $driven['attempts']);
}

// The same key on the way in, before anything is submitted: an edit form drawn from a key
// with no row behind it would show the declared defaults and then insert on save.
$driven = drive('edit', array(), array('id' => 'abc'));
pin('the edit form is not drawn for a key that is not a row', '', $driven['drawn']);
pin(
    'the admin is told why',
    array(array('error', 'There is no admin with this id')),
    $driven['flashes']
);
pin('nor for a key whose row has been deleted', '', drive('edit', array(), array('id' => (string)$gone))['drawn']);

// The one key that is not a refusal: the "my profile" link carries no id at all, and the
// screen has always answered that with the session's own account.
$driven = drive('edit', array(), array('id' => ''));
check('an edit with no id at all draws the logged-in account', strpos($driven['drawn'], '<form ') !== false, $driven['drawn']);
pin('which is the root account here', 'root', control_value($driven['drawn'], 's_username'));
pin('and it posts back naming that row', (string)$root, hidden($driven['drawn'], 'id'));
// The same fallback on a submission would let a body-only key name a row, so there is none.
$otherBefore = row($admin, $other);
$driven      = drive('edit_post', submission(array(
    's_username' => 'bodynamed',
    's_email'    => 'bodynamed@example.test',
    'pk_i_id'    => (string)$other,
)));
pin('a submission with no route key names no row: that account is untouched', $otherBefore, row($admin, $other));
pin('and the store was never reached', array(), $driven['attempts']);

harness_section('what the screen draws');

$driven = drive('edit', array(), array('id' => (string)$new));
$html   = $driven['drawn'];
pin('the CSRF check does not run for a form that is only being drawn', array(), $driven['csrf']);
pin('the page draws exactly one form', 1, substr_count($html, '<form '));
// The key the store addresses rows by is not something the page hands to the browser: the
// route carries the row, and 'id' is the route.
pin(
    'every request key in it is the route or a declared field',
    array('action', 'b_moderator', 'id', 'old_password', 'page', 's_email', 's_name', 's_password', 's_password2', 's_username'),
    input_names($html)
);
check(
    'so the primary key is not among them',
    !in_array('pk_i_id', input_names($html), true),
    implode(', ', input_names($html))
);
pin('it posts to the admins page', 'admins', hidden($html, 'page'));
pin('at the edit action', 'edit_post', hidden($html, 'action'));
pin('for this account', (string)$new, hidden($html, 'id'));
pin('showing the stored name', 'Rewritten', control_value($html, 's_name'));
pin('the stored username', 'someone', control_value($html, 's_username'));
pin('and the stored address', 'someone@example.test', control_value($html, 's_email'));
// Never: the column holds a hash, and putting one in the markup would publish it.
pin('the new-password box is drawn empty', '', control_value($html, 's_password'));
pin('the confirmation box too', '', control_value($html, 's_password2'));
pin('and the current-password box', '', control_value($html, 'old_password'));
check('the hash is nowhere in the page', strpos($html, (string)row($admin, $new)['s_password']) === false);
check('and the button says what it does', strpos($html, '>Save</button>') !== false, $html);
check('the edit form offers a way out of it', strpos($html, 'javascript:history.go(-1)') !== false, $html);
// The screen's own chrome, which the migration had to keep.
check('the theme wrapper is still there', strpos($html, '<div class="settings-user">') !== false, $html);
check('so is the list the client-side validator writes into', strpos($html, '<ul id="error_list"></ul>') !== false, $html);
check('and the client-side validator itself', strpos($html, 'oscValidateForm(') !== false, $html);
check(
    'which is still bound to the form by the name it has always had',
    strpos($html, 'form[name="admin_form"]') !== false && strpos($html, 'name="admin_form"') !== false,
    $html
);
// The one hook a plugin has ever had inside this form, still fired and still handed the row.
pin('the profile hook runs once', 1, count($driven['profile']));
pin('and is given the row being edited', $new, (int)($driven['profile'][0]['pk_i_id'] ?? 0));
// As strings, the shape a plugin has always been handed here. A row read through the query
// builder is typed, so without that the hook's payload changes kind.
pin('with its columns still strings, as they were', (string)$new, $driven['profile'][0]['pk_i_id'] ?? null);
check('with its markup inside the form', strpos(form_of($html), '<!--plugin-row-->') !== false, form_of($html));

$title = osc_apply_filter('admin_title', 'Shopclass admin panel');
check('the browser title is the screen\'s, ahead of the site\'s', strpos($title, 'Edit admin &raquo; ') === 0, $title);
check(
    'and the site title is still on the end of it',
    substr($title, -strlen('Shopclass admin panel')) === 'Shopclass admin panel',
    $title
);

$driven = drive('add');
$html   = $driven['drawn'];

// The migration's own promise, held to the byte: this is what the hand-written view emitted,
// with the indentation its literal newlines put between the tags taken out -- core's
// renderer emits the elements back to back. Every attribute, class, id, order and escaping
// decision is the one that shipped, apart from the four enumerated in the commit: the row
// labels are now <label for> rather than bare text, `required` reaches the controls, the
// e-mail box carries its column's maxlength, and the account type is no longer a row the
// view builds by hand.
$form = form_of($html);
// The client-side validator's own <script>, stood down to a marker along with the literal
// indentation the PHP file it is written in puts around it.
$form = preg_replace('/\s*<script>.*?<\/script>\s*/s', '<script/>', $form);
pin(
    'the add screen draws the markup it drew before the migration',
    '<form action="https://example.test/oc-admin/index.php" method="post" name="admin_form">'
    . '<input type="hidden" name="page" value="admins"/>'
    . '<input type="hidden" name="action" value="add_post"/>'
    . '<fieldset><div class="form-horizontal">'
    . '<script/>'
    . '<div class="form-row"><div class="form-label"><label for="field-s_name">Name <em>(required)</em></label></div>'
    . '<div class="form-controls"><input type="text" id="field-s_name" name="s_name" class="input-text field-text"'
    . ' value="" required /></div></div>'
    . '<div class="form-row"><div class="form-label"><label for="field-s_username">Username <em>(required)</em></label></div>'
    . '<div class="form-controls"><input type="text" id="field-s_username" name="s_username" class="input-text field-text"'
    . ' value="" required /></div></div>'
    . '<div class="form-row"><div class="form-label"><label for="field-s_email">E-mail <em>(required)</em></label></div>'
    . '<div class="form-controls"><input type="email" id="field-s_email" name="s_email" class="input-text field-text"'
    . ' value="" required maxlength="100" /></div></div>'
    . '<div class="form-row"><div class="form-label"><label for="field-b_moderator">Admin type <em>(required)</em></label></div>'
    . '<div class="form-controls"><select id="field-b_moderator" name="b_moderator" class="field-select" required>'
    . '<option value="0" selected>Administrator</option><option value="1">Moderator</option></select>'
    . '<div class="help-box">Administrators have total control over all aspects of your installation,'
    . ' while moderators are only allowed to moderate listings, comments and media files</div></div></div>'
    . '<div class="form-row"><div class="form-label"><label for="field-s_password">New password</label></div>'
    . '<div class="form-controls"><input type="password" id="field-s_password" name="s_password"'
    . ' class="input-text field-text" value="" autocomplete="off" spellcheck="false" required /></div></div>'
    . '<hr/>'
    . '<div class="form-row"><div class="form-label"><label for="field-old_password">Your current password</label></div>'
    . '<div class="form-controls"><input type="password" id="field-old_password" name="old_password"'
    . ' class="input-text field-text" value="" autocomplete="off" spellcheck="false" />'
    . '<div class="help-box">For security, type <b>your current password</b></div></div></div>'
    . '<!--plugin-row-->'
    . '<div class="form-actions"><button type="submit" class="btn btn-sm btn-submit">Add</button></div>'
    . '</div></fieldset></form>',
    $form
);
pin('the add form posts to the add action', 'add_post', hidden($html, 'action'));
pin('carries no row key at all', null, hidden($html, 'id'));
pin('and no confirmation box, because there is nothing to confirm against', null, control_value($html, 's_password2'));
// The empty string, not null: the row goes to the view and comes back through __get(),
// which answers '' for a key nothing was exported under. The hand-written view read it the
// same way and handed the hook the same value.
pin('the profile hook is handed no row, because there is none yet', array(''), $driven['profile']);
$title = osc_apply_filter('admin_title', 'Shopclass admin panel');
check(
    'and the browser title follows the screen, rather than being fixed',
    strpos($title, 'Add admin &raquo; ') === 0,
    $title
);

harness_section('the write goes through the declaration, not around it');

// Behavioural, and the strongest form the "no hand-written persist" guard takes: a filter
// declared by the pipeline changes what lands in the row. A controller writing its own
// values would ignore it and the row would say what was posted.
$listener = static function ($values, $id) {
    $values['s_name'] = 'through the pipeline';

    return $values;
};
osc_add_filter('admin_form_before_save', $listener);
$driven = drive('edit_post', submission(array(
    's_name'      => 'posted directly',
    's_username'  => 'someone',
    's_email'     => 'someone@example.test',
    'b_moderator' => '0',
    's_password'  => '',
    's_password2' => '',
)), array('id' => (string)$new));
osc_remove_filter('admin_form_before_save', $listener);
pin('the row holds what the pipeline made of the submission', 'through the pipeline', row($admin, $new)['s_name'] ?? null);
pin('and the save still reported success', array(array('ok', 'The admin has been updated')), $driven['flashes']);

harness_section('the guard: no view of a declared page rolls its own form');

$declaredViews = array(
    'oc-includes/osclass/gui/admin/settings-page.php',
    'oc-admin/themes/modern/admins/frm.php',
);
foreach ($declaredViews as $rel) {
    $src = (string)file_get_contents(ABS_PATH . $rel);
    check('scan: no hand-written <form> in ' . basename($rel), strpos($src, '<form') === false, $src);
    check(
        'scan: no hidden route field in ' . basename($rel),
        strpos($src, 'name="action"') === false && strpos($src, 'name="page"') === false,
        'the route belongs to the controller, and the hidden fields to core'
    );
    check(
        'scan: no CSRF check in ' . basename($rel),
        strpos($src, 'osc_csrf_check') === false,
        'a view that checks CSRF is a view that is handling a submission'
    );
    check(
        'scan: the form is core\'s, drawn through the shared helper, in ' . basename($rel),
        strpos($src, 'osc_admin_settings_form(') !== false
    );
}

harness_section('the guard: the migrated controller does not read the fields it declares');

// Behavioural: the CSRF check runs on both saves and on neither of the two draws. A scan
// sees the call and not whether the branch reaches it.
pin('the add form posts through a CSRF check', array('add_post'), drive('add_post', submission(array(
    's_username' => 'csrfprobe',
    's_email'    => 'csrfprobe@example.test',
)))['csrf']);
pin('so does the edit form', array('edit_post'), drive('edit_post', submission(array(
    's_username'  => 'someone',
    's_email'     => 'someone@example.test',
    's_password'  => '',
    's_password2' => '',
)), array('id' => (string)$new))['csrf']);
pin('and neither draw asks for one', array(), array_merge(
    drive('add')['csrf'],
    drive('edit', array(), array('id' => (string)$new))['csrf']
));

// Scan, and only a scan: the absence of a read cannot be observed from outside. The filter
// assertion above is what proves the values that do land came from the pipeline.
$controller = (string)file_get_contents(
    ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminAdmins.php'
);
foreach (array('s_name', 's_username', 's_email', 'b_moderator', 's_password', 's_password2', 'old_password') as $name) {
    check(
        'scan: the controller never reads ' . $name . ' out of the request',
        strpos($controller, "getParam('" . $name . "')") === false
            && strpos($controller, "getParamString('" . $name . "')") === false,
        'a declared field read by hand is the block this migration removed'
    );
}
check(
    'scan: and never hashes or writes the account itself',
    strpos($controller, 'osc_hash_password') === false
        && preg_match('/adminManager->(insert|update)\(/', $controller) === 0,
    'the store owns the write, through osc_db_table()'
);
check(
    'scan: while the delete path, which is not part of the declared form, still uses the model',
    strpos($controller, 'deleteBatch') !== false,
    'the scan above would pass just as well on a controller with no accounts in it at all'
);
// The declaration is where the hashing lives now, and it is the core helper rather than a
// second copy of password_hash().
$form = (string)file_get_contents(
    ABS_PATH . 'oc-includes/osclass/classes/admin/form/AdminAccountForm.php'
);
check('scan: the declaration hashes through osc_hash_password()', strpos($form, 'osc_hash_password(') !== false);
check('scan: and verifies through osc_verify_password()', strpos($form, 'osc_verify_password(') !== false);

harness_section('a demo install saves none of it');

// Defined last, because it cannot be undefined: every assertion above needs it absent.
define('DEMO', true);

// The two paths this migration owns. Deleting is a list action and was not touched.
$count  = rows($admin);
$before = row($admin, $new);
foreach (array('add_post', 'edit_post') as $action) {
    $driven = drive($action, submission(array(
        's_username' => 'demoprobe',
        's_email'    => 'demoprobe@example.test',
    )), array('id' => (string)$new));
    pin('a demo install writes nothing for ' . $action, $count, rows($admin));
    pin('and changes nothing for ' . $action, $before, row($admin, $new));
    pin(
        'saying so for ' . $action,
        array(array('warning', "This action can't be done because it's a demo site")),
        $driven['flashes']
    );
    pin('with no CSRF check, because there is nothing to check for ' . $action, array(), $driven['csrf']);
    pin('and the store never reached for ' . $action, array(), $driven['attempts']);
}

exit(harness_result());
