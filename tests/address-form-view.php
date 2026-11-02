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
 * The account address form follows the selected country (fields, labels, required marks), the
 * format JSON prints once, osc_address_text follows the country's order, and the form script is
 * wired to the markup it needs.
 *
 * DB-free; the view renders with stubbed helpers.  Usage: php tests/address-form-view.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';

function _m($text)
{
    return $text;
}
function osc_get_countries()
{
    if (!empty($GLOBALS['one_country'])) {
        return array(array('pk_c_code' => 'GB', 's_name' => 'United Kingdom'));
    }

    return array(
        array('pk_c_code' => 'US', 's_name' => 'United States'),
        array('pk_c_code' => 'GB', 's_name' => 'United Kingdom'),
        array('pk_c_code' => 'DE', 's_name' => 'Germany'),
        array('pk_c_code' => 'JP', 's_name' => 'Japan'),
        array('pk_c_code' => 'IE', 's_name' => 'Ireland'),
        array('pk_c_code' => 'ZZ', 's_name' => 'Nowhere'),
    );
}
function osc_base_url($withIndex = false)
{
    return 'http://localhost/' . ($withIndex ? 'index.php' : '');
}
function osc_assets_url($file = '')
{
    return 'http://localhost/oc-includes/assets/' . $file;
}
function osc_asset_url_versioned($url)
{
    return $url . '?v=1';
}
function osc_user_addresses_url()
{
    return 'http://localhost/user/addresses';
}
function osc_csrf_token_form()
{
    return '';
}
function osc_show_flash_message()
{
}
function osc_run_hook($hook, ...$args)
{
}
function osc_apply_filter($filter, $value, ...$args)
{
    return $filter === 'user_menu_filter' ? array() : $value;
}
function osc_logged_user_id()
{
    return 1;
}
foreach (array('osc_user_dashboard_url', 'osc_user_list_items_url', 'osc_user_alerts_url', 'osc_user_public_profile_url', 'osc_user_profile_url', 'osc_change_user_email_url', 'osc_user_logout_url') as $fn) {
    eval('function ' . $fn . '(...$a) { return "#"; }');
}
foreach (array('osc_is_user_dashboard', 'osc_is_list_items', 'osc_is_list_alerts', 'osc_is_messages_page', 'osc_is_user_profile', 'osc_is_current_page', 'osc_is_change_email_page', 'osc_is_change_password_page', 'osc_is_change_username_page', 'osc_get_osclass_location') as $fn) {
    eval('function ' . $fn . '(...$a) { return false; }');
}

// The three address helpers sit together in hUsers.php, which needs the whole app to load.
$helpers = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php');
$start   = strpos($helpers, 'function osc_address_formats');
$end     = strpos($helpers, 'function osc_address_picker');
$start   = strrpos(substr($helpers, 0, (int) $start), '/**');
$end     = strrpos(substr($helpers, 0, (int) $end), '/**');
eval(substr($helpers, (int) $start, (int) $end - (int) $start));
require_once ABS_PATH . 'oc-includes/osclass/gui/page-fn.php';

$render = static function (string $country): string {
    foreach (array('addresses' => array(), 'address_form' => array('country' => $country), 'address_errors' => array()) as $key => $value) {
        View::getInstance()->_exportVariableToView($key, $value);
    }
    ob_start();
    require ABS_PATH . 'oc-includes/osclass/gui/account/user-addresses-content.php';

    return (string) ob_get_clean();
};
$wrapper = static function (string $html, string $name): string {
    return preg_match('/<div data-address-field="' . $name . '"[^>]*>.*?<\/div><\/div>/s', $html, $m) ? $m[0] : '';
};
$order = static function (string $html): array {
    preg_match_all('/data-address-field="(line1|city|state|postcode)"/', $html, $m);

    return $m[1];
};

$gb = $render('GB');
pin('the form carries the JS hook', 1, substr_count($gb, 'data-address-form'));
$ie = $render('IE');
pin('IE labels the state field County, not required', array(true, false), array(
    str_contains($wrapper($ie, 'state'), '>County</label>'),
    str_contains($wrapper($ie, 'state'), ' required'),
));
pin('GB asks for a required Postcode', array(true, true), array(
    str_contains($wrapper($gb, 'postcode'), '>Postcode</label>'),
    str_contains($wrapper($gb, 'postcode'), ' required'),
));
pin('the phone label is Phone, not Mobile', array(true, false), array(str_contains($gb, '>Phone</label>'), str_contains($gb, 'Mobile')));

