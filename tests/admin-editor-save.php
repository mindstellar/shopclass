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
 * What the two editors' saves write, column by column.
 *
 * The render half is pinned in tests/admin-editor-fields.php; this is the other end of the
 * same contract. A posted name only matters because a controller reads it back and puts it
 * in a column, and that half fails silently: a field the form stopped sending is read as
 * empty, the save still reports success, and the column keeps whatever it held before.
 *
 * Both listing locales and both page locales are asserted, because the per-locale rows are
 * the part a single-locale check cannot see -- a save that writes the active locale over
 * every row looks correct on an English-only install.
 *
 * The controllers are driven with a request in front of them rather than called piecemeal,
 * so a guard that no branch reaches counts for nothing. Rows are read back with raw SQL on
 * the admin connection, never through the code under test.
 *
 * Usage:  php tests/admin-editor-save.php
 * Env:    DRIFT_DB_HOST DRIFT_DB_PORT DRIFT_DB_USER DRIFT_DB_PASS
 *         (default 127.0.0.1:33061 root/root -- the throwaway container)
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$admin = scratchdb_session('osc_admin_editor_save');

if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}
if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'http://localhost/');
}
if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 60);
}
if (!defined('OSC_DEBUG')) {
    define('OSC_DEBUG', false);
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
if (!function_exists('osc_base_url')) {
    function osc_base_url($with_index = false)
    {
        return WEB_PATH . ($with_index ? 'index.php' : '');
    }
}
if (!function_exists('osc_admin_base_url')) {
    function osc_admin_base_url($index = false)
    {
        return WEB_PATH . 'oc-admin/index.php';
    }
}
if (!function_exists('_m')) {
    function _m($key)
    {
        return $key;
    }
}
if (!function_exists('osc_is_moderator')) {
    function osc_is_moderator()
    {
        return false;
    }
}
if (!function_exists('osc_register_render_target')) {
    function osc_register_render_target($id, $path)
    {
    }
}
// The page editor refuses an internal name that would shadow a core route; the reserved
// set itself belongs to the theme stack, which no part of this save path needs.
if (!function_exists('osc_theme_view_names')) {
    function osc_theme_view_names(): array
    {
        return array('item', 'search', 'contact', 'login');
    }
}
if (!function_exists('osc_themes_path')) {
    function osc_themes_path()
    {
        return ABS_PATH . 'oc-content/themes/';
    }
}
// Recorded rather than enforced: which actions check the token is asserted below.
$GLOBALS['csrfChecks'] = array();
if (!function_exists('osc_csrf_check')) {
    function osc_csrf_check($die = true)
    {
        $GLOBALS['csrfChecks'][] = Params::getParam('action');

        return true;
    }
}
// The rejected-save path draws the form again: the moderation links it puts above the
// listing carry a token, and the page form offers the registered templates.
if (!function_exists('osc_csrf_token_url')) {
    function osc_csrf_token_url()
    {
        return 'CSRFName=t&CSRFToken=t';
    }
}
if (!function_exists('osc_page_templates')) {
    function osc_page_templates()
    {
        return array();
    }
}
if (!function_exists('osc_add_flash_ok_message')) {
    function osc_add_flash_ok_message($msg, $section = 'pubMessages')
    {
        $GLOBALS['flashes'][] = array('ok', $msg);
    }
}
if (!function_exists('osc_add_flash_error_message')) {
    function osc_add_flash_error_message($msg, $section = 'pubMessages')
    {
        $GLOBALS['flashes'][] = array('error', $msg);
    }
}
if (!function_exists('osc_add_flash_warning_message')) {
    function osc_add_flash_warning_message($msg, $section = 'pubMessages')
    {
        $GLOBALS['flashes'][] = array('warning', $msg);
    }
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hValidate.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
// After hSanitize.php, so its real osc_esc_html() is not shadowed by stubs.php's.
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hLocale.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hBilling.php';
require_once ABS_PATH . 'oc-includes/osclass/formatting.php';
// The page editor's save is a declared form, so the layer that runs one has to be here.
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
require_once ABS_PATH . 'oc-includes/osclass/utils.php';

/** Thrown in place of the exit() a real redirect ends the request with. */
class HarnessRedirect extends RuntimeException
{
}

/**
 * The controller base class, stubbed: a redirect is recorded and then ends the request the
 * way the real one does, and the section permission check has already happened by the time
 * doModel() runs.
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

        throw new HarnessRedirect($url);
    }

    public function _exportVariableToView($key, $value)
    {
        View::newInstance()->_exportVariableToView($key, $value);
    }

    public function doView($view)
    {
        $GLOBALS['views'][] = $view;
    }

    protected function refuseOnDemo($redirectUrl = null)
    {
        return false;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminItems.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminPages.php';

/* ----------------------------------------------------------------------------
 * Fixtures, seeded with raw SQL so they owe nothing to the code under test.
 * ------------------------------------------------------------------------- */

$prefix = DB_TABLE_PREFIX;

seed_locale($admin, 'en_US', 'English');
seed_locale($admin, 'es_ES', 'Espanol');
seed_country($admin, 'US', 'United States');
seed_country($admin, 'ES', 'Spain');
seed_currency($admin, 'USD', 'US Dollar');
seed_currency($admin, 'EUR', 'Euro');

$regionId = seed_region($admin, 'US', 'California');
$cityId   = seed_city($admin, $regionId, 'San Jose', 'US');

$parentCat = seed_category($admin, 'Vehicles');
$catId     = seed_category($admin, 'Bicycles', $parentCat);
$otherCat  = seed_category($admin, 'Tricycles', $parentCat);

$sellerId = seed_user($admin, 'ada', 'ada@example.test');
$itemId   = seed_item($admin, $catId, $sellerId, 'A red bicycle');
$admin->query(
    "UPDATE {$prefix}t_item_description SET s_title = 'A red bicycle',"
    . " s_description = 'The original English body.' WHERE fk_i_item_id = $itemId"
);
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_item_description (fk_i_item_id, fk_c_locale_code, s_title, s_description)"
    . ' VALUES (?, ?, ?, ?)',
    'isss',
    array($itemId, 'es_ES', 'Una bici roja', 'El cuerpo original en espanol.')
);

$itemSecret = 'secret' . $catId;

// b_link starts off, so the save's "on" is a write rather than the fixture's value.
$pageId = seed_page($admin, 'about-us', 'About us', 0);
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_pages_description (fk_i_pages_id, fk_c_locale_code, s_title, s_text)"
    . ' VALUES (?, ?, ?, ?)',
    'isss',
    array($pageId, 'es_ES', 'Sobre nosotros', '<p>Quienes somos.</p>')
);

