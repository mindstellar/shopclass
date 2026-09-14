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
 * Pins the table store: the half of a declared page that turns a validated submission
 * into a row, and the primary key it hands back to the effects.
 *
 * Everything above the store is shared with the preference pages that already ship, so
 * the ways this can be wrong are all in the last step, and every one of them is quiet:
 *
 *  - deciding insert-vs-update wrongly is a second row on every save, so an edit screen
 *    fills the table with copies of the thing being edited and the original never changes;
 *  - an update that names columns the page did not declare erases whatever else the row
 *    held, which on a real entity is the half of it this screen does not edit;
 *  - reading an unchanged update's zero affected rows as a failure is the bug the keyword
 *    block controller carries a comment about: the save worked and the admin is told it
 *    did not, so they save again, and again;
 *  - a 'column' mapping ignored writes the field name instead, which on any table is a
 *    column that does not exist or, worse, one that does;
 *  - a write on a rejected submission takes the effects with it, and with them the
 *    reason Permalinks may not rewrite .htaccess for a form that failed validation;
 *  - and the id the effects are handed is what a real after_save redirects to, so a null
 *    or a stale one sends the admin to a row that is not theirs -- which is why the key is
 *    checked against the row the table actually holds, not against the key itself;
 *  - a field core never collected -- a custom one, or one whose 'depends' master is off --
 *    has no value to write, and writing "no value" into its column blanks something the
 *    administrator never touched.
 *
 * The two guards that keep a bound table from being everyone's are exercised rather than
 * read: the controller is driven so a guard left in the source but never taken fails, and
 * the shared view is rendered so a hidden key fails even though the key's name is
 * interpolated and never appears in the view's source.
 *
 * The row it writes is the one its caller named and never one the request carried, which
 * is the difference between a form that edits a record and a form that edits any record
 * whose number somebody can type. A key is parsed rather than coerced for the same
 * reason: (int) reads ' 12 ' and '12abc' as 12, so a malformed key nobody meant lands on
 * a real row.
 *
 * QueryBuilder is immutable, so a store that writes `$q->where(...)` without reassigning
 * drops the clause: the update then refuses to run at all rather than updating the whole
 * table, and the second-row assertions below are what notices.
 *
 * DB-backed: the point is the row, so the row is read back with raw SQL on the admin
 * connection rather than through the code under test.
 *
 * Usage:  php tests/admin-form-store.php
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$admin = scratchdb_session('osc_admin_form_store');

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