$us = $render('US');
pin('US asks for a required State and ZIP code', array(true, true, true, true), array(
    str_contains($wrapper($us, 'state'), '>State</label>'),
    str_contains($wrapper($us, 'state'), ' required'),
    str_contains($wrapper($us, 'postcode'), '>ZIP code</label>'),
    str_contains($wrapper($us, 'postcode'), ' required'),
));
pin('US keeps the standard order', array('line1', 'city', 'state', 'postcode'), $order($us));

$de = $render('DE');
pin('DE hides and disables the state field', array(true, true), array(
    str_contains($wrapper($de, 'state'), ' hidden'),
    str_contains($wrapper($de, 'state'), ' disabled'),
));
pin('DE puts the postcode before the city', array('line1', 'postcode', 'city', 'state'), $order($de));
$jp = $render('JP');
pin('JP writes the postcode first and the street last', array('postcode', 'state', 'city', 'line1'), $order($jp));
pin('an unknown country gets the default labels', true, str_contains($wrapper($render('ZZ'), 'state'), '>State / Province</label>'));

pin('the formats JSON prints once, with the script', array(1, 1), array(
    substr_count($gb, 'id="osc-address-formats"'),
    substr_count($gb, 'osclass/address-form.js'),
));
preg_match('/id="osc-address-formats"[^>]*>(.*?)<\/script>/s', $gb, $json);
$decoded = json_decode($json[1] ?? '', true);
pin('the JSON is the format map', array('Postal code', '^(?:\d{5})$'), array($decoded['DE']['postcode'] ?? null, $decoded['DE']['pattern'] ?? null));
ob_start();
osc_address_form_script();
pin('a later call prints nothing', '', ob_get_clean());

$formats = osc_address_formats();
pin('the formats cover the site countries only', array('US', 'GB', 'DE', 'JP', 'IE', 'ZZ'), array_keys($formats));
pin('a format has the fields the script reads', array('fields', 'required', 'state', 'postcode', 'pattern', 'example'), array_keys($formats['DE']));

pin('osc_address_text puts the German postcode before the city', 'Hauptstr. 1, 10115 Berlin, Germany · +49 30 1234', osc_address_text(array(
    'line1' => 'Hauptstr. 1', 'city' => 'Berlin', 'state' => 'Berlin', 'postcode' => '10115', 'country' => 'DE', 'phone' => '+49 30 1234',
)));
pin('osc_address_text keeps the US order', '1 Main St, Austin, Texas 73301, United States', osc_address_text(array(
    'line1' => '1 Main St', 'city' => 'Austin', 'state' => 'Texas', 'postcode' => '73301', 'country' => 'US',
)));
pin('osc_address_text leads with the Japanese postcode', '100-0001 Tokyo, 1-1 Chiyoda, Japan', osc_address_text(array(
    'line1' => '1-1 Chiyoda', 'city' => 'Tokyo', 'postcode' => '100-0001', 'country' => 'JP',
)));
pin('osc_address_text copes with an empty address', '', osc_address_text(array()));

$js = (string) file_get_contents(ABS_PATH . 'oc-includes/assets/osclass/address-form.js');
pin('the script reads the JSON, the form hook and the field wrappers', array(true, true, true), array(
    str_contains($js, 'osc-address-formats'),
    str_contains($js, '[data-address-form]'),
    str_contains($js, 'data-address-field'),
));
pin('the script needs no jQuery', false, (bool) preg_match('/jQuery|\$\(/', $js));

pin('the JSON block names the regions ajax url and the Choose label', array(true, true), array(
    str_contains($gb, 'data-regions-url="http://localhost/index.php?page=ajax&amp;action=regions&amp;countryId="'),
    str_contains($gb, 'data-choose="Choose…"'),
));
pin('with several countries the form has a Country select', array(1, 0), array(
    substr_count($gb, '<select class="oe-input" id="oe-addr-country"'),
    substr_count($gb, 'type="hidden" name="country"'),
));
$GLOBALS['one_country'] = true;
$one = $render('GB');
pin('with one country the form sends it hidden and shows no Country box', array(0, 1, true), array(
    substr_count($one, 'id="oe-addr-country"'),
    substr_count($one, 'type="hidden" name="country" value="GB"'),
    str_contains($one, 'data-address-field="postcode"'),
));
unset($GLOBALS['one_country']);

$node = trim((string) shell_exec('command -v node 2>/dev/null'));
if ($node !== '') {
    exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/address-form-js.test.mjs') . ' 2>&1', $nodeOut, $nodeCode);
    pin('address-form.js passes its stub-DOM tests', 0, $nodeCode);
}

exit(harness_result());