/* ----------------------------------------------------------------------------
 * Driving and reading back.
 * ------------------------------------------------------------------------- */

/**
 * Put a request in front of a real controller the way a browser would.
 *
 * @param string $controller Controller class name
 * @param array  $fields     The POST body
 * @param array  $query      The query string
 *
 * @return array{flashes: array, redirects: array, csrf: array}
 */
function drive(string $controller, array $fields, array $query): array
{
    $_GET   = $query;
    $_POST  = $fields;
    $_FILES = array();
    Params::init();

    $GLOBALS['flashes']    = array();
    $GLOBALS['redirects']  = array();
    $GLOBALS['views']      = array();
    $GLOBALS['csrfChecks'] = array();

    ob_start();
    try {
        (new $controller())->doModel();
    } catch (HarnessRedirect $e) {
        // The request ended, as it does in a browser.
    }
    ob_end_clean();

    return array(
        'flashes'   => $GLOBALS['flashes'],
        'redirects' => $GLOBALS['redirects'],
        'csrf'      => $GLOBALS['csrfChecks'],
    );
}

/** One row by primary key, read with raw SQL. */
function row(mysqli $admin, string $table, string $pk, $id): array
{
    $res = $admin->query('SELECT * FROM ' . DB_TABLE_PREFIX . $table . ' WHERE ' . $pk . ' = ' . (int)$id);
    $out = $res ? ($res->fetch_assoc() ?: array()) : array();
    if ($res) {
        $res->free();
    }

    return $out;
}