// The declared save path leans on these four and on nothing else that needs a request.
if (!function_exists('__')) {
    function __($key, $domain = 'core')
    {
        return $key;
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

require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hValidate.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';

// The controller and the shared view reach for these; each is stubbed at the edge so the
// decision under test is the only thing left doing any deciding.
if (!function_exists('_m')) {
    function _m($key)
    {
        return $key;
    }
}
if (!function_exists('osc_csrf_check')) {
    function osc_csrf_check($die = true)
    {
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
// Page chrome belongs to the admin theme; what is being read here is the form inside it.
if (!function_exists('osc_admin_page')) {
    function osc_admin_page(array $opts)
    {
    }
}
if (!function_exists('osc_current_admin_theme_path')) {
    function osc_current_admin_theme_path($file = '')
    {
    }
}

/**
 * The controller's base class, stubbed: a redirect is recorded rather than taken, and the
 * capability check the real one performs has already happened by the time doModel() runs.
 */
class AdminSecBaseModel
{
    protected $action;

    public function __construct()
    {
        $this->action = Params::getParam('action');
    }

    public function isModerator()
    {
        return false;
    }

    public function redirectTo($url, $code = null)
    {
        $GLOBALS['redirects'][] = $url;
    }

    public function _exportVariableToView($key, $value)
    {
        View::newInstance()->_exportVariableToView($key, $value);
    }
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsCustom.php';

use mindstellar\admin\form\store\StoreFactory;
use mindstellar\settings\SettingsPageRegistry;

$GLOBALS['effects']   = array();
$GLOBALS['flashes']   = array();
$GLOBALS['redirects'] = array();

/**
 * Put a submission in front of the save path the way a browser would, and hand back what
 * osc_settings_save() made of it. The real Params is used rather than a stub, so the value
 * that reaches the store has been through the same purifier a live POST goes through.
 */
function post(string $pageId, array $fields, $id = null): array
{
    $_GET  = array();
    $_POST = $fields;
    Params::init();
    $GLOBALS['effects'] = array();

    return osc_settings_save($pageId, $id);
}

/** The e_type column beside one preference, read with raw SQL. */
function pref_type(mysqli $admin, string $section, string $name): ?string
{
    $stmt = $admin->prepare(
        'SELECT e_type FROM ' . DB_TABLE_PREFIX . 't_preference WHERE s_section = ? AND s_name = ?'
    );
    $stmt->bind_param('ss', $section, $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row === null ? null : (string)$row['e_type'];
}

/** One row by primary key, read with raw SQL so the code under test is not asked to agree. */
function row(mysqli $admin, string $table, int $id): array
{
    $res = $admin->query(
        'SELECT * FROM ' . DB_TABLE_PREFIX . $table . ' WHERE pk_i_id = ' . $id
    );
    $out = $res ? ($res->fetch_assoc() ?: array()) : array();
    if ($res) {
        $res->free();
    }

    return $out;
}

/** The primary key of the row a column identifies, read with raw SQL. */
function row_id_by(mysqli $admin, string $table, string $column, string $value): ?int
{
    $stmt = $admin->prepare(
        'SELECT pk_i_id FROM ' . DB_TABLE_PREFIX . $table . ' WHERE ' . $column . ' = ?'
    );
    $stmt->bind_param('s', $value);
    $stmt->execute();
    $result = $stmt->get_result();
    $row    = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return $row === null ? null : (int)$row['pk_i_id'];
}

/**
 * Put a submission in front of the real controller the way a browser would, and hand back
 * the flashes it raised, where it sent the admin, and anything it drew on the way.
 */
function drive(string $pageId, array $fields, string $action = 'custom_post'): array
{
    $_GET  = array();
    $_POST = $fields + array('page' => 'settings', 'action' => $action, 'id' => $pageId);
    Params::init();
    $GLOBALS['effects']   = array();
    $GLOBALS['flashes']   = array();
    $GLOBALS['redirects'] = array();

    ob_start();
    $controller = new CAdminSettingsCustom();
    $controller->doModel();
    $drawn = (string)ob_get_clean();

    return array(
        'flashes'   => $GLOBALS['flashes'],
        'redirects' => $GLOBALS['redirects'],
        'drawn'     => $drawn,
    );
}

/** What core's shared view actually emits for a page, with the theme chrome stubbed out. */
function draw(array $spec, array $stored): string
{
    View::newInstance()->_exportVariableToView('settings_page', $spec);
    View::newInstance()->_exportVariableToView('settings_values', $stored);

    ob_start();
    require ABS_PATH . 'oc-includes/osclass/gui/admin/settings-page.php';

    return (string)ob_get_clean();
}

/** Every request key a rendered form carries, de-duplicated and sorted. */
function input_names(string $html): array
{
    preg_match_all('/\sname="([^"]*)"/', $html, $matches);
    $names = array_values(array_unique($matches[1]));
    sort($names);

    return $names;
}

function rows(mysqli $admin, string $table): int
{
    $res = $admin->query('SELECT COUNT(*) c FROM ' . DB_TABLE_PREFIX . $table);
    $n   = $res ? (int)($res->fetch_assoc()['c'] ?? 0) : -1;
    if ($res) {
        $res->free();
    }

    return $n;
}

/** Every after_save the last post() ran, in order. */
function effects(): array
{
    return $GLOBALS['effects'];
}

/**
 * The id the nth effect was handed, or 'missing' when it never ran.
 *
 * Guarded with array_key_exists rather than ??, because null is the answer a preference
 * page is supposed to give and ?? cannot tell it from an effect that did not run.
 *
 * @return mixed
 */
function effect_id(int $at)
{
    $ran = $GLOBALS['effects'];

    return isset($ran[$at]) && array_key_exists(2, $ran[$at]) ? $ran[$at][2] : 'missing';
}

osc_add_hook('admin_form_after_save', static function ($pageId, $values, $id) {
    $GLOBALS['effects'][] = array('hook', $pageId, $id);
});

$recordInline = static function ($values, $id) {
    $GLOBALS['effects'][] = array('inline', $values, $id);
};

// t_ban_rule is the fixture because it is the shape every entity screen has: an
// auto-increment key and several independently editable columns, one of which the page
// below deliberately does not declare.
SettingsPageRegistry::instance()->register('rule', array(
    'title'      => 'Ban rule',
    'menu'       => '',
    'store'      => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields'     => array(
        array('type' => 'text', 'name' => 's_name', 'label' => 'Name', 'required' => true),
        array('type' => 'text', 'name' => 's_ip', 'label' => 'IP'),
    ),
    'after_save' => $recordInline,
));

// The same table, reached by a field whose name is another column of it. Nothing but a
// respected 'column' can put the value in s_email and leave s_ip empty.
SettingsPageRegistry::instance()->register('mapped', array(
    'title'  => 'Mapped',
    'menu'   => '',
    'store'  => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields' => array(
        array('type' => 'text', 'name' => 's_ip', 'column' => 's_email', 'label' => 'Email'),
    ),
));

// A preference page declared exactly as one was before the store existed.
SettingsPageRegistry::instance()->register('prefs_blank', array(
    'title'  => 'Blank',
    'menu'   => '',
    'fields' => array(
        array('type' => 'text', 'name' => 's_opt', 'label' => 'Optional'),
    ),
));

SettingsPageRegistry::instance()->register('prefs', array(
    'title'      => 'Prefs',
    'menu'       => '',
    'fields'     => array(
        array('type' => 'text', 'name' => 's_name', 'label' => 'Name', 'required' => true),
    ),
    'after_save' => $recordInline,
));

// A preference has a key the same way a row has a column, and a control may be stored
// nowhere or derived from the ones beside it, on either store. This is a preference page
// that uses all three, which is what every migrated core settings screen does.
SettingsPageRegistry::instance()->register('prefs_mapped', array(
    'title'   => 'Mapped prefs',
    'menu'    => '',
    'section' => 'mapped',
    'fields'  => array(
        array('type' => 'text', 'name' => 'shown_as', 'column' => 'stored@as', 'label' => 'Mapped'),
        array('type' => 'text', 'name' => 'helper', 'label' => 'Helper', 'persist' => false),
        array(
            'type'    => 'checkbox',
            'name'    => 'switch',
            'label'   => 'Switch',
            'persist' => static fn ($value, array $values) => $value ? (string)$values['helper'] : '-1',
        ),
        array(
            'type'    => 'secret',
            'name'    => 'token',
            'label'   => 'Token',
            'persist' => static fn ($value) => $value === '' ? null : $value,
            'write_only' => false,
        ),
        array('type' => 'checkbox', 'name' => 'plain_switch', 'label' => 'Plain'),
        array('type' => 'number', 'name' => 'count', 'label' => 'Count'),
    ),
));

harness_section('a new row is inserted');

$result = post('rule', array('s_name' => 'Alice', 's_ip' => '10.0.0.1'));
pin('a good submission reports no errors', array(), $result['errors']);
pin('and one row written', 1, $result['updated']);
$id = $result['id'];
check('the save hands back a primary key', is_int($id) && $id > 0, describe($id));
pin('and exactly one row exists', 1, rows($admin, 't_ban_rule'));
$stored = row($admin, 't_ban_rule', (int)$id);
pin('the key it handed back is the row that was written', (string)$id, $stored['pk_i_id'] ?? null);
pin('the declared columns hold what was submitted', 'Alice', $stored['s_name'] ?? null);
pin('every one of them', '10.0.0.1', $stored['s_ip'] ?? null);
// s_email is a column of this table that the page does not declare. An insert naming it
// would have set it to the empty value of a field that does not exist, which is what its
// schema default happens to be as well -- so the sharp proof of "only what was declared"
// is the mapped page below, where the two candidate columns differ.
pin('and a column the page never declared is left at the table default', '', $stored['s_email'] ?? null);

harness_section('re-posting with the key updates rather than inserting again');

// Set from outside the page, so the update below has something of its own to preserve.
$admin->query(
    'UPDATE ' . DB_TABLE_PREFIX . "t_ban_rule SET s_email = 'kept@example.test' WHERE pk_i_id = " . (int)$id
);
// A second row nothing in this section is about: an update whose WHERE went missing would
// take this one with it, and QueryBuilder's immutability is exactly how that happens.
$other = seed_exec(
    $admin,
    'INSERT INTO ' . DB_TABLE_PREFIX . 't_ban_rule (s_name, s_ip, s_email) VALUES (?, ?, ?)',
    'sss',
    array('Untouched', '192.168.0.1', 'other@example.test')
);

$result = post('rule', array('s_name' => 'Alicia', 's_ip' => '10.0.0.2'), $id);
pin('the update reports no errors', array(), $result['errors']);
pin('one row changed', 1, $result['updated']);
pin('and the key is the row that was already there', $id, $result['id']);
pin('no second row was inserted', 2, rows($admin, 't_ban_rule'));
$stored = row($admin, 't_ban_rule', (int)$id);
pin('the declared columns hold the new values', 'Alicia', $stored['s_name'] ?? null);
pin('all of the address too', '10.0.0.2', $stored['s_ip'] ?? null);
// The whole reason the store names columns from the declaration rather than from the row.
pin('and a column the page never declared is untouched', 'kept@example.test', $stored['s_email'] ?? null);
$untouched = row($admin, 't_ban_rule', $other);
pin('a row the submission did not name keeps its name', 'Untouched', $untouched['s_name'] ?? null);
pin('and the untouched row keeps its address', '192.168.0.1', $untouched['s_ip'] ?? null);

harness_section('an unchanged save affects no rows and is still a success');

$result = post('rule', array('s_name' => 'Alicia', 's_ip' => '10.0.0.2'), $id);
// The bug this pins: an update returns the affected-row count, which is legitimately zero
// when the row already said what the form says, and reading that as failure told the admin
// their save had not worked when it had.
pin('nothing changed, so no error is reported', array(), $result['errors']);
pin('the affected-row count is zero', 0, $result['updated']);
pin('the key is still handed back', $id, $result['id']);
pin('and no row was inserted to make the count non-zero', 2, rows($admin, 't_ban_rule'));
$stored = row($admin, 't_ban_rule', (int)$id);
pin('the row still holds the submitted values', 'Alicia', $stored['s_name'] ?? null);
pin('and the undeclared column is still there', 'kept@example.test', $stored['s_email'] ?? null);
pin('the effects still ran, because an unchanged save is a save', 2, count(effects()));

harness_section('nothing is written when a field fails validation');

$before = rows($admin, 't_ban_rule');
$result = post('rule', array('s_name' => '', 's_ip' => '10.0.0.9'));
pin('the required field is reported', 1, count($result['errors']));
pin('and named', 'Name cannot be left empty', $result['errors'][0] ?? null);
pin('no row was inserted by the refused save', $before, rows($admin, 't_ban_rule'));
pin('nothing was written, so there is no key to report', null, $result['id']);
pin('and the submitted address comes back for the re-render', '10.0.0.9', $result['values']['s_ip'] ?? null);
// The reason Permalinks may not rewrite .htaccess on a rejected form: not "ran once and
// did nothing", but never ran.
pin('no effect ran at all', array(), effects());

$result = post('rule', array('s_name' => '', 's_ip' => '10.0.0.9'), $id);
pin('a rejected update reports its error too', 1, count($result['errors']));
$stored = row($admin, 't_ban_rule', (int)$id);
pin('and the row it named is untouched', 'Alicia', $stored['s_name'] ?? null);
pin('every column of the inserted row', '10.0.0.2', $stored['s_ip'] ?? null);
pin('with no effect run', array(), effects());

harness_section('the effects are handed the row they are about');

$result = post('rule', array('s_name' => 'Bob', 's_ip' => '10.0.0.3'));
$new    = $result['id'];
$ran    = effects();
pin('the hook and the inline callable each ran once', 2, count($ran));
pin('the hook ran first', 'hook', $ran[0][0] ?? null);
pin('for the page that was saved', 'rule', $ran[0][1] ?? null);
// The id is worth nothing unless the row under it is the one this submission wrote, and
// comparing the store's answer to the store's answer agrees with a stale key just as
// happily as with a right one. So the expected key comes out of the table.
$written = row_id_by($admin, 't_ban_rule', 's_name', 'Bob');
check('the submitted values are in a row of their own', $written !== null, describe($written));
// The id was hard-coded null before the store existed; on an insert it is the row that
// was just created, which is what an after_save redirects to.
pin('and the hook carries that row\'s key', $written, effect_id(0));
pin('the inline callable ran second', 'inline', $ran[1][0] ?? null);
pin('sees the saved values', 'Bob', $ran[1][1]['s_name'] ?? null);
pin('and the same key', $written, effect_id(1));
pin('which is the key the save reported', $written, $new);
check('and is not the row the earlier save wrote', $new !== $id, describe($new) . ' vs ' . describe($id));

$result = post('rule', array('s_name' => 'Bobby', 's_ip' => '10.0.0.4'), $new);
$ran    = effects();
pin('an update runs them once as well', 2, count($ran));
pin('and the id is the existing row, not a new one', $new, effect_id(0));
pin('the inline callable is handed the new key too', $new, effect_id(1));
// The same read-back on the update path: a key naming some other row that happens to exist
// is still a key, and only the row behind it says whether it is the right one.
$updated = row($admin, 't_ban_rule', (int)effect_id(0));
pin('the row that key names holds what was just saved', 'Bobby', $updated['s_name'] ?? null);
pin('every column of the updated row', '10.0.0.4', $updated['s_ip'] ?? null);
pin('and no row was inserted behind the update', 3, rows($admin, 't_ban_rule'));

harness_section('a field writes the column it declares');

$before = rows($admin, 't_ban_rule');
$result = post('mapped', array('s_ip' => 'someone@example.test'));
pin('the mapped page saves cleanly on insert', array(), $result['errors']);
pin('and inserts a row', $before + 1, rows($admin, 't_ban_rule'));
$stored = row($admin, 't_ban_rule', (int)$result['id']);
pin('the value lands in the declared column', 'someone@example.test', $stored['s_email'] ?? null);
// Sharp because both columns exist: a store writing by field name as well as by mapping
// would have put the address here too.
pin('and not in the column named after the field', '', $stored['s_ip'] ?? null);

harness_section('reading a row back into the form');

$values = osc_settings_values('rule', $new);
pin('every declared field is keyed by its name', array('s_name', 's_ip'), array_keys($values));
pin('and holds the stored value', 'Bobby', $values['s_name'] ?? null);
pin('all of the re-read address too', '10.0.0.4', $values['s_ip'] ?? null);
pin('one field can be read on its own', 'Bobby', osc_settings_value('rule', 's_name', $new));
pin('a mapped field reads back through its column', 'kept@example.test', osc_settings_value('mapped', 's_ip', (int)$id));
// A new-entity form has no key, so there is no row and the declared defaults are the
// right answer -- not the last row that happened to be written.
$fresh = osc_settings_values('rule');
pin('with no key there is no row, so the declared defaults are used', '', $fresh['s_name'] ?? null);
pin('for every field', '', $fresh['s_ip'] ?? null);
pin('a key nothing was ever saved under reads the same way', '', osc_settings_value('rule', 's_name', 999999));

harness_section('casting on the way out');

// t_keyword_block carries a TINYINT, which the driver hands back as an int: a checkbox
// that read back as 0 rather than false would render unticked-looking either way but
// compare wrong in every `if` a page writes around it.
SettingsPageRegistry::instance()->register('kw', array(
    'title'  => 'Keyword',
    'menu'   => '',
    'store'  => array('table' => 't_keyword_block', 'pk' => 'pk_i_id'),
    'fields' => array(
        array('type' => 'text', 'name' => 's_keyword', 'label' => 'Keyword', 'required' => true),
        array('type' => 'checkbox', 'name' => 'b_substring', 'label' => 'Substring'),
        array('type' => 'text', 'name' => 'dt_date', 'label' => 'Date'),
    ),
));

$result = post('kw', array('s_keyword' => 'spam', 'b_substring' => '1', 'dt_date' => '2026-01-02 03:04:05'));
pin('a checkbox page saves cleanly', array(), $result['errors']);
$kwId   = (int)$result['id'];
$stored = row($admin, 't_keyword_block', $kwId);
pin('a ticked checkbox is stored as 1', '1', $stored['b_substring'] ?? null);
// s_scope is a column of this table with a non-empty default, so an insert naming every
// column of the table rather than every declared field would have blanked it.
pin('a column with a default the page never declared keeps it', 'all', $stored['s_scope'] ?? null);
$values = osc_settings_values('kw', $kwId);
pin('and reads back as a bool, not as the int the column holds', true, $values['b_substring'] ?? null);

$result = post('kw', array('s_keyword' => 'spam', 'dt_date' => '2026-01-02 03:04:05'), $kwId);
pin('unticking it is a change', 1, $result['updated']);
$stored = row($admin, 't_keyword_block', $kwId);
pin('stored as 0', '0', $stored['b_substring'] ?? null);
pin('and read back as false', false, osc_settings_value('kw', 'b_substring', $kwId));
pin('with the scope column still untouched', 'all', $stored['s_scope'] ?? null);

harness_section('a page that declared no store still writes preferences');

$result = post('prefs', array('s_name' => 'Carol'));
pin('the preference page saves cleanly', array(), $result['errors']);
pin('and writes its one value', 1, $result['updated']);
pin('into the page section', 'Carol', Preference::newInstance()->get('s_name', 'prefs'));
pin('reading it back goes through the same store', 'Carol', osc_settings_value('prefs', 's_name'));
// Preferences are keyed by name, not by row, so there is nothing for an id to mean and
// the effects keep being handed null -- unchanged from before the store existed.
$ran = effects();
pin('the effects ran', 2, count($ran));
pin('with a null id, because a preference page has no row', null, effect_id(0));
pin('the inline callable is handed no key either', null, effect_id(1));
pin('and no ban rule was written by it', 4, rows($admin, 't_ban_rule'));

// A preference page counts real changes, the way a table-backed one already does through
// its affected-row count. Without it every field it declares counts as written, so an
// unchanged save reports a change and the screen can never say "Nothing to update".
$result = post('prefs', array('s_name' => 'Carol'));
pin('re-posting the same value changes nothing', 0, $result['updated']);
pin('and the stored value is still there', 'Carol', Preference::newInstance()->get('s_name', 'prefs'));
$result = post('prefs', array('s_name' => 'Carol '));
pin('a value that only differs by the trim is no change either', 0, $result['updated']);
$result = post('prefs', array('s_name' => 'Dave'));
pin('changing it is one change', 1, $result['updated']);
pin('and the new value is stored', 'Dave', Preference::newInstance()->get('s_name', 'prefs'));

// get() answers '' both for a key that is absent and for one stored empty, so a
// write-only-if-changed that compared values would skip the write that creates the row --
// and the page would come back showing its declared default instead of the blank.
check('the optional key starts absent', pref_type($admin, 'prefs_blank', 's_opt') === null);
$result = post('prefs_blank', array('s_opt' => ''));
pin('a first save of a blank value is still a write', 1, $result['updated']);
check(
    'so the key exists rather than staying absent',
    array_key_exists('s_opt', Preference::newInstance()->getSection('prefs_blank')),
    var_export(Preference::newInstance()->getSection('prefs_blank'), true)
);
$result = post('prefs_blank', array('s_opt' => ''));
pin('and saving the same blank again changes nothing', 0, $result['updated']);
$result = post('prefs_blank', array('s_opt' => 'now set'));
pin('filling it in is a change', 1, $result['updated']);

harness_section('a preference page maps, derives and withholds the same way a table does');

$result = post('prefs_mapped', array(
    'shown_as' => 'value',
    'helper'   => '7',
    'switch'   => '1',
    'token'    => 'sekrit',
    'plain_switch' => '1',
    'count'    => '3',
));
pin('the mapped page saves cleanly on update', array(), $result['errors']);
pin(
    'a field with a column lands under that key, punctuation and all',
    'value',
    Preference::newInstance()->get('stored@as', 'mapped')
);
pin('and not under the name of its control', '', Preference::newInstance()->get('shown_as', 'mapped'));
pin('a field that is stored nowhere is stored nowhere', '', Preference::newInstance()->get('helper', 'mapped'));
check('with no row of its own in the table', pref_type($admin, 'mapped', 'helper') === null);
pin('a derived value is what its callable returned', '7', Preference::newInstance()->get('switch', 'mapped'));
pin('a secret that was typed is written', 'sekrit', Preference::newInstance()->get('token', 'mapped'));

$result = post('prefs_mapped', array('shown_as' => 'value', 'helper' => '7', 'switch' => '', 'token' => ''));
pin('the derivation answers the other way when the switch is off', '-1', Preference::newInstance()->get('switch', 'mapped'));
pin('and a callable returning null leaves the value alone', 'sekrit', Preference::newInstance()->get('token', 'mapped'));

// Nothing in core reads e_type, but it is what a site owner sees looking at the table and
// what the screens this replaced had already decided, so it is derived rather than dropped.
pin(
    'the type column follows the field type',
    array('STRING', 'BOOLEAN', 'INTEGER'),
    array(
        pref_type($admin, 'mapped', 'stored@as'),
        pref_type($admin, 'mapped', 'plain_switch'),
        pref_type($admin, 'mapped', 'count'),
    )
);
pin('and a derived value is a string, because core does not know what came back', 'STRING', pref_type($admin, 'mapped', 'switch'));

harness_section('the row is the caller\'s, never the request\'s');

// The whole of finding one in a single POST: a row key in the submission, and a store
// that reads it, is every row of the bound table writable by anyone who can reach the
// page. The key here is a real row, so a store that still looked would overwrite it.
$victim = row($admin, 't_ban_rule', (int)$id);
$before = rows($admin, 't_ban_rule');
$result = post('rule', array('pk_i_id' => (string)$id, 's_name' => 'Pwned', 's_ip' => '10.0.0.66'));
pin('a submission carrying a row key still saves', array(), $result['errors']);
check(
    'but the key it carried is not the row that was written',
    $result['id'] !== $id,
    describe($result['id']) . ' vs ' . describe($id)
);
pin('it inserted a row of its own instead', $before + 1, rows($admin, 't_ban_rule'));
$still = row($admin, 't_ban_rule', (int)$id);
pin('the row the request named keeps its name', $victim['s_name'] ?? null, $still['s_name'] ?? null);
pin('and the surviving row keeps its address', $victim['s_ip'] ?? null, $still['s_ip'] ?? null);
pin('and the column the page never declared', $victim['s_email'] ?? null, $still['s_email'] ?? null);

harness_section('a key that is not a positive integer is refused, not guessed at');

// Every one of these is a row number to (int): ' 12 ' and '12abc' are both 12, true is 1,
// and 1.9 is 1. Coerced, two of them write to a row nobody named; parsed, all of them are
// the same answer -- no.
$before = rows($admin, 't_ban_rule');
$kept   = row($admin, 't_ban_rule', (int)$id);
$cases  = array(
    'a key with spaces round it'  => ' ' . $id . ' ',
    'a key with trailing garbage' => $id . 'abc',
    'a decimal key'               => $id . '.0',
    'a zero key'                  => '0',
    'a negative key'              => '-1',
    'a key that is not a number'  => 'abc',
    'a char primary key'          => 'en_US',
    'a key that is an array'      => array((string)$id),
    'a key that is a bool'        => true,
    'a key that is a float'       => 1.9,
);
foreach ($cases as $what => $key) {
    $result = post('rule', array('s_name' => 'Guessed', 's_ip' => '10.0.0.7'), $key);
    pin($what . ' is refused with one error', 1, count($result['errors']));
    // Inside the loop, not after it: worded once at the end, this pins the last case only.
    pin(
        'the refusal for ' . $what . ' is worded by core, not by the store',
        'That form does not name a record that can be saved.',
        $result['errors'][0] ?? null
    );
    pin('nothing is written for ' . $what, $before, rows($admin, 't_ban_rule'));
    pin('no key comes back for ' . $what, null, $result['id']);
    pin('and no effect runs for ' . $what, array(), effects());
}
pin('with the submitted values back for the re-render', '10.0.0.7', $result['values']['s_ip'] ?? null);
$still = row($admin, 't_ban_rule', (int)$id);
pin('the row a coerced key would have landed on keeps its name', $kept['s_name'] ?? null, $still['s_name'] ?? null);
pin('and the kept row keeps its address', $kept['s_ip'] ?? null, $still['s_ip'] ?? null);

harness_section('a key with no row behind it is refused');

// Affected rows cannot tell an unchanged row from a WHERE that matched nothing, so
// without a look-up first this is the admin whose row was deleted while their tab was
// open: told there was nothing to update, edits discarded, and the effects run anyway.
$deleted = post('rule', array('s_name' => 'Doomed', 's_ip' => '10.0.0.10'))['id'];
$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . 't_ban_rule WHERE pk_i_id = ' . (int)$deleted);
$before = rows($admin, 't_ban_rule');

$result = post('rule', array('s_name' => 'Doomed edited', 's_ip' => '10.0.0.11'), $deleted);
pin('saving a row that was deleted elsewhere is an error', 1, count($result['errors']));
pin('and says so', 'That record no longer exists, so nothing was saved.', $result['errors'][0] ?? null);
pin('the edit is not reported as a save that changed nothing', 0, $result['updated']);
pin('no row is recreated under the key', $before, rows($admin, 't_ban_rule'));
pin('no key comes back from the refused save', null, $result['id']);
pin('no effect runs for a save that wrote nothing', array(), effects());
pin('and the edits come back on screen', 'Doomed edited', $result['values']['s_name'] ?? null);

$result = post('rule', array('s_name' => 'Ghost', 's_ip' => '10.0.0.12'), 999999);
pin('a key nothing was ever saved under is refused the same way', 1, count($result['errors']));
pin('with nothing written', $before, rows($admin, 't_ban_rule'));
pin('and no effect run', array(), effects());

harness_section('a write the table refuses comes back as an error');

// QueryBuilder throws by design, and an uncaught throw here is a generic error page: the
// admin loses everything they typed, which is the failure the declared path exists to
// prevent.
SettingsPageRegistry::instance()->register('broken', array(
    'title'      => 'Broken',
    'menu'       => '',
    'store'      => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields'     => array(
        array('type' => 'text', 'name' => 's_name', 'column' => 's_not_a_column', 'label' => 'Name'),
    ),
    'after_save' => $recordInline,
));

$before = rows($admin, 't_ban_rule');
$result = post('broken', array('s_name' => 'Alice'));
pin('a column the table does not have is one error, not an error page', 1, count($result['errors']));
pin(
    'worded by core',
    'That could not be saved. Please check the values and try again.',
    $result['errors'][0] ?? null
);
// DbException carries the constant 'Database query failed' by design, so it cannot leak a
// schema name on its own. What can is a handler that appends the driver's message, or one
// that helpfully names the column it tried: both are edits to the wording, so the wording
// is what is pinned -- core's sentence, and nothing bolted onto it.
foreach (array('Database query failed', 's_not_a_column', 't_ban_rule', 'SQL') as $leak) {
    check(
        'with nothing of the failed statement bolted on: ' . $leak,
        strpos($result['errors'][0] ?? '', $leak) === false,
        describe($result['errors'][0] ?? null)
    );
}
pin('nothing was written', $before, rows($admin, 't_ban_rule'));
pin('no key comes back from the refused update', null, $result['id']);
pin('no effect runs on a write that failed', array(), effects());
pin('and the submitted name comes back for the re-render', 'Alice', $result['values']['s_name'] ?? null);

harness_section('a refused write is a rejected save, and it carries no secret');

// admin_form_save_failed has two call sites and they are not the same code path. The one
// reached from a failed validation is pinned in tests/admin-form-lifecycle-hooks.php; this
// is the other one, reached when the row is gone or the table refuses the statement. The
// password was typed on that attempt exactly as it was on the one that saved, so a listener
// counting failures would be recording it here too.
$GLOBALS['failedPayloads'] = array();
osc_add_hook('admin_form_save_failed', static function ($pageId, $errors, $values) {
    $GLOBALS['failedPayloads'][] = $values;
});

// The shape a re-authentication box has: a secret that is no column at all.
$secretField = array(
    'type'       => 'secret',
    'name'       => 'old_password',
    'label'      => 'Your current password',
    'persist'    => false,
    'write_only' => true,
);
SettingsPageRegistry::instance()->register('brokensecret', array(
    'title'  => 'Broken, with a secret',
    'menu'   => '',
    'store'  => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields' => array(
        array('type' => 'text', 'name' => 's_name', 'column' => 's_not_a_column', 'label' => 'Name'),
        $secretField,
    ),
));
SettingsPageRegistry::instance()->register('gonesecret', array(
    'title'  => 'Gone, with a secret',
    'menu'   => '',
    'store'  => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields' => array(
        array('type' => 'text', 'name' => 's_name', 'label' => 'Name'),
        $secretField,
    ),
));

