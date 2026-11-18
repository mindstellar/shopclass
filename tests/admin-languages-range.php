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
 * The range line under the languages table. Nothing filters that list, yet one language
 * read "Showing 1 to 1 of 1 results (filtered from 9 total results)".
 *
 * DB-free.  Usage: php tests/admin-languages-range.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');
define('OC_ADMIN', true);

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

function __($text, $domain = '')
{
    return $text;
}
function _e($text, $domain = '')
{
    echo $text;
}
function osc_run_hook($hook, ...$args)
{
}
function osc_esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES);
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hPagination.php';

/** The range line for $count languages on $page, $limit to a page. */
$range = static function (int $count, int $page, int $limit = 10): string {
    $locales = array();
    for ($i = 0; $i < $count; $i++) {
        $locales[] = array('pk_c_code' => sprintf('a%d_AA', $i));
    }
    $data = CAdminLanguages::tablePage($locales, ($page - 1) * $limit, $limit);
    ob_start();
    osc_show_pagination_admin($data + array('show_range' => true, 'iPage' => $page, 'base_url' => 'https://example.test/oc-admin/index.php?page=languages'));

    return preg_match('/<span>(Showing[^<]*)<\/span>/', (string) ob_get_clean(), $m) ? $m[1] : '';
};

pin('one language', 'Showing 1 to 1 of 1 results', $range(1, 1));
pin('twelve languages, first page', 'Showing 1 to 10 of 12 results', $range(12, 1));
pin('twelve languages, second page', 'Showing 11 to 12 of 12 results', $range(12, 2));

exit(harness_result());
