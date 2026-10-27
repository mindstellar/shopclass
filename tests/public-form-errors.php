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
 * A refused public form marks each wrong field with its own line: osc_keep_form() keeps the
 * messages by pointer, the form classes print aria-invalid and a .oe-field-error line, and
 * the listing validator tells an empty title from a short one.
 *
 * DB-free.  Usage: php tests/public-form-errors.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hUtils.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hValidate.php';

use mindstellar\exception\InvalidException;
use mindstellar\listing\ListingValidator;

if (!function_exists('_m')) {
    function _m($key)
    {
        return $key;
    }
}
if (!function_exists('osc_add_flash_error_message')) {
    function osc_add_flash_error_message($msg)
    {
        $GLOBALS['flashed'][] = $msg;
    }
}
if (!function_exists('osc_logged_user_name')) {
    function osc_logged_user_name()
    {
        return '';
    }
}
if (!function_exists('osc_logged_user_email')) {
    function osc_logged_user_email()
    {
        return '';
    }
}
if (!function_exists('osc_max_characters_per_title')) {
    function osc_max_characters_per_title()
    {
        return 100;
    }
}

if (!function_exists('osc_max_characters_per_description')) {
    function osc_max_characters_per_description()
    {
        return 5000;
    }
}
if (!function_exists('osc_max_size_kb')) {
    function osc_max_size_kb()
    {
        return 2048;
    }
}
if (!function_exists('__')) {
    function __($key)
    {
        return $key;
    }
}
if (!function_exists('osc_selectable_parent_categories')) {
    function osc_selectable_parent_categories()
    {
        return true;
    }
}
if (!function_exists('_osc_location_list')) {
    function _osc_location_list($rows, $type)
    {
        return $rows;
    }
}
$session = Session::getInstance();
$render  = static function (callable $draw): string {
    ob_start();
    $draw();

    return (string) ob_get_clean();
};

harness_section('osc_keep_form keeps a message per field');
pin('nothing kept, nothing marked', array('', array()), array(osc_form_error('/yourName'), osc_form_errors()));
$html = $render(static fn () => ContactForm::your_name());
pin('a fine field has no aria-invalid and no line', 0, substr_count($html, 'aria-invalid') + substr_count($html, 'oe-field-error'));

$GLOBALS['flashed'] = array();
osc_keep_form(array('yourName' => ''), 'Check the fields marked below.', array('/yourName' => 'Enter your name', '/yourEmail' => 'Enter a valid e-mail address'));
pin('the message is by pointer', 'Enter your name', osc_form_error('/yourName'));
pin('all of them', array('/yourName', '/yourEmail'), array_keys(osc_form_errors()));
pin('one flash line', array('Check the fields marked below.'), $GLOBALS['flashed']);

harness_section('the form classes mark the field');
$html = $render(static fn () => ContactForm::your_name());
pin('aria-invalid', 1, substr_count($html, 'aria-invalid="true"'));
pin('aria-describedby points at the line', 1, substr_count($html, 'aria-describedby="yourName-error"'));
pin('the line follows the input', 1, substr_count($html, '<p class="oe-field-error" id="yourName-error">Enter your name</p>'));
$html = $render(static fn () => ContactForm::your_email());
pin('the e-mail field too', 1, substr_count($html, 'id="yourEmail-error">Enter a valid e-mail address</p>'));
$html = $render(static fn () => ContactForm::your_message());
pin('a field with no error stays plain', 0, substr_count($html, 'aria-invalid') + substr_count($html, 'oe-field-error'));

$session->_setForm('form_errors', array('/title' => 'Add a title (English).', '/price' => '<b>Price</b>'));
$html = $render(static fn () => ItemForm::title_input('title', 'en_US'));
pin('the title input is marked', 1, substr_count($html, 'aria-invalid="true"'));
pin('with its own line', 1, substr_count($html, 'id="titleen_US-error">Add a title (English).</p>'));
$html = $render(static fn () => ItemForm::description_textarea('description', 'en_US'));
pin('a description with no error is plain', 0, substr_count($html, 'aria-invalid'));
$html = $render(static fn () => ItemForm::price_input_text(array('i_price' => null)));
pin('the message is escaped', 1, substr_count($html, '&lt;b&gt;Price&lt;/b&gt;</p>'));

