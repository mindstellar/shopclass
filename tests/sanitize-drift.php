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
 * Pins two sanitize/escape fixes so they don't drift back:
 *
 * - Sanitize::username() keeps dots, unlike osc_sanitize_username(). The
 *   front-end username controllers must call Sanitize::username(), the same
 *   as UserActions, or a dotted username registered on the front end can
 *   never pass its own availability check or username change.
 * - Escape::js() must match osc_esc_js() line for line; the two-array
 *   str_replace() it used to call breaks under PHP 8.
 *
 * Usage: php tests/sanitize-drift.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\utility\Escape;
use mindstellar\utility\Sanitize;

harness_section('Sanitize::username() keeps dots');

pin('a dotted username is kept as-is', 'john.doe', (new Sanitize())->username('john.doe'));

harness_section('front-end username controllers call Sanitize::username(), not osc_sanitize_username()');

foreach (array(
    'oc-includes/osclass/classes/controller/CWebUser.php',
    'oc-includes/osclass/classes/controller/CWebAjax.php',
) as $relPath) {
    $src = (string) file_get_contents(ABS_PATH . $relPath);
    check($relPath . ' calls Sanitize username()', (bool) preg_match('/Sanitize\(\)\)->username\(/', $src));
    check($relPath . ' no longer calls osc_sanitize_username(', strpos($src, 'osc_sanitize_username(') === false);
}

harness_section('Escape::js() matches osc_esc_js()');

$input = "a\nb<br>Array";

pin('Escape::js() converts newlines and <br> without leaking the word Array', 'a\nb\nArray', Escape::js($input));
pin('Escape::js() matches osc_esc_js() for the same input', osc_esc_js($input), Escape::js($input));

exit(harness_result());