// Both ways a write is refused, so neither branch can be the one that leaks.
$refusals = array(
    'a column the table does not have' => array('brokensecret', null),
    'a key whose row is gone'          => array('gonesecret', $deleted),
);
foreach ($refusals as $what => $case) {
    $GLOBALS['failedPayloads'] = array();
    $result = post($case[0], array('s_name' => 'Alice', 'old_password' => 'hunter2'), $case[1]);
    pin('the write is refused for ' . $what, 1, count($result['errors']));
    pin('and admin_form_save_failed fired once for ' . $what, 1, count($GLOBALS['failedPayloads']));
    $payload = $GLOBALS['failedPayloads'][0] ?? array();
    check(
        'carrying no secret for ' . $what,
        is_array($payload) && !array_key_exists('old_password', $payload),
        implode(', ', array_keys(is_array($payload) ? $payload : array()))
    );
    pin('while the field beside it is there for ' . $what, 'Alice', $payload['s_name'] ?? null);
    // The caller declared the field, so it still has it: withholding is about the hooks.
    pin('and the caller still sees it for ' . $what, 'hunter2', $result['values']['old_password'] ?? null);
}

harness_section('a before_save listener cannot write a column the page never declared');

// The store walks the declaration and not the submission, which is the other half of
// narrowing what the filter hands back: with either one gone, a listener on any page could
// write any column of the bound table. Neither is observable on its own, so the assertion
// below is what says the pair of them still holds.
$injectTarget = post('rule', array('s_name' => 'Declared', 's_ip' => '10.0.0.20'))['id'];
$admin->query(
    'UPDATE ' . DB_TABLE_PREFIX . "t_ban_rule SET s_email = 'notyours@example.test' WHERE pk_i_id = "
    . (int)$injectTarget
);
$inject = static function ($values, $pageId) {
    // s_email is a real column of t_ban_rule that the 'rule' page does not declare.
    $values['s_email'] = 'injected@example.test';

    return $values;
};
osc_add_filter('admin_form_before_save', $inject);
$result = post('rule', array('s_name' => 'Declared again', 's_ip' => '10.0.0.21'), $injectTarget);
osc_remove_filter('admin_form_before_save', $inject);
pin('the save itself is clean', array(), $result['errors']);
$stored = row($admin, 't_ban_rule', (int)$injectTarget);
pin('the declared columns take the submission', 'Declared again', $stored['s_name'] ?? null);
pin('all of the address on the second store too', '10.0.0.21', $stored['s_ip'] ?? null);
pin('and the column the listener named is untouched', 'notyours@example.test', $stored['s_email'] ?? null);
check(
    'the key it invented does not come back to the caller either',
    !array_key_exists('s_email', $result['values']),
    implode(', ', array_keys($result['values']))
);