/** One locale's description row for an owner, read with raw SQL. */
function locale_row(mysqli $admin, string $table, string $fk, int $id, string $locale): array
{
    $res = $admin->query(
        'SELECT * FROM ' . DB_TABLE_PREFIX . $table . ' WHERE ' . $fk . ' = ' . $id
        . " AND fk_c_locale_code = '" . $admin->real_escape_string($locale) . "'"
    );
    $out = $res ? ($res->fetch_assoc() ?: array()) : array();
    if ($res) {
        $res->free();
    }

    return $out;
}

/** The subset of a row the pins name, so a failure prints only what it is about. */
function only(array $row, array $keys): array
{
    $out = array();
    foreach ($keys as $key) {
        $out[$key] = $row[$key] ?? null;
    }

    return $out;
}

/* ----------------------------------------------------------------------------
 * The listing editor's save.
 * ------------------------------------------------------------------------- */

$itemPost = array(
    'page'              => 'items',
    'action'            => 'item_edit_post',
    'id'                => (string)$itemId,
    'secret'            => $itemSecret,
    'catId'             => (string)$otherCat,
    'title'             => array('en_US' => 'A blue bicycle', 'es_ES' => 'Una bici azul'),
    'description'       => array('en_US' => '<p>The edited English body.</p>', 'es_ES' => '<p>El cuerpo editado.</p>'),
    'price'             => '19.90',
    'currency'          => 'EUR',
    'contactName'       => 'Grace Hopper',
    'contactEmail'      => 'grace@example.test',
    'contactPhone'      => '555-0199',
    'showEmail'         => '1',
    'countryId'         => 'US',
    'region'            => 'California',
    'regionId'          => (string)$regionId,
    'city'              => 'San Jose',
    'cityId'            => (string)$cityId,
    'cityArea'          => 'Downtown',
    'zip'               => '95110',
    'address'           => '1 Market Street',
    'dt_expiration'     => '-1',
    'update_expiration' => '',
);

$result = drive('CAdminItems', $itemPost, array('page' => 'items', 'action' => 'item_edit_post'));

harness_section('the listing save is guarded and reports success');

pin('the token is checked before anything is written', array('item_edit_post'), $result['csrf']);
pin('the admin is told it saved', array(array('ok', 'Changes saved correctly')), $result['flashes']);

harness_section('the listing save writes the columns its fields feed');

pin(
    'the listing row carries what was posted',
    array(
        'fk_i_category_id'   => (string)$otherCat,
        'i_price'            => '19900000',
        'fk_c_currency_code' => 'EUR',
        'b_show_email'       => '1',
        's_contact_name'     => 'Grace Hopper',
        's_contact_email'    => 'grace@example.test',
        's_contact_phone'    => '5550199',
    ),
    only(row($admin, 't_item', 'pk_i_id', $itemId), array(
        'fk_i_category_id', 'i_price', 'fk_c_currency_code', 'b_show_email',
        's_contact_name', 's_contact_email', 's_contact_phone',
    ))
);

pin(
    'the location row carries what was posted',
    array(
        'fk_c_country_code' => 'US',
        's_country'         => 'United States',
        'fk_i_region_id'    => (string)$regionId,
        's_region'          => 'California',
        'fk_i_city_id'      => (string)$cityId,
        's_city'            => 'San Jose',
        's_city_area'       => 'Downtown',
        's_address'         => '1 Market Street',
        's_zip'             => '95110',
    ),
    only(row($admin, 't_item_location', 'fk_i_item_id', $itemId), array(
        'fk_c_country_code', 's_country', 'fk_i_region_id', 's_region',
        'fk_i_city_id', 's_city', 's_city_area', 's_address', 's_zip',
    ))
);

harness_section('the listing save writes both locale rows, each its own');

