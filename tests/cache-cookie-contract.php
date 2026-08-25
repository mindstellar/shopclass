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
 * Tests for the HTTP caching contract's cookie allowlist.
 *
 * osc_cache_relevant_cookies() is the one list a reverse proxy and the app must agree on, and
 * both failure directions are silent — a name that is not a real wire cookie never matches, and
 * a missing name lets a personalized response be stored under a URL that has no such component
 * in its cache key. 6.2.0.rc2 hit both at once: it listed Cookie container KEYS (oc_userId,
 * oc_adminId) as if they were cookie names, and in replacing them dropped oc_userLocale, which
 * is a genuine standalone cookie. A language-switched anonymous visitor was then served — and
 * had their translated page stored for — everyone else.
 *
 * DB-free. Usage:  php tests/cache-cookie-contract.php
 */

require_once __DIR__ . '/lib/harness.php';

// hHttpCache is a pure helper; osc_apply_filter is the only core function it reaches for.
if (!function_exists('osc_apply_filter')) {
    function osc_apply_filter($tag, $value)
    {
        return $value;
    }
}
require_once __DIR__ . '/../oc-includes/osclass/helpers/hHttpCache.php';

$root     = dirname(__DIR__);
$cookies  = osc_cache_relevant_cookies();

// Cookie names PHP itself emits, so they are never found in a literal setcookie() call.
$sessionNames = array('osclass', 'PHPSESSID', session_name());

harness_section('every allowlisted name is a real wire cookie, not a container key');
foreach ($cookies as $name) {
    if (in_array($name, $sessionNames, true)) {
        check("$name — PHP session cookie (exempt)", true);
        continue;
    }
    $found = array();
    foreach (array('oc-includes/osclass', 'oc-admin') as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));
        foreach ($it as $f) {
            if ($f->isFile() && substr($f->getFilename(), -4) === '.php') {
                $src = file_get_contents($f->getPathname());
                if (strpos($src, "setcookie('" . $name . "'") !== false) {
                    $found[] = $f->getFilename();
                }
            }
        }
    }
    check(
        "$name is written by a literal setcookie()",
        $found !== array(),
        $found === array()
            ? 'no setcookie() writes this name — a proxy can never match it'
            : 'set in ' . implode(', ', array_unique($found))
    );
}

harness_section('the locale cookie is present — it changes the rendered body');
check(
    'oc_userLocale is allowlisted',
    in_array('oc_userLocale', $cookies, true),
    'osc_current_user_locale() reads it and it selects the .mo file, so two visitors on one '
    . 'URL get different HTML; without it that HTML is stored and replayed to both'
);

harness_section('app allowlist and the reference proxy configs agree');
$proxyFiles = array('.docker/nginx/microcache.conf', '.docker/prod/entrypoint.sh');
foreach ($proxyFiles as $rel) {
    $src = file_get_contents($root . '/' . $rel);
    if (!preg_match('/"~\(\^\|;\\\\s\*\)\(([^)]+)\)="/', $src, $m)) {
        check("$rel — bypass map found", false, 'no $mc_private cookie map matched');
        continue;
    }
    $inProxy = explode('|', $m[1]);
    // The app resolves session_name() at runtime; the proxy must cover both possibilities.
    $expected = array_values(array_unique(array_merge(
        array_diff($cookies, array(session_name())),
        array('osclass', 'PHPSESSID')
    )));
    sort($expected);
    $got = $inProxy;
    sort($got);
    pin("$rel lists exactly the app's cookies", implode(',', $expected), implode(',', $got));
}

$fail = $GLOBALS['failCount'];
echo "\n" . ($fail === 0
        ? "ALL PASS ({$GLOBALS['okCount']})\n"
        : "FAILED: $fail (" . implode(', ', $GLOBALS['failLabels']) . ")\n");
exit($fail === 0 ? 0 : 1);
