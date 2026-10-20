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
 * The API meta tag on public pages: a theme that does not call osc_head() gets it from the `header`
 * hook, a theme may decline it, and a page prints it once.
 * Usage: php tests/api-head-meta.php
 */

define('OSC_CSRF_SECRET', 'api-head-meta-test-secret');
define('WEB_PATH', 'http://example.test/');

require_once __DIR__ . '/lib/api-boot.php';

function osc_esc_html($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}

function osc_get_preference($name, $section = 'osclass')
{
    return '';
}

function osc_base_url($withIndex = false)
{
    return WEB_PATH;
}

function osc_rewrite_enabled()
{
    return true;
}

function osc_is_web_user_logged_in()
{
    return false;
}

function osc_theme_supports(string $feature)
{
    return \mindstellar\theme\ThemeSupports::getInstance()->get($feature);
}

function osc_add_theme_support(string $feature, $args = true): void
{
    \mindstellar\theme\ThemeSupports::getInstance()->add($feature, $args);
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hApi.php';

$header = static function (): string {
    ob_start();
    osc_run_hook('header');

    return (string) ob_get_clean();
};

osc_add_theme_support('head', ['api' => false]);
check('a theme that declined the api part gets no tag from the hook', !str_contains($header(), 'name="shopclass-api"'));

\mindstellar\theme\ThemeSupports::getInstance()->reset();
$out = $header();
check('a theme that does not call osc_head() gets the tag from the header hook', str_contains($out, '<meta name="shopclass-api"'));
check('with a guest token', str_contains($out, '&quot;token&quot;:&quot;scs_'));
pin('a second header run prints nothing', '', $header());
pin('nor does a theme calling the helper itself', '', osc_api_session_meta());

exit(harness_result());
