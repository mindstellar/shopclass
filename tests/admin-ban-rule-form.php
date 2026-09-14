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
 * The ban-rule screen: the first core screen whose write path is a declaration rather
 * than a hand-written Params::getParam / validate / DAO block.
 *
 * What this is guarding, in the order it would go wrong:
 *
 *  - The row a table-backed save writes must be the one the controller named and no
 *    other. Taking it from the submission is the bug the store was built to make
 *    impossible, and it is invisible from the screen: the form looks the same whether the
 *    key came from the route or from a field somebody added to the POST. So a rule is
 *    edited with another rule's key in the body, and the other rule is read back.
 *  - A key that is not a row -- absent, zero, negative, spaced, half a number, or a rule
 *    that has since been deleted -- must write nothing at all. The dangerous shape is not
 *    an error page but a silent insert: refuse the key, fall through to the save, and the
 *    edit screen quietly creates rules instead of changing them.
 *  - The rule spanning two fields ("both cannot be empty") has no field to live on, so it
 *    lives on the page. Lost in the migration, the table fills with rules that match
 *    nobody and the admin is told they saved.
 *  - The declared path is meant to be strictly better in one visible way: a rejected save
 *    redraws what was typed instead of discarding it. That is asserted, not assumed.
 *  - And an update that changes nothing affects zero rows, which is a save. Reading it as
 *    a failure is the bug the keyword-block controller still carries a comment about.
 *  - What lands in the row has to be what landed before. The hand-written block read the
 *    fields through Params::getParam(), which strips every tag; the declared path reads
 *    the request raw, so a migration that skips the stripping changes the table quietly.
 *  - A value longer than its column used to be stored as much of it as fitted, under a
 *    success message: STRICT_TRANS_TABLES is off unless OSC_DB_STRICT_MODE says otherwise.
 *
 * The guards at the foot are the plan's "no regression to hand-rolled". Each says which
 * kind it is: a behavioural assertion drives the code and reads what it did, a source scan
 * only reads the file. Scans are the cheap extra, never the proof -- a grep for a call is
 * evaded by a one-character edit, so wherever the same fact can be observed it is
 * observed instead.
 *
 * DB-backed: the row is the point, so rows are read back with raw SQL on the admin
 * connection rather than through the code under test.
 *
 * Usage:  php tests/admin-ban-rule-form.php
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$admin = scratchdb_session('osc_ban_rule_form');

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

    /** The admin theme's view for this screen, drawn where the real one would draw it. */
    public function doView($view)
    {
        include ABS_PATH . 'oc-admin/themes/modern/' . $view;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminUsers.php';

use mindstellar\admin\form\BanRuleForm;
use mindstellar\settings\SettingsPageRegistry;

$GLOBALS['effects']   = array();
$GLOBALS['flashes']   = array();
$GLOBALS['redirects'] = array();

osc_add_hook('admin_form_after_save', static function ($pageId, $values, $id) {
    $GLOBALS['effects'][] = array($pageId, $id);
});

// The last thing the save pipeline does before handing the values to the store, so a
// request that got this far reached the store. Recorded so the controller's own checks
// can be pinned apart from the store's: both refuse a key with no row behind it, and an
// assertion about the message alone still passes when only one of the two is left.
$GLOBALS['attempts'] = array();
osc_add_filter('admin_form_before_save', static function ($values, $pageId) {
    $GLOBALS['attempts'][] = $pageId;

    return $values;
});

/**
 * Put a request in front of the real controller the way a browser would, and hand back
 * what it did with it.
 */
function drive(string $action, array $fields = array(), array $query = array()): array
{
    $_GET  = $query + array('page' => 'users', 'action' => $action);
    $_POST = $fields;
    Params::init();
    $GLOBALS['effects']    = array();
    $GLOBALS['flashes']    = array();
    $GLOBALS['redirects']  = array();
    $GLOBALS['csrfChecks'] = array();
    $GLOBALS['attempts']   = array();

    ob_start();
    $controller = new CAdminUsers();
    $controller->doModel();
    $drawn = (string)ob_get_clean();

    return array(
        'flashes'   => $GLOBALS['flashes'],
        'redirects' => $GLOBALS['redirects'],
        'effects'   => $GLOBALS['effects'],
        'csrf'      => $GLOBALS['csrfChecks'],
        'attempts'  => $GLOBALS['attempts'],
        'drawn'     => $drawn,
    );
}

/** One row by primary key, read with raw SQL so the code under test is not asked to agree. */
function row(mysqli $admin, int $id): array
{
    $res = $admin->query('SELECT * FROM ' . DB_TABLE_PREFIX . 't_ban_rule WHERE pk_i_id = ' . $id);
    $out = $res ? ($res->fetch_assoc() ?: array()) : array();
    if ($res) {
        $res->free();
    }

    return $out;
}

function rows(mysqli $admin): int
{
    $res = $admin->query('SELECT COUNT(*) c FROM ' . DB_TABLE_PREFIX . 't_ban_rule');
    $n   = $res ? (int)($res->fetch_assoc()['c'] ?? 0) : -1;
    if ($res) {
        $res->free();
    }

    return $n;
}

/** A rule put in place with raw SQL, so the fixture owes nothing to the code under test. */
function seed_rule(mysqli $admin, string $name, string $ip, string $email): int
{
    return seed_exec(
        $admin,
        'INSERT INTO ' . DB_TABLE_PREFIX . 't_ban_rule (s_name, s_ip, s_email) VALUES (?, ?, ?)',
        'sss',
        array($name, $ip, $email)
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

/** The value one text control was drawn with, or null when the control is not there. */
function control_value(string $html, string $name): ?string
{
    return preg_match(
        '/<input type="text" id="field-' . preg_quote($name, '/') . '" name="'
        . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/',
        $html,
        $m
    ) ? $m[1] : null;
}

/** How a refused key reads in an assertion label. Not the harness's describe(). */
function key_label($value): string
{
    return is_array($value) ? 'an array-valued key' : "'" . (string)$value . "'";
}

$pageId = BanRuleForm::register();

harness_section('the screen is declared once, and declares the table it is');

pin('the page has an id of its own', 'core.ban_rule', $pageId);
$spec = SettingsPageRegistry::instance()->get($pageId);
pin(
    'bound to a row of t_ban_rule',
    array('type' => 'table', 'table' => 't_ban_rule', 'pk' => 'pk_i_id'),
    $spec['store']
);
$fields = SettingsPageRegistry::instance()->fields($pageId);
pin('declaring the three fields the screen has always had', array('s_name', 's_ip', 's_email'), array_keys($fields));
foreach ($fields as $name => $field) {
    pin($name . ' is a text field', 'text', $field['type']);
}
pin('reached from the ban-rule list rather than from a menu', '', $spec['menu']);
pin('and open to an administrator, which is all page=users admits', 'administrator', $spec['capability']);

// A declared column that the table does not have registers cleanly and fails at the first
// save. The schema is right here, so it is asked rather than assumed.
$columns = array();
$res     = $admin->query('SHOW COLUMNS FROM ' . DB_TABLE_PREFIX . 't_ban_rule');
while ($res && ($column = $res->fetch_assoc())) {
    $columns[] = $column['Field'];
}
if ($res) {
    $res->free();
}
pin('the table is the shape the page declares', array('pk_i_id', 's_name', 's_ip', 's_email'), $columns);
foreach (array_keys($fields) as $name) {
    check('the column ' . $name . ' writes to is one the table has', in_array($name, $columns, true));
}

harness_section('adding a rule');

$before = rows($admin);
$driven = drive('create_ban_rule_post', array(
    's_name'  => 'Spam ring',
    's_ip'    => '192.168.10-20.*',
    's_email' => '*@BadSite.com',
));
pin('one rule more than there was', $before + 1, rows($admin));
pin('the admin is told it saved', array(array('ok', 'Rule saved correctly')), $driven['flashes']);
pin(
    'and an added rule is sent back to the list',
    array('https://example.test/oc-admin/index.php?page=users&action=ban'),
    $driven['redirects']
);
pin('the CSRF check ran, for this action', array('create_ban_rule_post'), $driven['csrf']);
pin('nothing was drawn, because it redirected', '', $driven['drawn']);

$new = (int)($driven['effects'][0][1] ?? 0);
check('the effects were handed the key of the row that was written', $new > 0, var_export($driven['effects'], true));
pin('and the page they name is this one', array(array('core.ban_rule', $new)), $driven['effects']);
$stored = row($admin, $new);
pin('the name is stored as submitted', 'Spam ring', $stored['s_name'] ?? null);
pin('the IP rule too', '192.168.10-20.*', $stored['s_ip'] ?? null);
// The declaration's own sanitiser: addresses are matched lower-cased, so they are stored
// that way, exactly as the hand-written controller did with strtolower().
pin('and the address is lower-cased on the way in', '*@badsite.com', $stored['s_email'] ?? null);

harness_section('what lands in the row is what landed before');

// The old controller read these fields through Params::getParam(), whose default runs
// HTMLPurifier over the value and takes every tag out, contents and all. The declared path
// reads the request raw, so the screen declares the same stripping: a migration that
// quietly changes what is in the table is not a migration, whatever the page does on the
// way out. Both halves are measured here rather than transcribed.
$count  = rows($admin);
$driven = drive('create_ban_rule_post', array(
    's_name'  => '<script>alert(1)</script> spam  ',
    's_ip'    => '10.5.5.5',
    's_email' => '',
));
$purified = (int)($driven['effects'][0][1] ?? 0);
$wasStored = Params::getParam('s_name');
pin('the old path stripped the tag and what it contained', ' spam  ', $wasStored);
pin('the declared path stores the same text', 'spam', row($admin, $purified)['s_name'] ?? null);
pin('differing from the old row only in the space around it', trim($wasStored), row($admin, $purified)['s_name']);
pin('and it is one row, saved', $count + 1, rows($admin));

// An ampersand was escaped on the way in before this screen was declared, and still is:
// what changed would be visible in the table, so it is asked of the table. Storing it
// pre-escaped is what keeps the value inert for anything that prints it without escaping,
// and osc_esc_html() is built to carry an already-escaped entity through untouched -- a
// row holding the bare character renders a raw "&" from the reason "Q&A; Session".
$driven = drive('create_ban_rule_post', array(
    's_name'  => 'Q&A; Session',
    's_ip'    => '10.5.5.6',
    's_email' => '',
));
$stored = row($admin, (int)($driven['effects'][0][1] ?? 0))['s_name'] ?? null;
pin('an ampersand is stored the way the old path stored it', Params::getParam('s_name'), $stored);
pin('which is escaped', 'Q&amp;A; Session', $stored);
pin('and comes back out of osc_esc_html() as the admin typed it', 'Q&amp;A; Session', osc_esc_html($stored));
check(
    'while the bare character would not survive the same trip',
    osc_esc_html('Q&A; Session') === 'Q&A; Session',
    osc_esc_html('Q&A; Session')
);

harness_section('a value the column cannot hold is refused, not cut short');

// STRICT_TRANS_TABLES is off unless OSC_DB_STRICT_MODE says otherwise, so an over-long
// value used to be stored as its first 250 characters under "Rule saved correctly" -- the
// end of it gone, with nothing said. The column widths are declared, so the form comes back
// to be shortened instead.
$count  = rows($admin);
$long   = str_repeat('a', 251);
$driven = drive('create_ban_rule_post', array(
    's_name'  => $long,
    's_ip'    => '10.7.7.7',
    's_email' => '',
));
pin('nothing was inserted for an over-long field', $count, rows($admin));
pin(
    'the admin is told which field is too long, and how long it may be',
    array(array('error', 'Ban name / Reason must be 250 characters or fewer')),
    $driven['flashes']
);
pin('no effect ran for the over-long field', array(), $driven['effects']);
pin('the admin was not sent away for the over-long field', array(), $driven['redirects']);
check('the form was drawn again for the over-long field', strpos($driven['drawn'], '<form ') !== false, $driven['drawn']);
pin('with all 251 characters still in it, to be shortened', $long, control_value($driven['drawn'], 's_name'));

// The boundary, because a cap that is one out rejects a value the column holds fine.
$driven = drive('create_ban_rule_post', array(
    's_name'  => str_repeat('a', 250),
    's_ip'    => '10.7.7.8',
    's_email' => '',
));
pin('exactly 250 characters is still saved', array(array('ok', 'Rule saved correctly')), $driven['flashes']);
pin('and stored whole', str_repeat('a', 250), row($admin, (int)($driven['effects'][0][1] ?? 0))['s_name'] ?? null);

// The cap is the column's, so it is measured on what reaches the column and not on what was
// typed. Escaping four characters out of one puts 249 a's and an ampersand over a 250-wide
// column, and the rule refuses it rather than letting MySQL cut it: that is the whole point
// of declaring the width, and the reason the control is given no maxlength to promise
// otherwise.
$count  = rows($admin);
$driven = drive('create_ban_rule_post', array(
    's_name'  => str_repeat('a', 249) . '&',
    's_ip'    => '10.7.7.9',
    's_email' => '',
));
pin(
    'the length rule counts the stored value, not the typed one',
    array(array('error', 'Ban name / Reason must be 250 characters or fewer')),
    $driven['flashes']
);
pin('so nothing was inserted', $count, rows($admin));

// Each field is held to its own column, not to one width for the screen.
$count  = rows($admin);
$driven = drive('create_ban_rule_post', array(
    's_name'  => 'Long IP rule',
    's_ip'    => str_repeat('9', 51),
    's_email' => '',
));
pin('the IP rule is held to the 50 its column has', array(array('error', 'IP rule must be 50 characters or fewer')), $driven['flashes']);
pin('and nothing was inserted for it either', $count, rows($admin));

harness_section('editing the row the route names');

$count  = rows($admin);
$driven = drive('edit_ban_rule_post', array(
    's_name'  => 'Spam ring (wider)',
    's_ip'    => '192.168.*.*',
    's_email' => '*@badsite.com',
), array('id' => (string)$new));
pin('no second row: the edit updated one', $count, rows($admin));
pin('the admin is told it updated', array(array('ok', 'Rule updated correctly')), $driven['flashes']);
pin(
    'and an edited rule is sent back to the list',
    array('https://example.test/oc-admin/index.php?page=users&action=ban'),
    $driven['redirects']
);
pin('the effects name the row that was already there', array(array('core.ban_rule', $new)), $driven['effects']);
$stored = row($admin, $new);
pin('the name changed', 'Spam ring (wider)', $stored['s_name'] ?? null);
pin('the IP rule changed', '192.168.*.*', $stored['s_ip'] ?? null);
pin('the key did not', $new, (int)($stored['pk_i_id'] ?? 0));

harness_section('an unchanged save is a save');

$driven = drive('edit_ban_rule_post', array(
    's_name'  => 'Spam ring (wider)',
    's_ip'    => '192.168.*.*',
    's_email' => '*@badsite.com',
), array('id' => (string)$new));
// An update that changed nothing affects zero rows. Reading that as a failure is the bug
// the keyword-block controller carries a comment about: the save worked and the admin is
// told it did not, so they save again, and again.
pin('the admin is told it updated, not that nothing happened', array(array('ok', 'Rule updated correctly')), $driven['flashes']);
pin('and it still counts as one save for the effects', 1, count($driven['effects']));

harness_section('the row is the one the controller passed, not one the body names');

$other       = seed_rule($admin, 'Somebody else', '10.0.0.1', 'other@example.test');
$otherBefore = row($admin, $other);
$count       = rows($admin);

// The screen's row is its 'id' route parameter, which the controller parses, checks
// against the table and hands to the save. What must never happen is the store picking
// the primary key out of the submission instead -- it did once, and every row of a bound
// table was then writable by anyone who could reach the page. A field named after the key
// is therefore just an unread field.
$driven = drive('edit_ban_rule_post', array(
    's_name'   => 'Rewritten',
    's_ip'     => '172.16.*.*',
    's_email'  => '*@badsite.com',
    'pk_i_id'  => (string)$other,
    'PK_I_ID'  => (string)$other,
), array('id' => (string)$new));
pin('the rule the body named is untouched, to the byte', $otherBefore, row($admin, $other));
pin('the rule the controller named is the one that changed', 'Rewritten', row($admin, $new)['s_name'] ?? null);
pin('no row was added on the way', $count, rows($admin));
pin('and the effects name the row the controller passed', array(array('core.ban_rule', $new)), $driven['effects']);

harness_section('a key that is not a row writes nothing');

// The shape to be afraid of is not the error page: it is the silent insert. Refuse the
// key, fall through to the save, and an edit screen creates rules instead of changing
// them. Every case here therefore checks the row count as well as the message.
$gone = seed_rule($admin, 'Deleted before the save', '10.0.0.9', 'gone@example.test');
$admin->query('DELETE FROM ' . DB_TABLE_PREFIX . 't_ban_rule WHERE pk_i_id = ' . $gone);

foreach (array('', '0', '-1', 'abc', ' ' . $new . ' ', $new . 'abc', (string)$gone, array((string)$new)) as $key) {
    $count  = rows($admin);
    $before = row($admin, $new);
    $driven = drive('edit_ban_rule_post', array(
        's_name'  => 'Should not land',
        's_ip'    => '10.10.10.10',
        's_email' => '*@nowhere.test',
    ), array('id' => $key));

    pin('no row is written for ' . key_label($key), $count, rows($admin));
    pin('and no row is changed for ' . key_label($key), $before, row($admin, $new));
    pin(
        'the admin is told the rule is gone for ' . key_label($key),
        array(array('error', 'That ban rule no longer exists')),
        $driven['flashes']
    );
    pin(
        'and sent back to the list for ' . key_label($key),
        array('https://example.test/oc-admin/index.php?page=users&action=ban'),
        $driven['redirects']
    );
    pin('no effect ran for ' . key_label($key), array(), $driven['effects']);
    // The store refuses a key with no row behind it as well, so the message and the
    // redirect above read the same whether the controller checked or not. This is the
    // controller's half on its own: it turned the request away without the store being
    // asked anything.
    pin('and the store was never reached for ' . key_label($key), array(), $driven['attempts']);
}

// The diagonal the loop does not cover: nothing on the route, and a key in the body
// instead. Params merges the submission over the query string, so a controller that falls
// back to the body when the route names no rule reads this as an edit of a rule the
// submitter chose, and says it saved.
$otherBefore = row($admin, $other);
$count       = rows($admin);
$driven      = drive('edit_ban_rule_post', array(
    's_name'  => 'Named by the body alone',
    's_ip'    => '10.11.12.13',
    's_email' => '*@nowhere.test',
    'pk_i_id' => (string)$other,
));
pin('a key carried only in the body names no rule: that rule is untouched, to the byte', $otherBefore, row($admin, $other));
pin('and no rule was created in its place', $count, rows($admin));
pin(
    'the submission is refused',
    array(array('error', 'That ban rule no longer exists')),
    $driven['flashes']
);
pin(
    'and a submission for a missing row is sent back to the list',
    array('https://example.test/oc-admin/index.php?page=users&action=ban'),
    $driven['redirects']
);
pin('nothing was saved', array(), $driven['effects']);
pin('and the store was never reached', array(), $driven['attempts']);

// The same key on the way in, before anything is submitted: an edit form drawn from a key
// with no row behind it would show the declared defaults and then insert on save.
$driven = drive('edit_ban_rule', array(), array('id' => 'abc'));
pin('the edit form is not drawn for a key that is not a row', '', $driven['drawn']);
// A decimal key whose row has been deleted: nothing but the controller's own lookup can
// refuse this one, because drawing a form reaches no store at all.
pin('nor for a key whose row has been deleted', '', drive('edit_ban_rule', array(), array('id' => (string)$gone))['drawn']);
pin(
    'the admin is told why',
    array(array('error', 'That ban rule no longer exists')),
    $driven['flashes']
);
pin(
    'and a draw for a missing row is sent back to the list',
    array('https://example.test/oc-admin/index.php?page=users&action=ban'),
    $driven['redirects']
);

harness_section('what the screen requires, and what it does not');

// Only the pair matters: a rule with no name at all is one the screen has always taken,
// and marking either field required would reject submissions that save today.
$count  = rows($admin);
$driven = drive('create_ban_rule_post', array(
    's_name'  => '',
    's_ip'    => '10.2.3.4',
    's_email' => '',
));
pin('a rule with no name is still saved', array(array('ok', 'Rule saved correctly')), $driven['flashes']);
pin('and it is a row', $count + 1, rows($admin));
$nameless = (int)($driven['effects'][0][1] ?? 0);
pin('with the name left empty', '', row($admin, $nameless)['s_name'] ?? null);
pin('and the address left empty', '', row($admin, $nameless)['s_email'] ?? null);

harness_section('a rule matching nobody is refused, with what was typed still on screen');

$count  = rows($admin);
$driven = drive('create_ban_rule_post', array(
    's_name'  => 'Neither one nor the other',
    's_ip'    => '',
    's_email' => '',
));
pin('nothing was inserted for a rule that names neither', $count, rows($admin));
pin(
    'and the reason is the one this screen has always given',
    array(array('error', 'Both rules can not be empty')),
    $driven['flashes']
);
pin('no effect ran for the empty rule', array(), $driven['effects']);
pin('the admin was not sent away for the empty rule', array(), $driven['redirects']);
// The improvement the declared path brings: a rejected save is redrawn with what was
// typed, instead of being thrown away by a redirect to the list.
check('the form was drawn again for the empty rule', strpos($driven['drawn'], '<form ') !== false, $driven['drawn']);
pin('carrying the name that was rejected', 'Neither one nor the other', control_value($driven['drawn'], 's_name'));
pin('and still posting as an add', 'create_ban_rule_post', hidden($driven['drawn'], 'action'));

$before = row($admin, $new);
$driven = drive('edit_ban_rule_post', array(
    's_name'  => 'Edited but empty',
    's_ip'    => '',
    's_email' => '',
), array('id' => (string)$new));
pin('an edit is refused the same way, byte for byte in the row', $before, row($admin, $new));
pin(
    'with the same reason',
    array(array('error', 'Both rules can not be empty')),
    $driven['flashes']
);
pin('the redrawn form still edits the same rule', (string)$new, hidden($driven['drawn'], 'id'));
pin('and still posts as an edit', 'edit_ban_rule_post', hidden($driven['drawn'], 'action'));
pin('carrying what was typed', 'Edited but empty', control_value($driven['drawn'], 's_name'));

harness_section("the page's rule is handed the row it is deciding about");

// A rule spanning fields is handed ($values, $pageId, $id). Without the third argument the
// commonest entity-level rule -- "that name is taken, except on this row" -- cannot be
// written at all, and the second position cannot be changed once a plugin has declared a
// callback taking it. Both are asserted: what arrives, and that it is usable.
$seen = array();
osc_admin_form('core.ban_rule.probe')
    ->title('Ban rule probe')
    ->menu('')
    ->store(BanRuleForm::TABLE, BanRuleForm::PK)
    ->onValidate(static function (array $values, string $pageId, $id) use (&$seen) {
        $seen[] = array($pageId, $id, $values['s_name'] ?? null);

        return null;
    })
    ->text('s_name', 'Ban name / Reason')
    ->register();

/** A declared page saved directly, so the argument the caller passes is the one asserted. */
function save_page(string $pageId, array $fields, $id = null): array
{
    $_GET  = array();
    $_POST = $fields;
    Params::init();

    return osc_settings_save($pageId, $id);
}

$saved = save_page('core.ban_rule.probe', array('s_name' => 'probe insert'));
pin('an insert tells the rule there is no row yet', array(array('core.ban_rule.probe', null, 'probe insert')), $seen);

$probe = (int)$saved['id'];
$seen  = array();
save_page('core.ban_rule.probe', array('s_name' => 'probe edit'), $probe);
pin('an edit tells it which row, as the integer it was given', array(array('core.ban_rule.probe', $probe, 'probe edit')), $seen);
pin('and that is the row that was written', 'probe edit', row($admin, $probe)['s_name'] ?? null);

// The rule the third argument exists for, written out: a name already on another rule is
// refused, and the same name on the rule that holds it is not.
osc_admin_form('core.ban_rule.unique')
    ->title('Ban rule uniqueness probe')
    ->menu('')
    ->store(BanRuleForm::TABLE, BanRuleForm::PK)
    ->onValidate(static function (array $values, string $pageId, $id) use ($admin) {
        $name = $admin->real_escape_string((string)($values['s_name'] ?? ''));
        $res  = $admin->query(
            'SELECT pk_i_id FROM ' . DB_TABLE_PREFIX . "t_ban_rule WHERE s_name = '" . $name . "'"
        );
        $taken = null;
        while ($res && ($found = $res->fetch_assoc())) {
            if ($id === null || (int)$found['pk_i_id'] !== (int)$id) {
                $taken = 'That name is already used by another rule';
            }
        }
        if ($res) {
            $res->free();
        }

        return $taken;
    })
    ->text('s_name', 'Ban name / Reason')
    ->register();

$count  = rows($admin);
$result = save_page('core.ban_rule.unique', array('s_name' => 'probe edit'));
pin('a name another rule holds is refused on an insert', array('That name is already used by another rule'), $result['errors']);
pin('and no row was added', $count, rows($admin));
$result = save_page('core.ban_rule.unique', array('s_name' => 'probe edit'), $probe);
pin('the same name on the rule that holds it is accepted', array(), $result['errors']);
pin('because the rule could tell the row apart', $probe, $result['id']);

harness_section('what the screen draws');

$driven = drive('edit_ban_rule', array(), array('id' => (string)$new));
$html   = $driven['drawn'];
pin('the CSRF check does not run for a form that is only being drawn', array(), $driven['csrf']);
pin('the page draws exactly one form', 1, substr_count($html, '<form '));
// The key the store addresses rows by is not something the page hands to the browser: the
// route carries the row, and 'id' is the route.
pin(
    'every request key in it is the route or a declared field',
    array('action', 'id', 'page', 's_email', 's_ip', 's_name'),
    input_names($html)
);
check(
    'so the primary key is not among them',
    !in_array('pk_i_id', input_names($html), true),
    implode(', ', input_names($html))
);
pin('it posts to the users page', 'users', hidden($html, 'page'));
pin('at the edit action', 'edit_ban_rule_post', hidden($html, 'action'));
pin('for this rule', (string)$new, hidden($html, 'id'));
pin('showing the stored name', 'Rewritten', control_value($html, 's_name'));
pin('the stored IP rule', '172.16.*.*', control_value($html, 's_ip'));
pin('the stored address rule', '*@badsite.com', control_value($html, 's_email'));
check('and the button says what it does', strpos($html, '>Update rule</button>') !== false, $html);
// The screen's own chrome, which the migration had to keep: the wrapper the theme styles,
// and the browser title the page contributes. Filters accumulate for the life of a
// process, so what is read here is the newest prefix -- the screen that was drawn last.
check('the theme wrapper is still there', strpos($html, '<div class="settings-user">') !== false, $html);
$title = osc_apply_filter('admin_title', 'Shopclass admin panel');
check('the browser title is the screen\'s, ahead of the site\'s', strpos($title, 'Edit rule &raquo; ') === 0, $title);
check(
    'and the site title is still on the end of it',
    substr($title, -strlen('Shopclass admin panel')) === 'Shopclass admin panel',
    $title
);

$driven = drive('create_ban_rule');
$html   = $driven['drawn'];

// The migration's own promise, held to the byte: this is what the hand-written view
// emitted, with the indentation its literal newlines put between the tags taken out --
// core's renderer emits the elements back to back. Every attribute, class, id, order and
// escaping decision is the one that shipped, so a difference here is the screen changing
// rather than the plumbing behind it.
$form = substr($html, strpos($html, '<form '), strpos($html, '</form>') + 7 - strpos($html, '<form '));
pin(
    'the add screen draws the markup it drew before the migration',
    '<form action="https://example.test/oc-admin/index.php" method="post" name="register">'
    . '<input type="hidden" name="page" value="users"/>'
    . '<input type="hidden" name="action" value="create_ban_rule_post"/>'
    . '<fieldset><div class="form-horizontal">'
    . '<div class="form-row"><div class="form-label"><label for="field-s_name">Ban name / Reason</label></div>'
    . '<div class="form-controls"><input type="text" id="field-s_name" name="s_name" class="input-text field-text" value="" /></div></div>'
    . '<div class="form-row"><div class="form-label"><label for="field-s_ip">IP rule</label></div>'
    . '<div class="form-controls"><input type="text" id="field-s_ip" name="s_ip" class="input-text field-text" value="" />'
    . '<div class="help-box">(e.g. 192.168.10-20.*)</div></div></div>'
    . '<div class="form-row"><div class="form-label"><label for="field-s_email">E-mail rule</label></div>'
    . '<div class="form-controls"><input type="text" id="field-s_email" name="s_email" class="input-text field-text" value="" />'
    . '<div class="help-box">(e.g. *@badsite.com, *@subdomain.badsite.com, *@*badsite.com)</div></div></div>'
    . '<div class="form-actions"><button type="submit" class="btn btn-sm btn-submit">Add new ban rule</button></div>'
    . '</div></fieldset></form>',
    $form
);
pin('the add form posts to the add action', 'create_ban_rule_post', hidden($html, 'action'));
pin('carries no row key at all', null, hidden($html, 'id'));
pin('and its fields are empty', '', control_value($html, 's_name'));
check('its button says what it does', strpos($html, '>Add new ban rule</button>') !== false, $html);
$title = osc_apply_filter('admin_title', 'Shopclass admin panel');
check(
    'and the browser title follows the screen, rather than being fixed',
    strpos($title, 'Add new ban rule &raquo; ') === 0,
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
$driven = drive('edit_ban_rule_post', array(
    's_name'  => 'posted directly',
    's_ip'    => '172.16.*.*',
    's_email' => '*@badsite.com',
), array('id' => (string)$new));
osc_remove_filter('admin_form_before_save', $listener);
pin('the row holds what the pipeline made of the submission', 'through the pipeline', row($admin, $new)['s_name'] ?? null);
pin('and the save still reported success', array(array('ok', 'Rule updated correctly')), $driven['flashes']);

harness_section('the guard: no view of a declared page rolls its own form');

// Behavioural where it can be: the ban-rule view above drew one <form>, and every request
// key in it came from core. What a scan adds is the other half -- that the view has no
// second, hand-written form waiting for a branch these assertions did not take.
$declaredViews = array(
    'oc-includes/osclass/gui/admin/settings-page.php',
    'oc-admin/themes/modern/users/ban_frm.php',
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
pin('the add form posts through a CSRF check', array('create_ban_rule_post'), drive('create_ban_rule_post', array(
    's_name'  => 'CSRF probe',
    's_ip'    => '10.9.9.9',
    's_email' => '',
))['csrf']);
pin('so does the edit form', array('edit_ban_rule_post'), drive('edit_ban_rule_post', array(
    's_name'  => 'CSRF probe',
    's_ip'    => '10.9.9.9',
    's_email' => '',
), array('id' => (string)$new))['csrf']);
pin('and neither draw asks for one', array(), array_merge(
    drive('create_ban_rule')['csrf'],
    drive('edit_ban_rule', array(), array('id' => (string)$new))['csrf']
));

// Scan, and only a scan: the absence of a read cannot be observed from outside. The
// filter assertion above is what proves the values that do land came from the pipeline.
$controller = (string)file_get_contents(
    ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminUsers.php'
);
foreach (array_keys($fields) as $name) {
    check(
        'scan: the controller never reads ' . $name . ' out of the request',
        strpos($controller, "getParam('" . $name . "')") === false
            && strpos($controller, "getParamString('" . $name . "')") === false,
        'a declared field read by hand is the block this migration removed'
    );
}
check(
    'scan: and never writes the rule through the model either',
    preg_match('/BanRule::newInstance\(\)->(insert|update)\(/', $controller) === 0,
    'the store owns the write, through osc_db_table()'
);
check(
    'scan: while the delete path, which is not part of the declared form, still uses it',
    strpos($controller, 'deleteByPrimaryKey') !== false,
    'the scan above would pass just as well on a controller with no ban rules in it at all'
);

exit(harness_result());
