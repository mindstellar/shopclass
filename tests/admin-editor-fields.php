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
 * Every request key the two editors post, pinned control by control.
 *
 * The listing editor (items/frm.php) and the page editor (pages/frm.php) are hand-built
 * screens whose fields are read back by name in ItemActions and CAdminPages. Nothing
 * declares that contract, so a field renamed, dropped, or turned into a different kind of
 * control while the screens are rebuilt is silent: the form still draws, the save still
 * runs, and the column it fed is quietly left at its old value.
 *
 * For each screen the add form and the edit form are rendered, and for every posted name
 * three things are pinned: exactly one control carries it, it is the control type the save
 * path expects, and it is drawn with the fixture's value. The plugin surfaces are pinned
 * too -- the listing's #plugin-hook container and the page's page_meta output both have to
 * land inside the <form>, or what a plugin renders is never submitted.
 *
 * DB-free: the models the views reach for are stood in, and the fixture listing and page
 * are handed to the view the way a controller hands them over. Each screen renders in its
 * own subprocess, because both views declare global functions of the same names and a
 * second include would fatal.
 *
 * Usage: php tests/admin-editor-fields.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

error_reporting(E_ALL & ~E_DEPRECATED);

if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}
if (!defined('OSC_DEBUG')) {
    define('OSC_DEBUG', false);
}
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}

/* ----------------------------------------------------------------------------
 * The fixtures. Both are the shape the controller exports to the view.
 * ------------------------------------------------------------------------- */

$GLOBALS['fixtureLocales'] = array(
    array('pk_c_code' => 'en_US', 's_name' => 'English'),
    array('pk_c_code' => 'es_ES', 's_name' => 'Espanol'),
);

$GLOBALS['fixtureItem'] = array(
    'pk_i_id'            => 42,
    's_secret'           => 'sekrit42',
    'fk_i_category_id'   => 7,
    'fk_i_user_id'       => 3,
    'i_price'            => 19900000,
    'fk_c_currency_code' => 'EUR',
    's_contact_name'     => 'Ada Lovelace',
    's_contact_email'    => 'ada@example.test',
    's_contact_phone'    => '555-0100',
    'b_show_email'       => 1,
    's_ip'               => '203.0.113.7',
    'fk_c_country_code'  => 'US',
    's_country'          => 'United States',
    'fk_i_region_id'     => 12,
    's_region'           => 'California',
    'fk_i_city_id'       => 340,
    's_city'             => 'San Jose',
    'fk_i_city_area_id'  => 88,
    's_city_area'        => 'Downtown',
    's_zip'              => '95110',
    's_address'          => '1 Market Street',
    'locale'             => array(
        'en_US' => array('s_title' => 'A red bicycle', 's_description' => '<p>Barely used.</p>'),
        'es_ES' => array('s_title' => 'Una bici roja', 's_description' => '<p>Casi nueva.</p>'),
    ),
);

$GLOBALS['fixtureResources'] = array(
    array('pk_i_id' => 101, 'fk_i_item_id' => 42, 's_name' => 'front', 's_path' => 'oc-content/uploads/', 's_extension' => 'jpg'),
    array('pk_i_id' => 102, 'fk_i_item_id' => 42, 's_name' => 'side', 's_path' => 'oc-content/uploads/', 's_extension' => 'jpg'),
    array('pk_i_id' => 103, 'fk_i_item_id' => 42, 's_name' => 'rear', 's_path' => 'oc-content/uploads/', 's_extension' => 'jpg'),
);

$GLOBALS['fixturePage'] = array(
    'pk_i_id'         => 9,
    's_internal_name' => 'about-us',
    'b_indelible'     => 0,
    'b_link'          => 1,
    's_meta'          => '{"template":"landing"}',
    'locale'          => array(
        'en_US' => array('s_title' => 'About us', 's_text' => '<p>Who we are.</p>'),
        'es_ES' => array('s_title' => 'Sobre nosotros', 's_text' => '<p>Quienes somos.</p>'),
    ),
);

/* ----------------------------------------------------------------------------
 * Stand-ins. The models are declared here so the autoloader never reaches the
 * real ones; the helpers are the handful the two views and their form classes
 * call that would otherwise want a database.
 * ------------------------------------------------------------------------- */

/** The category tree, as much of it as the listing view reads. */
class Category
{
    public static function newInstance(): self
    {
        return new self();
    }

