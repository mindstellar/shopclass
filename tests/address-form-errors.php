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
 * The address form marks a refused field through the shared Form helper, not hand-written
 * markup, and the uploader only hides its file input once the script has taken over.
 *
 * DB-free; the view renders with stubbed helpers.  Usage: php tests/address-form-errors.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';

$attrs = Form::markInvalidWith('phone', 'Enter a phone.', array('id' => 'oe-addr-phone'));
pin('a refused field gets aria-invalid', 'true', $attrs['aria-invalid'] ?? '');
pin('it points at the -error id', 'oe-addr-phone-error', $attrs['aria-describedby'] ?? '');
pin('a fine field is left alone', array('id' => 'x'), Form::markInvalidWith('x', '', array('id' => 'x')));
pin(
    'the error line carries the same id',
    '<p class="oe-field-error" id="oe-addr-phone-error">Enter a phone.</p>',
    Form::errorLineWith('phone', 'Enter a phone.', $attrs)
);
pin('a fine field has no error line', '', Form::errorLineWith('phone', '', $attrs));

// The address view, rendered with stubbed helpers.
function _m($text)
{
    return $text;
}
function osc_get_countries()
{
    return array(array('pk_c_code' => 'US', 's_name' => 'United States'), array('pk_c_code' => 'IN', 's_name' => 'India'));
}
function osc_base_url($withIndex = false)
{
    return 'http://localhost/' . ($withIndex ? 'index.php' : '');
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
require_once ABS_PATH . 'oc-includes/osclass/gui/page-fn.php';
$renderAddresses = static function (array $errors): string {
    foreach (array('addresses' => array(), 'address_form' => array('phone' => '12', 'country' => 'IN'), 'address_errors' => $errors) as $key => $value) {
        View::getInstance()->_exportVariableToView($key, $value);
    }
    ob_start();
    require ABS_PATH . 'oc-includes/osclass/gui/account/user-addresses-content.php';

    return (string) ob_get_clean();
};
$tag = static function (string $html, string $id): string {
    return preg_match('/<(?:input|select)\b[^>]*\bid="' . preg_quote($id, '/') . '"[^>]*>/s', $html, $m) ? $m[0] : '';
};

$html  = $renderAddresses(array('phone' => 'Enter a phone.', 'country' => 'Pick a country.'));
$phone = $tag($html, 'oe-addr-phone');
pin('the refused phone input is aria-invalid and points at its error', array(true, true), array(
    str_contains($phone, 'aria-invalid="true"'),
    str_contains($phone, 'aria-describedby="oe-addr-phone-error"'),
));
pin('the phone error line is on the page', 1, preg_match('/<(p|span)\b[^>]*\bid="oe-addr-phone-error"[^>]*>Enter a phone\.<\/\1>/', $html));
$country = $tag($html, 'oe-addr-country');
pin('the refused country select is aria-invalid and points at its error', array(true, true), array(
    str_contains($country, 'aria-invalid="true"'),
    str_contains($country, 'aria-describedby="oe-addr-country-error"'),
));
pin('the country error line is on the page', 1, preg_match('/<(p|span)\b[^>]*\bid="oe-addr-country-error"[^>]*>Pick a country\.<\/\1>/', $html));
$name = $tag($html, 'oe-addr-name');
pin('a fine field has no marks', array(true, false, false, false), array(
    $name !== '',
    str_contains($name, 'aria-invalid'),
    str_contains($name, 'aria-describedby'),
    str_contains($html, 'oe-addr-name-error'),
));
$clean = $renderAddresses(array());
pin('a clean form has no marks at all', array(false, false), array(str_contains($clean, 'aria-invalid'), str_contains($clean, '-error"')));

$css = (string) file_get_contents(ABS_PATH . 'oc-includes/assets/osclass/osc-uploader.css');
pin('the file input is hidden only inside an enhanced uploader', true, (bool) preg_match('/\.osc-uploader\.is-enhanced\s+\.osc-uploader-input\s*\{/', $css));
pin('no rule hides the bare file input', false, (bool) preg_match('/^\.osc-uploader-input\s*\{/m', $css));
$js = (string) file_get_contents(ABS_PATH . 'oc-includes/assets/osclass/osc-uploader.js');
pin('the script marks the uploader enhanced', true, str_contains($js, "classList.add('is-enhanced')"));

exit(harness_result());
