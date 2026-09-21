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
 * What the admin user search promises.
 *
 * It promised none of it for a while. `User::_search()` binds its values and allowlists
 * each key as a column name, and the admin screen was still passing whole SQL fragments
 * as the key -- "s_email LIKE 'x' OR s_name LIKE 'x'". Those keys failed the allowlist
 * and were skipped, so every search ran with no WHERE at all and answered the full list.
 * Nothing failed; the screen just quietly stopped filtering.
 *
 * So what needs pinning is the shape of a condition, not the SQL:
 *
 *  - a structured condition reaches the query, and an unknown operator cannot;
 *  - several columns in one condition are OR'd, not AND'd, and stay bound;
 *  - a bare term matches anywhere, because somebody typing half an address expects that;
 *  - a `%` or `_` the admin typed matches itself rather than acting as a wildcard;
 *  - `*` is still the wildcard it always was.
 *
 * Usage:  php tests/models/usersearch.php          (standalone, own scratch database)
 *         php tests/run-models.php usersearch      (as part of the suite)
 */

if (!function_exists('osc_base_url')) {
    function osc_base_url($dummy = false)
    {
        return 'http://localhost/';
    }
}
if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 0);
}
if (!function_exists('osc_cache_get')) {
    $GLOBALS['__usersearch_cache'] = array();
    function osc_cache_get($key, &$found = null)
    {
        if (array_key_exists($key, $GLOBALS['__usersearch_cache'])) {
            $found = true;

            return $GLOBALS['__usersearch_cache'][$key];
        }
        $found = false;

        return false;
    }

    function osc_cache_set($key, $value, $ttl = 0)
    {
        $GLOBALS['__usersearch_cache'][$key] = $value;

        return true;
    }

    function osc_cache_delete($key)
    {
        unset($GLOBALS['__usersearch_cache'][$key]);

        return true;
    }
}

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';
// The datatable registers its column filter in its constructor, so the real hook helper
// is loaded rather than stubbed -- and addHook() strips the plugins path off a hook name.
require_once dirname(__DIR__, 2) . '/oc-includes/osclass/helpers/hPlugins.php';
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return dirname(__DIR__, 2) . '/oc-content/plugins/';
    }
}

$admin = scratchdb_session('osc_models_usersearch');

require_once dirname(__DIR__, 2) . '/oc-includes/osclass/classes/datatables/UsersDataTable.php';

seed_user($admin, 'sarahcollins', 'sarah.collins@example.com');
seed_user($admin, 'michaelreed', 'michael.reed@example.com');
seed_user($admin, 'tester_one', 'tester_one@example.com');
seed_user($admin, 'pct', 'has%percent@example.com');
seed_user($admin, 'elsewhere', 'someone@other.test');

$model = User::newInstance();

/** Run a search and return the matched usernames, sorted, as one comparable string. */
$found = static function (array $conditions) use ($model): string {
    $result = $model->search(0, 100, 'pk_i_id', 'ASC', $conditions);
    $names  = array();
    foreach ($result['users'] as $row) {
        $names[] = $row['s_username'];
    }
    sort($names);

    return implode(',', $names);
};

/** What the admin screen builds for a given request, so the pins run the real path. */
$fromRequest = static function (array $params): array {
    $table  = new UsersDataTable();
    $method = new ReflectionMethod('UsersDataTable', 'getDBParams');
    $method->setAccessible(true);
    $method->invoke($table, $params);

    return $table->conditions;
};

$all = 'elsewhere,michaelreed,pct,sarahcollins,tester_one';

harness_section('A structured condition reaches the query');

pin(
    'one column, equality',
    'sarahcollins',
    $found(array(array('columns' => array('s_username'), 'op' => '=', 'value' => 'sarahcollins')))
);
pin(
    'one column, LIKE with a wildcard',
    'sarahcollins',
    $found(array(array('columns' => array('s_email'), 'op' => 'LIKE', 'value' => '%collins%')))
);
pin(
    'plain column => value still means equality',
    'michaelreed',
    $found(array('s_username' => 'michaelreed'))
);
pin(
    'no conditions returns everyone',
    $all,
    $found(array())
);