harness_section('every wired control is marked with its own line');
$session->_setForm('form_errors', array(
    '/description' => 'Add a description.', '/category_id' => 'Pick a category.', '/country' => 'Pick a country.',
    '/region' => 'Pick a region.', '/city' => 'Pick a city.', '/message' => 'Write a message',
));
$marked = static function (string $html, string $id, string $message): array {
    return array(
        substr_count($html, 'aria-invalid="true"'),
        substr_count($html, 'aria-describedby="' . $id . '-error"'),
        substr_count($html, '<p class="oe-field-error" id="' . $id . '-error">' . $message . '</p>'),
    );
};
$controls = array(
    'the description'    => array(static fn () => ItemForm::description_textarea('description', 'en_US'), 'descriptionen_US', 'Add a description.'),
    'the category'       => array(static fn () => ItemForm::category_select(array(array('pk_i_id' => 1, 's_name' => 'Cars')), array('pk_i_id' => 0)), 'catId', 'Pick a category.'),
    'the country'        => array(static fn () => ItemForm::country_select(array(array('pk_c_code' => 'ES', 's_name' => 'Spain')), array('pk_i_id' => 0)), 'countryId', 'Pick a country.'),
    'the region'         => array(static fn () => ItemForm::region_select(array(array('pk_i_id' => 1, 's_name' => 'Madrid')), array('pk_i_id' => 0)), 'regionId', 'Pick a region.'),
    'the city'           => array(static fn () => ItemForm::city_select(array(array('pk_i_id' => 1, 's_name' => 'Madrid')), array('pk_i_id' => 0)), 'cityId', 'Pick a city.'),
    'a generic textarea' => array(static fn () => ContactForm::your_message(), 'message', 'Write a message'),
);
foreach ($controls as $label => [$draw, $id, $message]) {
    pin($label . ': aria-invalid, aria-describedby and its line', array(1, 1, 1), $marked($render($draw), $id, $message));
}

harness_section('each form name points at the validator field');
$pointers = array(
    'catId' => '/category_id', 'countryId' => '/country', 'countryName' => '/country', 'regionId' => '/region',
    'regionName' => '/region', 'cityId' => '/city', 'cityName' => '/city', 'cityArea' => '/city_area',
    'contactName' => '/contact_name', 'contactEmail' => '/contact_email', 'contactPhone' => '/contact_phone',
    'title[en_US]' => '/title', 'description[en_US]' => '/description', 'yourName' => '/yourName',
);
$found = array();
foreach (array_keys($pointers) as $name) {
    $found[$name] = Form::errorPointer($name);
}
pin('every mapped name', $pointers, $found);
$marked = array_filter(array_merge(array_values($pointers), array('/price', '/currency', '/address', '/zip', '/yourEmail', '/phoneNumber', '/message', '/friendName', '/friendEmail')), static fn (string $p): bool => Form::marksPointer($p));
pin('each one a form prints is marked', 23, count($marked));
pin('photos are not', false, Form::marksPointer('/photos'));

harness_section('an InvalidException gives the map and the line');
$e = InvalidException::all(array(
    ListingValidator::entry('/title', 'required', 'Add a title (English).'),
    ListingValidator::entry('/title', 'too_long', 'Title too long (English).'),
    ListingValidator::entry('/price', 'invalid', 'Price must be a number.'),
));
pin('first message per pointer', array('/title' => 'Add a title (English).', '/price' => 'Price must be a number.'), $e->byPointer());
pin('several fields: one pointer line', 'Check the fields marked below.', osc_form_error_line($e->byPointer()));
pin('one field: its message', 'Price must be a number.', osc_form_error_line(array('/price' => "Price must be a number.\n")));
pin(
    'a field no form marks keeps its message in the line',
    'Check the fields marked below. Too many photos.',
    osc_form_error_line(array('/title' => 'Add a title.', '/photos' => 'Too many photos.'))
);
pin(
    'nothing marked: every message',
    'Too many photos. Pick a size.',
    osc_form_error_line(array('/photos' => 'Too many photos.', '/product/options' => 'Pick a size.'))
);

harness_section('ListingValidator words an empty title and a short one apart');
$data = array(
    'photos' => array(), 'title' => array('en_US' => ''), 'description' => array('en_US' => 'A long enough text'),
    'catId' => 0, 'price' => null, 'countryName' => 'Spain', 'regionName' => 'Madrid', 'cityName' => 'Madrid',
    'cityArea' => '', 'address' => '', 'contactPhone' => '',
);
$title = static function (array $data): array {
    $found = array();
    foreach ((new ListingValidator())->common($data) as $error) {
        if ($error['pointer'] === '/title') {
            $found[] = array($error['code'], $error['message']);
        }
    }

    return $found;
};
$found = $title($data);
pin('an empty title is required', 'required', $found[0][0] ?? '');
pin('and asks for one', true, str_starts_with($found[0][1] ?? '', 'Add a title'));
$data['title']['en_US'] = '!!!';
$found                  = $title($data);
pin('typed but no letter or number is too short', 'too_short', $found[0][0] ?? '');
$data['title']['en_US'] = '';
$data['description']['en_US'] = '';
$codes = array();
foreach ((new ListingValidator())->common($data) as $error) {
    if ($error['pointer'] === '/description') {
        $codes[] = $error['code'];
    }
}
pin('an empty description is required', array('required'), $codes);

exit(harness_result());