harness_section('a field core did not collect keeps its column');

// The subtler half of "only declared columns are written": a field core never read has no
// value to put anywhere, and putting "no value" in its column blanks something the
// administrator never touched. The custom field is named after a real column on purpose --
// that is the only way to tell "not written" from "written as nothing".
SettingsPageRegistry::instance()->register('untouched', array(
    'title'  => 'Untouched',
    'menu'   => '',
    'store'  => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields' => array(
        array('type' => 'text', 'name' => 's_name', 'label' => 'Name'),
        array(
            'type'    => 'custom',
            'name'    => 's_email',
            'label'   => 'Email',
            'default' => 'unread',
            'render'  => static function () {
                echo '<em>drawn by whoever declared it</em>';
            },
        ),
    ),
));

$keeper = seed_exec(
    $admin,
    'INSERT INTO ' . DB_TABLE_PREFIX . 't_ban_rule (s_name, s_ip, s_email) VALUES (?, ?, ?)',
    'sss',
    array('Keeper', '10.1.0.1', 'keeper@example.test')
);
$before = rows($admin, 't_ban_rule');
$result = post('untouched', array('s_name' => 'Keeper edited', 's_email' => 'attacker@example.test'), $keeper);
pin('a page with a field core does not read saves cleanly', array(), $result['errors']);
pin('and changes the row', 1, $result['updated']);
$stored = row($admin, 't_ban_rule', $keeper);
pin('the declared field is written', 'Keeper edited', $stored['s_name'] ?? null);
pin('the column behind the uncollected field is neither blanked nor nulled', 'keeper@example.test', $stored['s_email'] ?? null);
pin('and no row was inserted', $before, rows($admin, 't_ban_rule'));