harness_section('Several columns are OR, not AND');

// `elsewhere` has the username "elsewhere" and the address "someone@other.test": the two
// share no substring, so each of these can only be matched through one of the columns.
// Under AND both would answer nothing.
pin(
    'matching the first column alone is enough',
    'elsewhere',
    $found(array(array('columns' => array('s_username', 's_email'), 'op' => 'LIKE', 'value' => '%elsewhere%')))
);
pin(
    'matching the second column alone is enough',
    'elsewhere',
    $found(array(array('columns' => array('s_username', 's_email'), 'op' => 'LIKE', 'value' => '%other.test%')))
);
pin(
    'two separate conditions are AND',
    '',
    $found(array(
        array('columns' => array('s_username'), 'op' => '=', 'value' => 'sarahcollins'),
        array('columns' => array('s_username'), 'op' => '=', 'value' => 'michaelreed'),
    ))
);

harness_section('A condition cannot carry SQL');

pin(
    'an unknown operator falls back to equality, it does not reach the query',
    '',
    $found(array(array('columns' => array('s_username'), 'op' => 'LIKE OR 1=1 -- ', 'value' => 'nobody')))
);
pin(
    'a column that is not a plain name is dropped, and the condition with it',
    $all,
    $found(array(array('columns' => array('s_username = 1 OR 1'), 'op' => '=', 'value' => 'x')))
);
pin(
    'a value carrying SQL is matched as text',
    '',
    $found(array(array('columns' => array('s_username'), 'op' => '=', 'value' => "' OR 1=1 -- ")))
);
pin(
    'the old raw-SQL-as-key shape still matches nothing, rather than everything',
    $all,
    $found(array("s_email LIKE 'sarah' OR s_name LIKE 'sarah'" => null))
);

harness_section('What the screen builds from a request');

pin(
    'the search box looks in e-mail and name',
    'sarahcollins',
    $found($fromRequest(array('user' => 'sarah')))
);
pin(
    'a bare term matches anywhere in the value',
    'sarahcollins',
    $found($fromRequest(array('user' => 'collins')))
);
pin(
    'a full address still matches',
    'michaelreed',
    $found($fromRequest(array('s_email' => 'michael.reed@example.com')))
);
pin(
    'part of a username matches',
    'sarahcollins',
    $found($fromRequest(array('s_username' => 'sarahcoll')))
);
pin(
    'a term nobody has matches nobody',
    '',
    $found($fromRequest(array('user' => 'zzzznothing')))
);
pin(
    'an empty box filters nothing',
    $all,
    $found($fromRequest(array('user' => '')))
);

harness_section('Wildcards the admin typed, and ones they did not');

pin(
    'a typed % matches a literal %, it does not match everything',
    'pct',
    $found($fromRequest(array('s_email' => '%')))
);
pin(
    'a typed _ matches a literal _, it is not any-single-character',
    'tester_one',
    $found($fromRequest(array('s_email' => '_')))
);
pin(
    '* is still the wildcard',
    'sarahcollins',
    $found($fromRequest(array('user' => 'sarah.c*')))
);
pin(
    '* on its own matches everyone',
    $all,
    $found($fromRequest(array('s_email' => '*')))
);

harness_section('The screen reports that a filter is on');

$table = new UsersDataTable();
$method = new ReflectionMethod('UsersDataTable', 'getDBParams');
$method->setAccessible(true);
$method->invoke($table, array('user' => 'sarah'));
pin('a search sets withFilters', true, $table->withFilters);

$clean = new UsersDataTable();
$method->invoke($clean, array());
pin('no search leaves withFilters alone', false, $clean->withFilters);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/usersearch.php */
