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
 * What the admin listing form says when a save is refused: no false "needs a title" line for
 * a language when another one has a title, one plain line when none has, and locale names
 * instead of codes.
 *
 * DB-free.  Usage: php tests/listing-admin-errors.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

define('OSC_DEBUG', false); // the controller's destructor reads it

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';

function _m($text)
{
    return $text;
}

foreach (array('hLocale', 'hValidate') as $helper) {
    require_once ABS_PATH . 'oc-includes/osclass/helpers/' . $helper . '.php';
}

use mindstellar\listing\ListingValidator;

View::getInstance()->_exportVariableToView('locales', array(
    array('pk_c_code' => 'en_US', 's_name' => 'English'),
    array('pk_c_code' => 'hi_IN', 's_name' => 'Hindi (India)'),
));

$summary = static function (array $title, array $refused): array {
    $controller = (new ReflectionClass(CAdminItems::class))->newInstanceWithoutConstructor();
    $method     = new ReflectionMethod($controller, 'itemErrors');

    return $method->invoke($controller, $refused, array('title' => $title));
};

harness_section('a title in one language');
$errors = $summary(array('en_US' => 'Red car', 'hi_IN' => ''), array(ListingValidator::entry('/contact_email', 'invalid', 'Email invalid.')));
pin('no title line for the empty language', array('Email invalid.'), array_values($errors));

harness_section('no title anywhere');
$errors = $summary(array('en_US' => '', 'hi_IN' => ''), array(
    ListingValidator::entry('/title', 'required', 'Add a title (English).'),
    ListingValidator::entry('/title', 'too_short', 'Title too short (Hindi (India)).'),
));
pin('one summary line', 'Add a title in at least one language.', $errors['title']['summary'] ?? null);
pin('the validator\'s own lines are not listed twice', array('title'), array_keys($errors));

harness_section('the field each line names');
$errors = $summary(array('en_US' => ''), array(
    ListingValidator::entry('/title', 'required', 'Add a title (English).'),
    ListingValidator::entry('/description', 'too_short', 'Description too short (English).'),
    ListingValidator::entry('/city', 'too_short', 'City too short.'),
    ListingValidator::entry('/city', 'too_long', 'City too short.'),
));
pin('the form is told the field of each listed line, in order', array('/description', '/city', '/city'), View::getInstance()->_get('editorErrorFields'));
pin('beside the lines it lists', array('Description too short (English).', 'City too short.', 'City too short.'), array_values(array_filter($errors, 'is_int', ARRAY_FILTER_USE_KEY)));

harness_section('locale names');
pin('a locale is named, not coded', 'Hindi (India)', osc_locale_name_of('hi_IN'));
pin('an unknown one keeps its code', 'xx_XX', osc_locale_name_of('xx_XX'));

exit(harness_result());