$values = osc_settings_values('untouched', $keeper);
pin('reading back gives the uncollected field its declared default', 'unread', $values['s_email'] ?? null);
pin('and not the column it happens to be named after', 'Keeper edited', $values['s_name'] ?? null);

// Every field uncollected is the same decision taken to its end: no column to set, so an
// existing row is left exactly as it was and no row is invented for a submission that
// named nothing to put in one.
SettingsPageRegistry::instance()->register('nothing', array(
    'title'      => 'Nothing to write',
    'menu'       => '',
    'store'      => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields'     => array(
        array(
            'type'   => 'custom',
            'name'   => 's_name',
            'label'  => 'Name',
            'render' => static function () {
                echo '<em>drawn by whoever declared it</em>';
            },
        ),
    ),
    'after_save' => $recordInline,
));

$kept   = row($admin, 't_ban_rule', $keeper);
$before = rows($admin, 't_ban_rule');
$result = post('nothing', array('s_name' => 'Blanked'), $keeper);
pin('a page with no column to write is not an error', array(), $result['errors']);
pin('and reports that nothing changed', 0, $result['updated']);
pin('the row it named keeps its name', $kept['s_name'] ?? null, row($admin, 't_ban_rule', $keeper)['s_name'] ?? null);
pin('and the keeper row keeps its address', $kept['s_ip'] ?? null, row($admin, 't_ban_rule', $keeper)['s_ip'] ?? null);
pin('and its address book', $kept['s_email'] ?? null, row($admin, 't_ban_rule', $keeper)['s_email'] ?? null);
pin('the key still comes back', $keeper, $result['id']);
pin('and the effects run for whatever did draw the fields', 2, count(effects()));

