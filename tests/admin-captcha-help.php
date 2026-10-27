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
 * The help under the "Require a CAPTCHA" boxes names the menu path that exists. A source
 * scan: the text is a literal in the screen declarations.
 * Usage: php tests/admin-captcha-help.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';

harness_section('captcha help');
foreach (['KeywordBlockSettingsScreen', 'CommentSettingsScreen'] as $screen) {
    $src = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/admin/form/' . $screen . '.php');
    check($screen . ' points at Spam and bots, Captcha', str_contains($src, 'osc_admin_captcha_settings_path()'));
    check($screen . ' no longer names a menu entry that does not exist', !str_contains($src, 'reCAPTCHA/Turnstile'));
}
exit(harness_result());
