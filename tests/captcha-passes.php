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
 * Captcha::passes() asks for a captcha only when the form's own switch and the global captcha
 * are both on, and then only a right answer passes. Each feature reads its own switch.
 *
 * DB-free.  Usage: php tests/captcha-passes.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\security\Captcha;

$GLOBALS['captcha'] = array('enabled' => true, 'answer' => false, 'items' => false, 'comments' => false, 'reports' => false);

if (!function_exists('osc_captcha_enabled')) {
    function osc_captcha_enabled(): bool
    {
        return $GLOBALS['captcha']['enabled'];
    }
}
if (!function_exists('osc_check_captcha')) {
    function osc_check_captcha(): bool
    {
        return $GLOBALS['captcha']['answer'];
    }
}
if (!function_exists('osc_recaptcha_items_enabled')) {
    function osc_recaptcha_items_enabled(): bool
    {
        return $GLOBALS['captcha']['items'];
    }
}
if (!function_exists('osc_recaptcha_comments_enabled')) {
    function osc_recaptcha_comments_enabled(): bool
    {
        return $GLOBALS['captcha']['comments'];
    }
}
if (!function_exists('osc_recaptcha_reports_enabled')) {
    function osc_recaptcha_reports_enabled(): bool
    {
        return $GLOBALS['captcha']['reports'];
    }
}
if (!function_exists('_m')) {
    function _m(string $s): string
    {
        return $s;
    }
}

/** Run passes() with only $switch on (null: no feature switch), the captcha enabled or not, and an answer. */
$passes = static function (?string $feature, ?string $switch, bool $enabled, bool $answer): bool {
    $GLOBALS['captcha'] = array(
        'enabled'  => $enabled,
        'answer'   => $answer,
        'items'    => $switch === 'items',
        'comments' => $switch === 'comments',
        'reports'  => $switch === 'reports',
    );

    return Captcha::passes($feature);
};

harness_section('Forms that always ask');
check('wrong answer fails', !$passes(null, null, true, false));
check('right answer passes', $passes(null, null, true, true));
check('captcha disabled passes', $passes(null, null, false, false));

foreach (array('items', 'comments', 'reports') as $feature) {
    harness_section("Feature '$feature'");
    check('own switch on, wrong answer fails', !$passes($feature, $feature, true, false));
    check('own switch on, right answer passes', $passes($feature, $feature, true, true));
    check('own switch on, captcha disabled passes', $passes($feature, $feature, false, false));
    check('own switch off passes', $passes($feature, null, true, false));
    foreach (array('items', 'comments', 'reports') as $other) {
        if ($other !== $feature) {
            check("only '$other' switch on passes", $passes($feature, $other, true, false));
        }
    }
}

harness_section('Unknown feature');
$thrown = null;
try {
    Captcha::passes('signup');
} catch (\InvalidArgumentException $e) {
    $thrown = $e;
}
check('an unknown feature throws InvalidArgumentException', $thrown !== null);

harness_section('failMessage');
pin('names the security check', 'Please complete the security check.', Captcha::failMessage());

exit(harness_result());