$result = post('nothing', array('s_name' => 'Blanked'));
pin('with no key there is nothing to insert', $before, rows($admin, 't_ban_rule'));
pin('so no key comes back', null, $result['id']);
pin('and it is still not an error', array(), $result['errors']);

harness_section('a field says what its column takes, or that it has none');

// Two shapes of the same key. 'persist' => false is a control that is not a column at all
// -- a confirmation box, a re-authentication box -- collected and validated like any other
// and written nowhere; a callable derives what the column takes, and null from it writes
// nothing, which is how "blank means unchanged" is declared rather than special-cased.
// It says nothing about what the control shows on the way back; that is 'write_only',
// its own key, checked below.
SettingsPageRegistry::instance()->register('derived', array(
    'title'  => 'Derived columns',
    'menu'   => '',
    'store'  => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields' => array(
        array(
            'type'    => 'text',
            'name'    => 's_name',
            'label'   => 'Name',
            'persist' => static fn ($value) => $value === '' ? null : 'derived:' . $value,
        ),
        array('type' => 'text', 'name' => 's_ip', 'label' => 'IP'),
        // Named after a real column on purpose: that is the only way to tell "not written"
        // from "written as nothing".
        array('type' => 'text', 'name' => 's_email', 'label' => 'Confirm', 'persist' => false),
    ),
));

