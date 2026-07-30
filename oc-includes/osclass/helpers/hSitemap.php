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
 * @package    Osclass
 * @subpackage Helpers
 * Public helpers around the core XML sitemap generator (see the Sitemap class).
 */

/**
 * The `Sitemap:` directive that advertises the sitemap index, as an absolute
 * URL. Built at runtime from osc_base_url(), so it can be appended to a
 * robots.txt without ever baking a host into a tracked file.
 *
 * @return string e.g. "Sitemap: https://example.com/sitemapindex.xml"
 */
function osc_sitemap_robots_line()
{
    return Sitemap::robotsLine();
}

/**
 * Default robots.txt body: disallow the admin panel and advertise the sitemap.
 * Intended as the seed content for the admin robots.txt editor.
 *
 * @return string
 */
function osc_sitemap_default_robots_txt()
{
    return Sitemap::defaultRobotsTxt();
}

/**
 * Pre-generate every enabled sitemap document into the object cache.
 *
 * @return array<string, bool> per-document success map
 */
function osc_sitemap_warm_cache()
{
    return Sitemap::newInstance()->warmCache();
}

/**
 * Drop every cached sitemap document so the next request rebuilds it. This is
 * the entry point behind an admin "Regenerate / Clear cache" action.
 *
 * @return void
 */
function osc_sitemap_clear_cache()
{
    Sitemap::newInstance()->clearCache();
}

/**
 * Core-served URL of a sitemap XSL stylesheet.
 *
 * @param string $file e.g. "xmlsitemap.xsl" or "xmlsitemapindex.xsl"
 *
 * @return string
 */
function osc_sitemap_stylesheet_url($file)
{
    return Sitemap::stylesheetUrl($file);
}
