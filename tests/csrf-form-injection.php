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
 * Pins which forms the shutdown injector stamps a CSRF token into.
 *
 * A token in a GET form is not protection, it is a leak: the value lands in the
 * query string, so it is shared in links, kept in referrers and access logs, and
 * differs per visitor -- which makes every search URL its own cache entry and its
 * own canonical. Every bundled theme had to remember `nocsrf` on each search form
 * to avoid it, and forgetting was silent.
 *
 * The other direction is worse: skip a POST form and its action rejects every
 * submission, so both are pinned here.
 *
 * DB-free -- replaceForms() is pure string work over markup.
 *
 * Usage:  php tests/csrf-form-injection.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';

/** Just the matcher, with a recognisable stand-in for the token markup. */
final class CsrfMatcher
{
    public function tokenForm(): string
    {
        return '<!--TOKEN-->';
    }

    public function replaceForms($form_data_html)
    {
        $src = file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/Csrf.php');
        preg_match('/public function replaceForms.*?\n    }/s', $src, $m);
        $body = preg_replace('/^\s*public function replaceForms\([^)]*\)\s*\{/', '', $m[0]);
        $body = preg_replace('/\}\s*$/', '', $body);

        return eval($body);
    }
}

$csrf   = new CsrfMatcher();
$tokened = static function (string $html) use ($csrf) {
    return strpos($csrf->replaceForms($html), '<!--TOKEN-->') !== false;
};

harness_section('POST forms are protected');
check('a plain post form', $tokened('<form action="/" method="post"></form>'));
check('uppercase METHOD', $tokened('<form action="/" METHOD="POST"></form>'));
check('a form with no method at all', $tokened('<form action="/"></form>'));

harness_section('GET forms are left alone');
// The search bar is the reason: its action ends up in the address bar.
check('method="get"', !$tokened('<form action="/" method="get" role="search"></form>'));
check("method='get' single-quoted", !$tokened("<form action='/' method='get'></form>"));
check('method=get unquoted', !$tokened('<form action="/" method=get></form>'));
check('METHOD="GET" uppercase', !$tokened('<form action="/" METHOD="GET"></form>'));
check('spaces around the equals', !$tokened('<form action="/" method = "get"></form>'));

harness_section('the nocsrf opt-out still works');
check('a post form marked nocsrf', !$tokened('<form class="nocsrf" method="post"></form>'));

harness_section('a method that merely contains "get" is still protected');
// The word appears in plenty of attribute values; only the method itself counts.
check('an action containing get', $tokened('<form action="/widget/get" method="post"></form>'));
check('a name containing get', $tokened('<form name="budget" method="post"></form>'));
check('a data attribute naming get', $tokened('<form data-x="get" method="post"></form>'));

harness_section('several forms on one page');
$mixed = '<form method="get"></form><form method="post"></form>';
$out   = $csrf->replaceForms($mixed);
pin('exactly one of the two is stamped', 1, substr_count($out, '<!--TOKEN-->'));

exit(harness_result());