    public function toRootTree($id = null)
    {
        return array(
            array('pk_i_id' => 4, 's_name' => 'Vehicles'),
            array('pk_i_id' => 7, 's_name' => 'Bicycles'),
        );
    }

    public function listEnabled()
    {
        return $this->listAll();
    }

    public function listAll($order = true)
    {
        return array(
            array('pk_i_id' => 4, 'fk_i_parent_id' => null, 's_name' => 'Vehicles', 'b_price_enabled' => 1),
            array('pk_i_id' => 7, 'fk_i_parent_id' => 4, 's_name' => 'Bicycles', 'b_price_enabled' => 1),
        );
    }

    public function findByPrimaryKey($id)
    {
        return array('pk_i_id' => $id, 'i_expiration_days' => 30, 'b_price_enabled' => 1);
    }
}

/** Only the site currency is read, by the price block. */
class Preference
{
    public static function newInstance(): self
    {
        return new self();
    }

    public function get($key, $section = 'osclass')
    {
        return $key === 'currency' ? 'EUR' : '';
    }
}

if (!function_exists('_e')) {
    function _e($key, $domain = 'core')
    {
        echo $key;
    }
}
if (!function_exists('_m')) {
    function _m($key)
    {
        return $key;
    }
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
if (!function_exists('osc_admin_base_url')) {
    function osc_admin_base_url($index = false)
    {
        return 'https://example.test/oc-admin/index.php';
    }
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($index = false)
    {
        return 'https://example.test/' . ($index ? 'index.php' : '');
    }
}
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
if (!function_exists('osc_enqueue_script')) {
    function osc_enqueue_script($id)
    {
    }
}
if (!function_exists('osc_item_url')) {
    function osc_item_url()
    {
        return 'https://example.test/a-red-bicycle_i42';
    }
}
if (!function_exists('osc_is_moderator')) {
    function osc_is_moderator()
    {
        return false;
    }
}
if (!function_exists('osc_images_enabled_at_items')) {
    function osc_images_enabled_at_items()
    {
        return true;
    }
}
if (!function_exists('osc_price_enabled_at_items')) {
    function osc_price_enabled_at_items()
    {
        return true;
    }
}
if (!function_exists('osc_max_images_per_item')) {
    function osc_max_images_per_item()
    {
        return 10;
    }
}
if (!function_exists('osc_locale_thousands_sep')) {
    function osc_locale_thousands_sep()
    {
        return ',';
    }
}
if (!function_exists('osc_locale_dec_point')) {
    function osc_locale_dec_point()
    {
        return '.';
    }
}
if (!function_exists('osc_prepare_price')) {
    function osc_prepare_price($price)
    {
        return number_format((float)$price / 1000000, 2, '.', '');
    }
}
if (!function_exists('osc_get_currencies')) {
    function osc_get_currencies()
    {
        return array(
            array('pk_c_code' => 'USD', 's_description' => 'US Dollar'),
            array('pk_c_code' => 'EUR', 's_description' => 'Euro'),
        );
    }
}
if (!function_exists('osc_get_countries')) {
    function osc_get_countries()
    {
        return array(
            array('pk_c_code' => 'US', 's_name' => 'United States'),
            array('pk_c_code' => 'ES', 's_name' => 'Spain'),
        );
    }
}
if (!function_exists('osc_get_locales')) {
    function osc_get_locales($indexed_by_pk = false, $order_by = null)
    {
        return $GLOBALS['fixtureLocales'];
    }
}
if (!function_exists('osc_get_admin_locales')) {
    function osc_get_admin_locales($indexed_by_pk = false, $order_by = null)
    {
        return $GLOBALS['fixtureLocales'];
    }
}
if (!function_exists('osc_current_admin_locale')) {
    function osc_current_admin_locale()
    {
        return 'en_US';
    }
}
if (!function_exists('osc_language')) {
    function osc_language()
    {
        return 'en_US';
    }
}
if (!function_exists('osc_csrf_token_url')) {
    function osc_csrf_token_url()
    {
        return 'CSRFName=t&CSRFToken=t';
    }
}
if (!function_exists('osc_page_template')) {
    function osc_page_template($id)
    {
        return null;
    }
}
if (!function_exists('osc_page_templates')) {
    function osc_page_templates()
    {
        return array();
    }
}
if (!function_exists('osc_widget_types')) {
    function osc_widget_types()
    {
        return array();
    }
}
if (!function_exists('osc_max_images_for_user')) {
    function osc_max_images_for_user($userId = null)
    {
        return 10;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPlugins.php';
// After hPlugins.php, so the guarded stand-ins there leave the real hook API alone and
// only __() (hTranslations.php is never loaded) is filled in.
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hItems.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';

/* ----------------------------------------------------------------------------
 * The child half: render one screen and print it.
 * ------------------------------------------------------------------------- */

/**
 * Draw one of the four screens, the way its controller draws it.
 *
 * @param string $screen One of item-add, item-edit, page-add, page-edit
 *
 * @return string
 */
function render_screen(string $screen): string
{
    $view = View::newInstance();

    if ($screen === 'item-add' || $screen === 'item-edit') {
        $isNew = ($screen === 'item-add');
        $view->_exportVariableToView('new_item', $isNew);
        $view->_exportVariableToView('actions', array());
        if (!$isNew) {
            $view->_exportVariableToView('item', $GLOBALS['fixtureItem']);
            $view->_exportVariableToView('resources', $GLOBALS['fixtureResources']);
        } else {
            $view->_exportVariableToView('resources', array());
        }

        ob_start();
        include ABS_PATH . 'oc-admin/themes/modern/items/frm.php';

        return (string)ob_get_clean();
    }

    // A plugin field on the page editor, so where the page_meta output lands is pinned
    // against the same rule as a core field.
    osc_add_hook('page_meta', static function () {
        echo '<input type="text" name="plugin_meta_probe" value="from-a-plugin"/>';
    });

    $view->_exportVariableToView('templates', array('landing'));
    $view->_exportVariableToView('registeredTemplates', array());
    $view->_exportVariableToView('page', $screen === 'page-edit' ? $GLOBALS['fixturePage'] : array());

    ob_start();
    include ABS_PATH . 'oc-admin/themes/modern/pages/frm.php';

    return (string)ob_get_clean();
}

if (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] !== '') {
    echo render_screen($argv[1]);
    exit(0);
}

/* ----------------------------------------------------------------------------
 * The parent half: render each screen in its own process and read the markup.
 * ------------------------------------------------------------------------- */

/**
 * The rendered markup for one screen, drawn by a child of this script.
 *
 * @param string $screen
 *
 * @return string
 */
function screen(string $screen): string
{
    static $cache = array();
    if (isset($cache[$screen])) {
        return $cache[$screen];
    }

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($screen) . ' 2>&1';
    $out = (string)shell_exec($cmd);
    if (strpos($out, '<form') === false) {
        fwrite(STDERR, "rendering $screen produced no form:\n$out\n");
        exit(2);
    }

    return $cache[$screen] = $out;
}

/**
 * An XPath over one screen's markup, with the document wrapped so a fragment parses.
 *
 * @param string $screen
 *
 * @return DOMXPath
 */
function dom(string $screen): DOMXPath
{
    static $cache = array();
    if (isset($cache[$screen])) {
        return $cache[$screen];
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
        . screen($screen) . '</body></html>');
    libxml_clear_errors();

    return $cache[$screen] = new DOMXPath($doc);
}

/**
 * Every control on a screen carrying one posted name.
 *
 * @param string $screen
 * @param string $name
 *
 * @return DOMElement[]
 */
function controls(string $screen, string $name): array
{
    $found = array();
    foreach (dom($screen)->query('//input[@name] | //select[@name] | //textarea[@name]') as $node) {
        if ($node instanceof DOMElement && $node->getAttribute('name') === $name) {
            $found[] = $node;
        }
    }

    return $found;
}

/**
 * How a control reads for a pin: the kind of control it is, whether it is inside the
 * form, and what value it was drawn with.
 *
 * @param DOMElement $node
 *
 * @return array{type: string, in_form: bool, value: string}
 */
function control_shape(DOMElement $node): array
{
    $tag  = strtolower($node->tagName);
    $type = $tag === 'input' ? strtolower($node->getAttribute('type') ?: 'text') : $tag;

    if ($tag === 'textarea') {
        // What a browser would hand back as .value. Serialised rather than read as text,
        // because this parser turns a body's markup into real child nodes and textContent
        // would strip the tags; then decoded, because a browser reads a textarea's body as
        // raw text either way -- "<p>" and "&lt;p&gt;" in the source are the same value to
        // it, and only one of the two is safe to write.
        $value = '';
        foreach ($node->childNodes as $child) {
            $value .= $node->ownerDocument->saveHTML($child);
        }
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    } elseif ($tag === 'select') {
        $value = '';
        foreach ($node->getElementsByTagName('option') as $option) {
            if ($option->hasAttribute('selected')) {
                $value = $option->getAttribute('value');
                break;
            }
        }
    } elseif ($type === 'checkbox') {
        $value = ($node->hasAttribute('checked') ? 'checked:' : 'unchecked:') . $node->getAttribute('value');
    } else {
        $value = $node->getAttribute('value');
    }

    return array('type' => $type, 'in_form' => in_form($node), 'value' => $value);
}

/**
 * Whether a node has a <form> ancestor. A control outside the form is never submitted.
 *
 * @param DOMNode $node
 *
 * @return bool
 */
function in_form(DOMNode $node): bool
{
    for ($p = $node->parentNode; $p !== null; $p = $p->parentNode) {
        if ($p instanceof DOMElement && strtolower($p->tagName) === 'form') {
            return true;
        }
    }

    return false;
}

/**
 * The one control carrying a name, or null. Keeps a suite that has already gone red on a
 * missing field reporting rather than fataling on the next assertion about it.
 *
 * @param string $screen
 * @param string $name
 *
 * @return DOMElement|null
 */
function first_control(string $screen, string $name): ?DOMElement
{
    $nodes = controls($screen, $name);

    return $nodes === array() ? null : $nodes[0];
}

/**
 * Pin one control's kind, place and value, by name.
 *
 * @param string $label
 * @param string $screen
 * @param string $name
 * @param array  $expected
 */
function pin_shape(string $label, string $screen, string $name, array $expected): void
{
    $node = first_control($screen, $name);
    pin($label, $expected, $node === null ? null : control_shape($node));
}

/**
 * Pin one posted name: one control, of the expected kind, inside the form, carrying the
 * fixture's value.
 *
 * @param string $screen
 * @param string $name
 * @param string $type
 * @param string $value
 */
function pin_field(string $screen, string $name, string $type, string $value): void
{
    $nodes = controls($screen, $name);
    pin("$screen: one control named $name", 1, count($nodes));
    if (count($nodes) !== 1) {
        return;
    }
    pin_shape(
        "$screen: $name is a $type inside the form, carrying its value",
        $screen,
        $name,
        array('type' => $type, 'in_form' => true, 'value' => $value)
    );
}

/** Whether an element with this id is present on the screen and sits inside the form. */
function id_in_form(string $screen, string $id): bool
{
    $nodes = dom($screen)->query('//*[@id="' . $id . '"]');

    return $nodes->length === 1 && in_form($nodes->item(0));
}

/* ----------------------------------------------------------------------------
 * The listing editor.
 * ------------------------------------------------------------------------- */

// Every name the edit form posts. The add form is the same list without the four the
// route and the record supply, which is asserted rather than assumed further down.
$itemEdit = array(
    // Route
    'page'                 => array('hidden', 'items'),
    'action'               => array('hidden', 'item_edit_post'),
    'id'                   => array('hidden', '42'),
    'secret'               => array('hidden', 'sekrit42'),
    // Content
    'title[en_US]'         => array('text', 'A red bicycle'),
    'title[es_ES]'         => array('text', 'Una bici roja'),
    'description[en_US]'   => array('textarea', '<p>Barely used.</p>'),
    'description[es_ES]'   => array('textarea', '<p>Casi nueva.</p>'),
    // Category
    'catId'                => array('hidden', '7'),
    // Price
    'price'                => array('text', '19.90'),
    'currency'             => array('select', 'EUR'),
    // Photos
    'photos[]'             => array('file', ''),
    // Seller
    'contactName'          => array('text', 'Ada Lovelace'),
    'contactEmail'         => array('text', 'ada@example.test'),
    'contactPhone'         => array('text', '555-0100'),
    'showEmail'            => array('checkbox', 'checked:1'),
    'ipAddress'            => array('text', '203.0.113.7'),
    // Location
    'countryId'            => array('select', 'US'),
    'region'               => array('text', 'California'),
    'regionId'             => array('hidden', '12'),
    'city'                 => array('text', 'San Jose'),
    'cityId'               => array('hidden', '340'),
    'cityArea'             => array('text', 'Downtown'),
    'zip'                  => array('text', '95110'),
    'address'              => array('text', '1 Market Street'),
    // Expiry
    'dt_expiration'        => array('text', '-1'),
    'update_expiration'    => array('checkbox', 'unchecked:'),
);

harness_section('the listing editor posts every name the save path reads');

foreach ($itemEdit as $name => $spec) {
    pin_field('item-edit', $name, $spec[0], $spec[1]);
}

// The city-area id was drawn beside the city-area name and read by nothing: not
// ItemActions::prepareData(), not a model, not a theme. It is pinned absent so it does not
// come back with the next field that copies its neighbour.
foreach (array('item-edit', 'item-add') as $screen) {
    pin("$screen: no control named cityAreaId", 0, count(controls($screen, 'cityAreaId')));
}

harness_section('the listing add form is the same form without the record');

// These four exist only once there is a listing to edit: its id and secret, the address
// it was posted from, and the choice to move its expiry.
$addOnlyMissing = array('id', 'secret', 'ipAddress', 'update_expiration');
foreach ($itemEdit as $name => $spec) {
    if (in_array($name, $addOnlyMissing, true)) {
        pin("item-add: no control named $name", 0, count(controls('item-add', $name)));
        continue;
    }
    if ($name === 'action') {
        pin_field('item-add', 'action', 'hidden', 'post_item');
        continue;
    }
    // An unsaved listing draws the same controls, empty.
    pin("item-add: one control named $name", 1, count(controls('item-add', $name)));
}

pin_shape(
    'item-add: the expiry box is the add form\'s, not the edit form\'s',
    'item-add',
    'dt_expiration',
    array('type' => 'text', 'in_form' => true, 'value' => '')
);

harness_section('the listing editor keeps its plugin surfaces inside the form');

check('item-edit: #plugin-hook is inside the form', id_in_form('item-edit', 'plugin-hook'));
check('item-add: #plugin-hook is inside the form', id_in_form('item-add', 'plugin-hook'));
// The category is chosen through the picker now, not the cascading selects the inline
// script used to write into #select_holder. What has to hold is that the control the
// administrator operates is the one sitting over the hidden field the save reads.
check('item-edit: the category picker is inside the form', id_in_form('item-edit', 'catId-picker'));
check('item-add: the category picker is inside the form', id_in_form('item-add', 'catId-picker'));

harness_section('the listing editor draws the photos it already has');

$photoLinks = dom('item-edit')->query('//div[@class="photos_div"]/div');
pin('item-edit: one tile per existing photo', 3, $photoLinks->length);
pin('item-edit: the add form has none', 0, dom('item-add')->query('//div[@class="photos_div"]/div')->length);

/* ----------------------------------------------------------------------------
 * The page editor.
 * ------------------------------------------------------------------------- */

$pageEdit = array(
    'page'             => array('hidden', 'pages'),
    'action'           => array('hidden', 'edit_post'),
    'id'               => array('hidden', '9'),
    'en_US#s_title'    => array('text', 'About us'),
    'en_US#s_text'     => array('textarea', '<p>Who we are.</p>'),
    'es_ES#s_title'    => array('text', 'Sobre nosotros'),
    'es_ES#s_text'     => array('textarea', '<p>Quienes somos.</p>'),
    'meta[template]'   => array('select', 'landing'),
    's_internal_name'  => array('text', 'about-us'),
    'b_link'           => array('checkbox', 'checked:1'),
);

harness_section('the page editor posts every name the save path reads');

foreach ($pageEdit as $name => $spec) {
    pin_field('page-edit', $name, $spec[0], $spec[1]);
}

harness_section('the page add form is the same form without the record');

foreach ($pageEdit as $name => $spec) {
    if ($name === 'id') {
        pin('page-add: no control named id', 0, count(controls('page-add', 'id')));
        continue;
    }
    if ($name === 'action') {
        pin_field('page-add', 'action', 'hidden', 'add_post');
        continue;
    }
    pin("page-add: one control named $name", 1, count(controls('page-add', $name)));
}

pin_shape(
    'page-add: the footer-link box starts unticked',
    'page-add',
    'b_link',
    array('type' => 'checkbox', 'in_form' => true, 'value' => 'unchecked:1')
);

harness_section('the page editor keeps the plugin hook inside the form');

foreach (array('page-edit', 'page-add') as $screen) {
    pin_field($screen, 'plugin_meta_probe', 'text', 'from-a-plugin');
}

exit(harness_result());

/* file end: ./tests/admin-editor-fields.php */
