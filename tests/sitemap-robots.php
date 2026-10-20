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
 * Pins the default robots.txt: it disallows the admin path of the install, at the
 * site root or in a subdirectory.
 *
 * Usage: php tests/sitemap-robots.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

$GLOBALS['robots_base'] = 'http://example.com/';

define('WEB_PATH', 'http://example.com/');

function osc_apply_filter($hook, $content = '', ...$args)
{
    return $hook === 'base_url' ? $GLOBALS['robots_base'] : $content;
}

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hDefines.php';

harness_section('default robots.txt');

$GLOBALS['robots_base'] = 'http://example.com/';
pin(
    'root install',
    "User-agent: *\nDisallow: /oc-admin/\nSitemap: http://example.com/sitemapindex.xml\n",
    Sitemap::defaultRobotsTxt()
);

$GLOBALS['robots_base'] = 'http://example.com/shop/';
pin(
    'subdirectory install',
    "User-agent: *\nDisallow: /shop/oc-admin/\nSitemap: http://example.com/shop/sitemapindex.xml\n",
    Sitemap::defaultRobotsTxt()
);

exit(harness_result());