pin(
    'the English title and body are the English ones',
    array('s_title' => 'A blue bicycle', 's_description' => '<p>The edited English body.</p>'),
    only(locale_row($admin, 't_item_description', 'fk_i_item_id', $itemId, 'en_US'), array('s_title', 's_description'))
);
pin(
    'the Spanish title and body are the Spanish ones',
    array('s_title' => 'Una bici azul', 's_description' => '<p>El cuerpo editado.</p>'),
    only(locale_row($admin, 't_item_description', 'fk_i_item_id', $itemId, 'es_ES'), array('s_title', 's_description'))
);

harness_section('the listing save reads the seller off the contact e-mail');

// There is no user field on the screen; whoever owns the contact address owns the listing.
pin('an address with no account behind it clears the owner', null, row($admin, 't_item', 'pk_i_id', $itemId)['fk_i_user_id']);

$itemPost['contactEmail'] = 'ada@example.test';
drive('CAdminItems', $itemPost, array('page' => 'items', 'action' => 'item_edit_post'));
pin(
    'a registered address makes that user the owner, and its name wins over the field',
    array('fk_i_user_id' => (string)$sellerId, 's_contact_name' => 'ada', 's_contact_email' => 'ada@example.test'),
    only(row($admin, 't_item', 'pk_i_id', $itemId), array('fk_i_user_id', 's_contact_name', 's_contact_email'))
);

harness_section('the listing save reads the checkbox, not the record');

$itemPost['showEmail'] = '';
drive('CAdminItems', $itemPost, array('page' => 'items', 'action' => 'item_edit_post'));
pin('an unticked box clears b_show_email', '0', row($admin, 't_item', 'pk_i_id', $itemId)['b_show_email']);

harness_section('the listing save moves the expiry only when a date is posted');

$before        = row($admin, 't_item', 'pk_i_id', $itemId)['dt_expiration'];
$itemPost['dt_expiration'] = '-1';
drive('CAdminItems', $itemPost, array('page' => 'items', 'action' => 'item_edit_post'));
pin('-1 is "leave it alone"', $before, row($admin, 't_item', 'pk_i_id', $itemId)['dt_expiration']);

$itemPost['dt_expiration'] = '2027-03-04 05:06:07';
drive('CAdminItems', $itemPost, array('page' => 'items', 'action' => 'item_edit_post'));
pin(
    'a date is stored as it was typed',
    '2027-03-04 05:06:07',
    row($admin, 't_item', 'pk_i_id', $itemId)['dt_expiration']
);
$itemPost['dt_expiration'] = '-1';

harness_section('the listing save needs the secret the form carried');

$wrongSecret           = $itemPost;
$wrongSecret['secret'] = 'not-the-secret';
$wrongSecret['price']  = '1.00';
drive('CAdminItems', $wrongSecret, array('page' => 'items', 'action' => 'item_edit_post'));
pin('a wrong secret writes no row', '19900000', row($admin, 't_item', 'pk_i_id', $itemId)['i_price']);

/* ----------------------------------------------------------------------------
 * The page editor's save.
 * ------------------------------------------------------------------------- */

$pagePost = array(
    'page'            => 'pages',
    'action'          => 'edit_post',
    'id'              => (string)$pageId,
    's_internal_name' => 'about-our-shop',
    'b_link'          => '1',
    'meta'            => array('template' => 'template-landing.php'),
    'en_US#s_title'   => 'About our shop',
    'en_US#s_text'    => '<p>The edited English body.</p>',
    'es_ES#s_title'   => 'Sobre nuestra tienda',
    'es_ES#s_text'    => '<p>El cuerpo editado.</p>',
);

$result = drive('CAdminPages', $pagePost, array('page' => 'pages', 'action' => 'edit_post'));

harness_section('the page save is guarded and reports success');

pin('the token is checked before anything is written', array('edit_post'), $result['csrf']);
pin('the admin is told it saved', array(array('ok', 'The page has been updated')), $result['flashes']);

harness_section('the page save writes the columns its fields feed');

