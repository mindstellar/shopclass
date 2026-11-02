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
 * The shop weight unit: metric or imperial display and input, grams stored, and the product form
 * turning typed ounces into grams.
 * Usage: php tests/weight-unit.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['weightUnit'] = '';
class Preference
{
    public static function getInstance()
    {
        return new self();
    }

    public function get($key, $section = 'osclass')
    {
        return $key === 'weight_unit' ? $GLOBALS['weightUnit'] : '';
    }
}
function __($key, $domain = 'core')
{
    return $key;
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPreference.php';

$variantFields = static function (array $raw, bool $formUnit = true): array {
    $method = new ReflectionMethod(mindstellar\listing\ListingInput::class, 'variantFields');

    return $method->invoke(null, $raw, 2, $formUnit);
};
$shownWeight = static function (int $grams): string {
    $method = new ReflectionMethod(mindstellar\listing\ListingInput::class, 'shownFields');

    return $method->invoke(null, array('i_weight' => $grams), null)['weight'];
};

harness_section('metric (the default)');
check('unset preference is metric', osc_weight_unit() === 'metric');
check('450 g', osc_weight_display(450) === '450 g');
check('1250 g is 1.25 kg', osc_weight_display(1250) === '1.25 kg');
check('2000 g is 2 kg', osc_weight_display(2000) === '2 kg');
check('input is grams', osc_weight_input(454) === '454');
check('parse keeps grams', osc_weight_parse('454') === 454);
check('parse of text is 0', osc_weight_parse('abc') === 0);
check('form saves grams as typed', $variantFields(array('weight' => '454'))['i_weight'] === 454);
check('form keeps a fraction for the validator', $variantFields(array('weight' => '1.5'))['i_weight'] === '1.5');
check('the edit form shows grams', $shownWeight(454) === '454');
check('2001 g is 3 started kilos', osc_weight_started(2001) === 3);

harness_section('imperial');
$GLOBALS['weightUnit'] = 'imperial';
check('imperial preference', osc_weight_unit() === 'imperial');
check('8.01 oz', osc_weight_display(227) === '8.01 oz');
check('454 g is 1 lb', osc_weight_display(454) === '1 lb');
check('1 kg is 2.2 lb', osc_weight_display(1000) === '2.2 lb');
check('454 g shows as 16.01 oz', osc_weight_input(454) === '16.01');
check('16 oz parse to 454 g', osc_weight_parse('16') === 454);
check('a comma works as the decimal point', osc_weight_parse('1,5') === 43);
check('grams round trip through ounces', (static function (): bool {
    for ($g = 0; $g <= 5000; $g++) {
        if (osc_weight_parse(osc_weight_input($g)) !== $g) {
            return false;
        }
    }

    return true;
})());
check('form saves 16 oz as 454 g', $variantFields(array('weight' => '16'))['i_weight'] === 454);
check('form leaves text for the validator', $variantFields(array('weight' => 'abc'))['i_weight'] === 'abc');
check('an empty weight stays empty', $variantFields(array('weight' => ''))['i_weight'] === null);
check('the API and imports send grams', $variantFields(array('weight' => '500'), false)['i_weight'] === 500);
check('the edit form shows 454 g as 16.01 oz', $shownWeight(454) === '16.01');
check('16 oz is 1 started lb', osc_weight_started(osc_weight_parse('16')) === 1);
check('48 oz is 3 started lb', osc_weight_started(osc_weight_parse('48')) === 3);
check('455 g is 2 started lb', osc_weight_started(455) === 2);
check('nothing is 0', osc_weight_started(0) === 0);

exit(harness_result());