$derived = seed_exec(
    $admin,
    'INSERT INTO ' . DB_TABLE_PREFIX . 't_ban_rule (s_name, s_ip, s_email) VALUES (?, ?, ?)',
    'sss',
    array('Before', '10.4.0.1', 'before@example.test')
);
$before = rows($admin, 't_ban_rule');
$result = post('derived', array(
    's_name'  => 'typed',
    's_ip'    => '10.4.0.2',
    's_email' => 'attacker@example.test',
), $derived);
pin('the save is clean for the mapped columns', array(), $result['errors']);
$stored = row($admin, 't_ban_rule', $derived);
pin('the column takes what the callable made of the value', 'derived:typed', $stored['s_name'] ?? null);
pin('an ordinary field still takes the value itself', '10.4.0.2', $stored['s_ip'] ?? null);
pin('and the column a persist-false field is named after is untouched', 'before@example.test', $stored['s_email'] ?? null);
pin('no row was inserted by the second store', $before, rows($admin, 't_ban_rule'));

// null from the callable is "leave this column alone", the whole reason a blank password
// box can mean "unchanged" without the store knowing what a password is.
$result = post('derived', array('s_name' => '', 's_ip' => '10.4.0.3', 's_email' => ''), $derived);
pin('a callable returning null is not an error', array(), $result['errors']);
$stored = row($admin, 't_ban_rule', $derived);
pin('its column keeps exactly what it held', 'derived:typed', $stored['s_name'] ?? null);
pin('while the field beside it still saves', '10.4.0.3', $stored['s_ip'] ?? null);

// persist is not readback. Riding both on one key blanked a column nobody had been shown:
// a field deriving its column drew empty, the admin saved the form as it stood, and the
// column went with it. So a derived field still reads its column unless it says otherwise.
$values = osc_settings_values('derived', $derived);
pin('a derived field still reads its column back', 'derived:typed', $values['s_name'] ?? null);
pin('an ordinary field does too', '10.4.0.3', $values['s_ip'] ?? null);
// A field that is no column has none to read: it is named after a real one here on
// purpose, and reading that would be showing a column belonging to something else.
pin('and a field that is no column reads its default', '', $values['s_email'] ?? null);

harness_section('write_only is the other half, and it is its own key');

SettingsPageRegistry::instance()->register('writeonly', array(
    'title'  => 'Write-only columns',
    'menu'   => '',
    'store'  => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields' => array(
        array(
            'type'       => 'text',
            'name'       => 's_name',
            'label'      => 'Name',
            'persist'    => static fn ($value) => $value === '' ? null : 'derived:' . $value,
            'write_only' => true,
        ),
        array('type' => 'text', 'name' => 's_ip', 'label' => 'IP', 'write_only' => true, 'default' => 'n/a'),
        array('type' => 'text', 'name' => 's_email', 'label' => 'Email'),
    ),
));

$result = post('writeonly', array(
    's_name'  => 'typed',
    's_ip'    => '10.4.0.9',
    's_email' => 'shown@example.test',
), $derived);
pin('a write-only field is still collected and written', array(), $result['errors']);
$stored = row($admin, 't_ban_rule', $derived);
pin('its column takes what it derived', 'derived:typed', $stored['s_name'] ?? null);
pin('and one with no persist takes the value itself', '10.4.0.9', $stored['s_ip'] ?? null);

$values = osc_settings_values('writeonly', $derived);
pin('but the control shows the declared default instead of the column', '', $values['s_name'] ?? null);
pin('the default, not the empty string, when one is declared', 'n/a', $values['s_ip'] ?? null);
pin('while the field that is not write-only shows what is stored', 'shown@example.test', $values['s_email'] ?? null);
pin('and one field at a time reads the same way', '', osc_settings_value('writeonly', 's_name', $derived));

// The same key on the preference store: a stored API key is as good a reason not to redraw
// a value as a column holding a hash, so it is not a table-store-only idea.
SettingsPageRegistry::instance()->register('writeonlypref', array(
    'title'  => 'Write-only preferences',
    'menu'   => '',
    'fields' => array(
        array('type' => 'text', 'name' => 'p_shown', 'label' => 'Shown'),
        array('type' => 'text', 'name' => 'p_hidden', 'label' => 'Hidden', 'write_only' => true, 'default' => 'x'),
    ),
));
$result = post('writeonlypref', array('p_shown' => 'a', 'p_hidden' => 'b'));
pin('the preference save is clean', array(), $result['errors']);
pin('the write-only preference is written', 'b', osc_get_preference('p_hidden', 'writeonlypref'));
$values = osc_settings_values('writeonlypref');
pin('and read back as its default', 'x', $values['p_hidden'] ?? null);
pin('while its neighbour reads what was stored', 'a', $values['p_shown'] ?? null);

// The validation pipeline still runs over both: they are refused, re-rendered and reported
// exactly like a column-backed field, which is what makes a confirmation box declarable.
SettingsPageRegistry::instance()->register('derivedreq', array(
    'title'  => 'Derived and required',
    'menu'   => '',
    'store'  => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields' => array(
        array('type' => 'text', 'name' => 's_name', 'label' => 'Name'),
        array('type' => 'text', 'name' => 's_email', 'label' => 'Confirm', 'persist' => false, 'required' => true),
    ),
));
$kept   = row($admin, 't_ban_rule', $derived);
$result = post('derivedreq', array('s_name' => 'Rejected', 's_email' => ''), $derived);
pin('a field that is no column is still required when it says so', array('Confirm cannot be left empty'), $result['errors']);
pin('and the row is untouched, byte for byte', $kept, row($admin, 't_ban_rule', $derived));
pin('while its value still comes back to be corrected', '', $result['values']['s_email'] ?? null);

harness_section('a dependent field on a table store is left alone, not blanked');

// A preference simply never gets a key; a column always exists, so the only two answers are
// to write it or to leave it. It is left: blanking a value because a checkbox elsewhere on
// the page is off is data loss dressed up as a save.
SettingsPageRegistry::instance()->register('kwdep', array(
    'title'  => 'Keyword with a master',
    'menu'   => '',
    'store'  => array('table' => 't_keyword_block', 'pk' => 'pk_i_id'),
    'fields' => array(
        array('type' => 'checkbox', 'name' => 'b_substring', 'label' => 'Substring'),
        array('type' => 'text', 'name' => 's_keyword', 'label' => 'Keyword', 'depends' => 'b_substring'),
        array('type' => 'text', 'name' => 'dt_date', 'label' => 'Date'),
    ),
));

$result = post('kwdep', array(
    'b_substring' => '1',
    's_keyword'   => 'master-on',
    'dt_date'     => '2026-02-01 00:00:00',
));
pin('with the master on the page saves cleanly', array(), $result['errors']);
$depId  = (int)$result['id'];
$stored = row($admin, 't_keyword_block', $depId);
pin('and the dependent field lands in its column', 'master-on', $stored['s_keyword'] ?? null);

