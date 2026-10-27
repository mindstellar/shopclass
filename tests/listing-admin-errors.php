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

$summary = static function (string $message, array $title): array {
    $controller = (new ReflectionClass(CAdminItems::class))->newInstanceWithoutConstructor();
    $method     = new ReflectionMethod($controller, 'itemErrors');
    $method->setAccessible(true);

    return $method->invoke($controller, $message, array('title' => $title));
};

harness_section('a title in one language');
$errors = $summary("Email invalid.\n", array('en_US' => 'Red car', 'hi_IN' => ''));
pin('no title line for the empty language', array('Email invalid.'), array_values($errors));

harness_section('no title anywhere');
$errors = $summary("Title too short (English).\nTitle too short (Hindi (India)).\n", array('en_US' => '', 'hi_IN' => ''));
pin('one summary line', 'Add a title in at least one language.', $errors['title']['summary'] ?? null);
pin('the validator\'s own lines are not listed twice', array('title'), array_keys($errors));

harness_section('locale names');
pin('a locale is named, not coded', 'Hindi (India)', ListingValidator::localeName('hi_IN'));
pin('an unknown one keeps its code', 'xx_XX', ListingValidator::localeName('xx_XX'));

exit(harness_result());
