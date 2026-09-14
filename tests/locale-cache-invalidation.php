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
 * osc_invalidate_locale_cache() — the flush every locale write has to do.
 *
 * osc_settings_locales() memoises the enabled-locale list for the request, because a page
 * of translated fields would otherwise ask for it once per field. That memo is wrong the
 * instant a locale is added, enabled, disabled or deleted, and nothing about the wrongness
 * shows: the languages screen redirects to itself and the row it just changed is drawn
 * from the list as it was before.
 *
 * So the invalidation is pinned twice over -- the helper itself, and every write path that
 * has to call it. The second half is a source scan that derives the set of locale-writing
 * actions from the controller rather than listing them, so an action added later is held
 * to the same rule instead of being quietly exempt.
 *
 * DB-free: everything the helper reaches outside itself is stubbed.
 *
 * Usage: php tests/locale-cache-invalidation.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';

$GLOBALS['locales'] = array(
    array('pk_c_code' => 'en_US', 's_name' => 'English'),
);
$GLOBALS['queries'] = 0;
$GLOBALS['fired']   = array();

// Defined before the helpers load, so there is no redeclaration.
function osc_add_hook($hook, $callback = null, $priority = 5)
{
}

/** Records what fired, and what the list looked like at that moment. */
function osc_run_hook($hook, ...$args)
{
    $GLOBALS['fired'][] = array(
        'hook'    => $hook,
        'args'    => $args,
        'locales' => osc_settings_locales(),
    );
}

function osc_apply_filter($hook, $content = '', ...$args)
{
    return $content;
}

function osc_base_url($withIndex = false)
{
    return 'http://example.test/';
}

function osc_current_user_locale()
{
    return 'en_US';
}

function osc_get_locales()
{
    return $GLOBALS['locales'];
}

function __($key, $domain = 'core')
{
    return $key;
}

/** Counts reads, which is the point of the memo the helper drops. */
class OSCLocale
{
    public static function newInstance()
    {
        return new self();
    }

    public function listAllEnabled($isBo = false, $indexedByPk = false)
    {
        $GLOBALS['queries']++;

        return $GLOBALS['locales'];
    }
}

require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hCache.php';

/** Every `case ('name'):` arm of the outer switch in $source, keyed by name. */
function case_blocks(string $source): array
{
    if (!preg_match_all("/case \('([a-z_]+)'\):/", $source, $m, PREG_OFFSET_CAPTURE)) {
        return array();
    }

    $blocks = array();
    foreach ($m[1] as $i => $match) {
        $start          = $m[0][$i][1];
        $end            = $m[0][$i + 1][1] ?? strlen($source);
        $blocks[$match[0]] = substr($source, $start, $end - $start);
    }

    return $blocks;
}

/** The body of one method, matched by counting braces from its opening one. */
function method_body(string $source, string $name): string
{
    $at = strpos($source, 'function ' . $name . '(');
    if ($at === false) {
        return '';
    }
    $open  = strpos($source, '{', $at);
    $depth = 0;
    for ($i = $open; $i < strlen($source); $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $open, $i - $open + 1);
            }
        }
    }

    return '';
}

harness_section('the enabled-locale list is read once per request');

pin('the first read goes to the database', array('en_US' => 'English'), osc_settings_locales());
pin('...and is one query', 1, $GLOBALS['queries']);
pin('the second read is the memo', array('en_US' => 'English'), osc_settings_locales());
pin('...and is no query at all', 1, $GLOBALS['queries']);

harness_section('osc_invalidate_locale_cache — what a locale write has to do');

// A locale is enabled. Without the flush every translated field drawn after this point,
// and every locale the store writes, is still working from the list above.
$GLOBALS['locales'][] = array('pk_c_code' => 'es_ES', 's_name' => 'Español');
pin('until it is called, the memo still hides the new locale', array('en_US' => 'English'), osc_settings_locales());

osc_invalidate_locale_cache();
pin(
    'afterwards the list is the one the database holds',
    array('en_US' => 'English', 'es_ES' => 'Español'),
    osc_settings_locales()
);

$fired = array_values(array_filter($GLOBALS['fired'], static function ($entry) {
    return $entry['hook'] === 'invalidate_locale_cache';
}));
pin('the hook fires once', 1, count($fired));
// The rest of this family hands the id of what changed; the locale list is a whole, so
// there is nothing to hand and a listener that expects an argument would be wrong.
pin('and carries no argument', array(), $fired[0]['args'] ?? null);
// A purge listener reading the list has to see the new one, or it caches the stale answer
// it was fired to prevent.
pin(
    'the flush happens before the hook, not after it',
    array('en_US' => 'English', 'es_ES' => 'Español'),
    $fired[0]['locales'] ?? null
);

harness_section('every locale-writing action on the languages screen invalidates');

$controller = (string)file_get_contents(
    ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminLanguages.php'
);
$blocks = case_blocks($controller);

// Derived from the source, not listed: an action added later that writes a locale is held
// to the same rule instead of being exempt for having been written after this test. The
// markers cover the inherited DAO writes, the two methods the model owns and the raw
// paths -- a case naming the table at all counts as writing, because the cost of a false
// positive is one extra call to the flush and the cost of a miss is a stale list.
$writes = array(
    '->update(',
    '->insert(',
    '->delete(',
    'insertLocaleInfo(',
    'deleteLocale(',
    'osc_checkLocales(',
    'osc_db_query(',
    't_locale',
);
$mutating = array();
foreach ($blocks as $name => $body) {
    foreach ($writes as $marker) {
        if (strpos($body, $marker) !== false) {
            $mutating[] = $name;
            break;
        }
    }
}
sort($mutating);

pin(
    'the actions that write a locale are the eight this screen has',
    array(
        'add_post',
        'delete',
        'disable_bo_selected',
        'disable_selected',
        'edit_post',
        'enable_bo_selected',
        'enable_selected',
        'import_locations',
    ),
    $mutating
);

foreach ($mutating as $name) {
    check(
        "'" . $name . "' drops the memoised locale list",
        strpos($blocks[$name], 'osc_invalidate_locale_cache(') !== false,
        'CAdminLanguages::doModel() case ' . $name . ' writes a locale and leaves the memo in place'
    );
}

harness_section('and so does the model, which a plugin can call directly');

// OSCLocale extends DAO, so update()/insert()/delete() are inherited and are not overridden
// here on purpose: the CI check that reports a legacy-DAO write inside a transaction
// resolves calls against the receiving class, and an override would change what it sees.
// The two methods the model owns carry the flush instead.
$model = (string)file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/model/OSCLocale.php');
foreach (array('deleteLocale', 'insertLocaleInfo') as $method) {
    $body = method_body($model, $method);
    check($method . '() has a body to read', $body !== '');
    check(
        'OSCLocale::' . $method . '() drops the memoised locale list',
        strpos($body, 'osc_invalidate_locale_cache(') !== false,
        'a caller reaching the model directly leaves the memo in place'
    );
}

exit(harness_result());