$result = post('kwdep', array('s_keyword' => 'ignored', 'dt_date' => '2026-02-01 00:00:00'), $depId);
pin('with the master off the save is still clean', array(), $result['errors']);
$stored = row($admin, 't_keyword_block', $depId);
pin('the master column is written', '0', $stored['b_substring'] ?? null);
pin('and the dependent column keeps its previous value', 'master-on', $stored['s_keyword'] ?? null);

$result = post('kwdep', array(
    'b_substring' => '1',
    's_keyword'   => 'master-on-again',
    'dt_date'     => '2026-02-01 00:00:00',
), $depId);
pin('switching the master back on writes it again', array(), $result['errors']);
$stored = row($admin, 't_keyword_block', $depId);
pin('with the submitted value', 'master-on-again', $stored['s_keyword'] ?? null);
pin('and the master back on', '1', $stored['b_substring'] ?? null);

harness_section('a value no column can hold is stored as nothing');

// Nothing declared on a table store expands into an array today, so the only way one gets
// here is a before_save listener handing one back. (string) on an array is the literal
// 'Array' plus a warning, which is a column full of nonsense rather than an empty one.
SettingsPageRegistry::instance()->register('arrayed', array(
    'title'  => 'Arrayed',
    'menu'   => '',
    'store'  => array('table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    'fields' => array(
        array('type' => 'text', 'name' => 's_name', 'label' => 'Name'),
        array('type' => 'text', 'name' => 's_ip', 'label' => 'IP'),
    ),
));
osc_add_filter('admin_form_before_save', static function ($values, $pageId) {
    if ($pageId === 'arrayed') {
        $values['s_ip'] = array('10.4.0.1', '10.4.0.2');
    }

    return $values;
});

$result = post('arrayed', array('s_name' => 'Arrayed', 's_ip' => '10.4.0.0'));
pin('the save is clean for the write-only field', array(), $result['errors']);
$stored = row($admin, 't_ban_rule', (int)$result['id']);
pin('the scalar field is stored as submitted', 'Arrayed', $stored['s_name'] ?? null);
pin('and the column that was handed an array is empty, not the word Array', '', $stored['s_ip'] ?? null);

harness_section('a key the read path cannot parse reads as no row');

// The write path refuses these outright, but a read cannot: a bookmarked ?id=abc has to
// draw the empty form rather than a white screen. Both answers say the same thing -- that
// key names no row -- and neither of them is the last row that happened to be written.
foreach (array('abc', ' ' . $id . ' ', $id . 'abc', '0', '-1', 'en_US') as $key) {
    $values = osc_settings_values('rule', $key);
    pin('every field reads its declared default for ' . describe($key), '', $values['s_name'] ?? null);
    pin('one at a time as well for ' . describe($key), '', osc_settings_value('rule', 's_ip', $key));
}

harness_section('the generic controller refuses a table-backed page');

// A table-backed page has no row until a controller supplies one, so the generic
// controller cannot serve it: with no key it inserts a copy on every save, and with a key
// out of the request it is the section above all over again.
check(
    'a table-backed page is recognisable before a save is attempted',
    StoreFactory::isTable(SettingsPageRegistry::instance()->get('rule'))
);
check(
    'and a preference page is not',
    !StoreFactory::isTable(SettingsPageRegistry::instance()->get('prefs'))
);

// Driving the controller, not reading it: a guard that is present in the source and never
// taken is the same install as no guard at all.
$before = rows($admin, 't_ban_rule');
$driven = drive('rule', array('s_name' => 'Through the controller', 's_ip' => '10.5.0.1'));
pin(
    'the admin is told the page needs a controller of its own',
    array(array('error', 'That settings page saves into a table, which needs a controller of its own')),
    $driven['flashes']
);
pin(
    'and is sent back to the settings index',
    array('https://example.test/oc-admin/index.php?page=settings'),
    $driven['redirects']
);
pin('no row was written', $before, rows($admin, 't_ban_rule'));
pin('nothing holds what the submission said', null, row_id_by($admin, 't_ban_rule', 's_name', 'Through the controller'));
pin('no effect ran', array(), effects());
pin('and nothing was drawn before the refusal', '', $driven['drawn']);

// The control, without which the guard could refuse every page and still look right.
$driven = drive('prefs', array('s_name' => 'Through the controller'));
pin('the same controller serves a preference page', array(array('ok', 'Settings have been updated')), $driven['flashes']);
pin('writing its value', 'Through the controller', Preference::newInstance()->get('s_name', 'prefs'));
pin('and sending the admin back to the page', array(osc_settings_page_url('prefs')), $driven['redirects']);

// The cheap extra: the guard has to be asked before the action switch, or the save has
// already run by the time the answer arrives.
$controller = file_get_contents(
    ABS_PATH . 'oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsCustom.php'
);
$asks     = strpos((string)$controller, 'StoreFactory::isTable(');
$dispatch = strpos((string)$controller, 'switch ($this->action)');
check('the one generic controller asks', $asks !== false, 'it does not consult the store at all');
check(
    'and asks before it dispatches a save',
    $asks !== false && $dispatch !== false && $asks < $dispatch,
    'the check is after the action switch, so the save has already run'
);

harness_section('the shared view carries no row key into the form');

// The fix that must not be made: a hidden primary key here is a row number in the request,
// and every row of the bound table is then writable by anyone who can reach the page. The
// key is interpolated from the page spec, so its name never appears in the view's source --
// the proof has to be the markup the view emits.
$spec  = SettingsPageRegistry::instance()->get('rule');
$html  = draw($spec, osc_settings_values('rule', $new));
$names = input_names($html);
check('the view drew a form', strpos($html, '<form ') !== false, $html);
pin(
    'and every request key in it is the route or a declared field',
    array('action', 'id', 'page', 's_ip', 's_name'),
    $names
);
check(
    'so the primary key the store addresses rows by is not among them',
    !in_array($spec['store']['pk'], $names, true),
    implode(', ', $names)
);
pin('the id it does carry names the page', 'rule', $spec['id']);

// Both halves of the drawing: the view, and the renderer it hands the page to. A scan of
// the view alone stopped meaning anything the moment the form moved out of it.
$drawing = '';
foreach (array(
    'oc-includes/osclass/gui/admin/settings-page.php',
    'oc-includes/osclass/classes/admin/ui/SettingsForm.php',
) as $rel) {
    $drawing .= (string)file_get_contents(ABS_PATH . $rel);
}
check(
    'and nothing that draws the form reaches for the store spec at all',
    preg_match('/\[\s*[\'"]store[\'"]\s*\]/', $drawing) === 0,
    'the form is drawn from the store spec, which is one step from emitting the key it names'
);

exit(harness_result());