pin(
    'the page row carries what was posted',
    array(
        's_internal_name' => 'about-our-shop',
        'b_link'          => '1',
        's_meta'          => '{"template":"template-landing.php"}',
    ),
    only(row($admin, 't_pages', 'pk_i_id', $pageId), array('s_internal_name', 'b_link', 's_meta'))
);

harness_section('the page save writes both locale rows, each its own');

pin(
    'the English title and body are the English ones',
    array('s_title' => 'About our shop', 's_text' => '<p>The edited English body.</p>'),
    only(locale_row($admin, 't_pages_description', 'fk_i_pages_id', $pageId, 'en_US'), array('s_title', 's_text'))
);
pin(
    'the Spanish title and body are the Spanish ones',
    array('s_title' => 'Sobre nuestra tienda', 's_text' => '<p>El cuerpo editado.</p>'),
    only(locale_row($admin, 't_pages_description', 'fk_i_pages_id', $pageId, 'es_ES'), array('s_title', 's_text'))
);

harness_section('the page save reads the switch, not the record');

$pagePost['b_link'] = '';
drive('CAdminPages', $pagePost, array('page' => 'pages', 'action' => 'edit_post'));
pin('an unticked footer switch clears b_link', '0', row($admin, 't_pages', 'pk_i_id', $pageId)['b_link']);

harness_section('the page save refuses what it cannot store');

$noTitle                   = $pagePost;
$noTitle['en_US#s_title']  = '';
$noTitle['es_ES#s_title']  = '';
$noTitle['en_US#s_text']   = '<p>Body with no title anywhere.</p>';
$result                    = drive('CAdminPages', $noTitle, array('page' => 'pages', 'action' => 'edit_post'));
pin(
    'a page with no title in any locale is refused',
    array(array('error', "The page couldn't be updated, at least one title should not be empty")),
    $result['flashes']
);
pin(
    'and its body is not written',
    '<p>The edited English body.</p>',
    locale_row($admin, 't_pages_description', 'fk_i_pages_id', $pageId, 'en_US')['s_text']
);

$noName                    = $pagePost;
$noName['s_internal_name'] = '';
$result                    = drive('CAdminPages', $noName, array('page' => 'pages', 'action' => 'edit_post'));
pin(
    'a page with no internal name is refused',
    array(array('error', 'You have to set an internal name')),
    $result['flashes']
);

$reserved                    = $pagePost;
$reserved['s_internal_name'] = 'contact';
$result                      = drive('CAdminPages', $reserved, array('page' => 'pages', 'action' => 'edit_post'));
pin(
    'an internal name that would shadow a core route is refused',
    array(array('error', 'You have to set a different internal name')),
    $result['flashes']
);
pin(
    'and the page keeps the name it had',
    'about-our-shop',
    row($admin, 't_pages', 'pk_i_id', $pageId)['s_internal_name']
);

/* ----------------------------------------------------------------------------
 * The page editor's save is the declaration's, not the controller's.
 * ------------------------------------------------------------------------- */

harness_section('the page controller reads no editor field out of the request');

// What stops the screen drifting back: a controller that reads one of these again is a
// second front door onto the same columns, and the two would sanitise differently.
$pagesCtrl = (string)file_get_contents(
    ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminPages.php'
);
foreach (array('s_internal_name', 'b_link') as $field) {
    check(
        'CAdminPages no longer reads "' . $field . '" itself',
        !preg_match('/Params::getParam[A-Za-z]*\(\s*\047' . preg_quote($field, '/') . '\047/', $pagesCtrl)
    );
}
check(
    'and it no longer sifts the whole request for the per-locale names',
    strpos($pagesCtrl, "preg_match('|(.+?)#(.+)|'") === false
);
check(
    'the save goes through the declared form',
    strpos($pagesCtrl, 'StaticPageForm::register(') !== false
        && strpos($pagesCtrl, 'osc_settings_save(') !== false
);

exit(harness_result());

/* file end: ./tests/admin-editor-save.php */
