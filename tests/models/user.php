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
 * Characterization pins for the User model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * the method bodies move to the parameterized query layer — with one exception.
 * findByIdSecret and findByIdPasswordSecret were converted ahead of the rest of
 * the model, to close a live authentication bypass (the secret compared through
 * escape() coerced a VARCHAR to a number, so "0" matched almost any account).
 * Their string-comparison behaviour is pinned here and, authoritatively, in
 * tests/security-secret-coercion.php; those two are NOT expected to reproduce the
 * old coercion.
 *
 * findByPrimaryKey memoizes through osc_cache_*. The bootstrap does not load the
 * real cache helpers, so a request-scoped array stand-in is defined below — the
 * same shape the default object cache has (a per-request PHP array), which is
 * what C9 is pinned against.
 *
 * Usage:  php tests/models/user.php          (standalone, own scratch database)
 *         php tests/run-models.php user      (as part of the suite)
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
    // A request-lifetime array cache, matching the default object cache's shape.
    $GLOBALS['__user_test_cache'] = array();
    function osc_cache_get($key, &$found = null)
    {
        if (array_key_exists($key, $GLOBALS['__user_test_cache'])) {
            $found = true;

            return $GLOBALS['__user_test_cache'][$key];
        }
        $found = false;

        return false;
    }
    function osc_cache_set($key, $value, $ttl = 0)
    {
        $GLOBALS['__user_test_cache'][$key] = $value;

        return true;
    }
    function osc_test_cache_flush()
    {
        $GLOBALS['__user_test_cache'] = array();
    }
}

if (!function_exists('osc_verify_password')) {
    // findByCredentials verifies the hash; the fixtures never store a real one,
    // so a stand-in that only accepts a known sentinel is enough to exercise the
    // wrong-password path deterministically.
    function osc_verify_password($password, $hash)
    {
        return $hash === 'KNOWN-GOOD-HASH' && $password === 'the-password';
    }
}

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';
// deleteUser fires delete_user / after_delete_user hooks unconditionally; load
// the real hook helper rather than stubbing it (amendment L).
require_once dirname(__DIR__, 2) . '/oc-includes/osclass/helpers/hPlugins.php';

$admin = scratchdb_session('osc_models_user');

seed_country($admin);
$regionId = seed_region($admin);
seed_locale($admin);
seed_locale($admin, 'fr_FR', 'French');

$prefix = DB_TABLE_PREFIX;
$model  = User::newInstance();

/** Insert a user description row with raw mysqli. */
$seedDescription = static function (int $uid, string $locale, string $info) use ($admin, $prefix) {
    seed_exec(
        $admin,
        "INSERT INTO {$prefix}t_user_description (fk_i_user_id, fk_c_locale_code, s_info) VALUES (?, ?, ?)",
        'iss',
        array($uid, $locale, $info)
    );
};

$rawCount = static function (string $sql) use ($admin): int {
    return (int)$admin->query($sql)->fetch_assoc()['c'];
};

$victim = seed_user($admin, 'victim', 'victim@example.test', 1, 1);
$admin->query("UPDATE {$prefix}t_user SET s_secret = 'aB3xK9qLmZ', i_items = 3 WHERE pk_i_id = $victim");
$seedDescription($victim, 'en_US', 'About the victim');

$other = seed_user($admin, 'other', 'other@example.test', 1, 1);

if (function_exists('osc_test_cache_flush')) {
    osc_test_cache_flush();
}

/* ----------------------------------------------------------------------------
 * Surface (C2).
 * ------------------------------------------------------------------------- */
harness_section('User: public surface');

check('User still extends DAO', is_subclass_of('User', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $prefix . 't_user', $model->getTableName());
pin('primary key is unchanged', 'pk_i_id', $model->getPrimaryKey());
pin('findByEmail signature is unchanged', 'public findByEmail($email, $locale = NULL)', harness_method_signature('User', 'findByEmail'));
pin('findByIdSecret signature is unchanged', 'public findByIdSecret($id, $secret, $locale = NULL)', harness_method_signature('User', 'findByIdSecret'));
pin('countUsers keeps its raw-condition default', "public countUsers(\$condition = 'b_enabled = 1 AND b_active = 1')", harness_method_signature('User', 'countUsers'));

/* ----------------------------------------------------------------------------
 * findByPrimaryKey and its cache (C9).
 * ------------------------------------------------------------------------- */
harness_section('User::findByPrimaryKey — lookup and cache');

osc_test_cache_flush();
$row = $model->findByPrimaryKey($victim);
check('a match returns the user', is_array($row) && ($row['pk_i_id'] ?? null) == $victim);
pin('the id round-trips as a string (C4)', (string)$victim, $row['pk_i_id']);
check('the description is attached under locale', isset($row['locale']['en_US']['s_info']));
pin('the description text is present', 'About the victim', $row['locale']['en_US']['s_info']);
pin('a missing id returns an empty array', array(), $model->findByPrimaryKey(999999));

osc_test_cache_flush();
$cold = harness_query_count(static function () use ($model, $victim) {
    $model->findByPrimaryKey($victim);
});
$warm = harness_query_count(static function () use ($model, $victim) {
    $model->findByPrimaryKey($victim);
});
check('a cold lookup issues queries', $cold > 0, (string)$cold);
pin('a warm lookup is served from cache at zero queries', 0, $warm);

/* ----------------------------------------------------------------------------
 * Email / username lookups — positive AND negative (this is an auth surface).
 * ------------------------------------------------------------------------- */
harness_section('User: credential lookups');

pin('findByEmail on a match returns the user', (string)$victim, $model->findByEmail('victim@example.test')['pk_i_id']);
/* A clean zero-row lookup returns an empty array; only a FAILED query returns
 * false (the $result == false branch), which a well-formed lookup never hits. */
pin('findByEmail on no match returns an empty array', array(), $model->findByEmail('nobody@example.test'));
pin('findByUsername on a match returns the user', (string)$victim, $model->findByUsername('victim')['pk_i_id']);
pin('findByUsername on no match returns an empty array', array(), $model->findByUsername('nobody'));
check('every value in the user row is a string or null (C4)', all_values_string(array_diff_key($model->findByEmail('victim@example.test'), array('locale' => 1))));

$creds = $model->findByCredentials('victim@example.test', 'the-password');
pin('findByCredentials with a wrong password returns an empty array', array(), $creds);

harness_section('User: the secret lookups (converted early; must compare as strings)');

check('the real secret authenticates', is_array($model->findByIdSecret($victim, 'aB3xK9qLmZ')) && count($model->findByIdSecret($victim, 'aB3xK9qLmZ')) > 0);
pin('the secret "0" matches nothing', array(), $model->findByIdSecret($victim, '0'));
pin('a wrong secret matches nothing', array(), $model->findByIdSecret($victim, 'wrong'));

/* ----------------------------------------------------------------------------
 * ajax autocomplete.
 * ------------------------------------------------------------------------- */
harness_section('User::ajax');

$ajax = $model->ajax('vic');
check('a name-prefix search finds the user', count($ajax) === 1 && $ajax[0]['value'] === 'victim');
pin('the autocomplete row is aliased to id/label/value', array('id', 'label', 'value'), array_keys($ajax[0]));
check('the label combines name and email', strpos($ajax[0]['label'], 'victim@example.test') !== false);
$byEmail = $model->ajax('other@');
check('it also matches on the email prefix', count($byEmail) === 1 && $byEmail[0]['value'] === 'other');
pin('an unmatched prefix returns an empty array', array(), $model->ajax('zzzz'));

/* ----------------------------------------------------------------------------
 * search (SQL_CALC_FOUND_ROWS, paged) — note the offset/count order.
 * ------------------------------------------------------------------------- */
harness_section('User::search and its by-name/by-email wrappers');

$all = $model->search();
check('search returns the standard shape', isset($all['rows'], $all['total_results'], $all['users']));
pin('rows counts the whole table as a string', '2', $all['rows']);
pin('total_results reflects the filter as a string', '2', $all['total_results']);

$byName = $model->searchByName(0, 10, 'pk_i_id', 'ASC', 'victim');
check('searchByName filters to the match', count($byName['users']) === 1 && $byName['users'][0]['s_username'] === 'victim');
$byMail = $model->searchByEmail(0, 10, 'pk_i_id', 'ASC', 'other@example.test');
check('searchByEmail filters to the match', count($byMail['users']) === 1 && $byMail['users'][0]['s_username'] === 'other');

/* The paged form: $start is the offset and $end the row count (LIMIT $start,$end). */
$paged = $model->search(1, 1, 'pk_i_id', 'ASC');
pin('search(start=1, end=1) skips one row and returns one', 1, count($paged['users']));
pin('and it is the second user by id', (string)$other, $paged['users'][0]['pk_i_id']);

$bogusOrder = $model->search(0, 10, 'nonsense; DROP', 'ASC');
check('a non-allowlisted order column falls back rather than injecting', count($bogusOrder['users']) === 2);

/* ----------------------------------------------------------------------------
 * countUsers — the raw-condition API.
 * ------------------------------------------------------------------------- */
harness_section('User::countUsers');

pin('the default condition counts enabled + active users as a string', '2', $model->countUsers());
$admin->query("UPDATE {$prefix}t_user SET b_active = 0 WHERE pk_i_id = $other");
pin('b_active = 1 counts only active users', '1', $model->countUsers('b_active = 1'));
pin('b_enabled = 1 counts all still-enabled users', '2', $model->countUsers('b_enabled = 1'));
$admin->query("UPDATE {$prefix}t_user SET b_active = 1 WHERE pk_i_id = $other");

/* ----------------------------------------------------------------------------
 * updateDescription — insert when absent, update when present.
 * ------------------------------------------------------------------------- */
harness_section('User::updateDescription');

$descOf = static function (string $locale) use ($admin, $prefix, $victim) {
    $r = $admin->query("SELECT s_info FROM {$prefix}t_user_description WHERE fk_i_user_id = $victim AND fk_c_locale_code = '$locale'")->fetch_assoc();
    return $r['s_info'] ?? null;
};

pin('updating an existing description reports one changed row', 1, $model->updateDescription($victim, 'en_US', 'Edited'));
/* Read back with raw mysqli: findByPrimaryKey caches and updateDescription does
 * not invalidate, so a cached read would still show the old text. */
pin('the description changed', 'Edited', $descOf('en_US'));
pin('describing an absent locale inserts it', true, (bool)$model->updateDescription($victim, 'fr_FR', 'En francais'));
pin('the inserted row exists', 'En francais', $descOf('fr_FR'));

/* ----------------------------------------------------------------------------
 * lastAccess.
 * ------------------------------------------------------------------------- */
harness_section('User::lastAccess');

pin('an unconditional last-access write reports one changed row', 1, $model->lastAccess($victim, '2026-01-01 00:00:00', '203.0.113.1'));
pin('the access columns landed', '203.0.113.1', $admin->query("SELECT s_access_ip FROM {$prefix}t_user WHERE pk_i_id = $victim")->fetch_assoc()['s_access_ip']);

/* ----------------------------------------------------------------------------
 * increaseNumItems / decreaseNumItems — the counters.
 * ------------------------------------------------------------------------- */
harness_section('User: item counters');

$itemsNow = static function () use ($admin, $prefix, $victim): string {
    return (string)$admin->query("SELECT i_items FROM {$prefix}t_user WHERE pk_i_id = $victim")->fetch_assoc()['i_items'];
};

pin('a non-numeric id is rejected by increaseNumItems', false, $model->increaseNumItems('abc'));
$model->increaseNumItems($victim);
pin('increaseNumItems adds one by default', '4', $itemsNow());
$model->increaseNumItems($victim, 3);
pin('increaseNumItems adds the given amount', '7', $itemsNow());
$model->decreaseNumItems($victim);
pin('decreaseNumItems removes one', '6', $itemsNow());

/* The counter never goes below zero. */
$admin->query("UPDATE {$prefix}t_user SET i_items = 0 WHERE pk_i_id = $victim");
$model->decreaseNumItems($victim);
pin('decreaseNumItems floors at zero', '0', $itemsNow());

/* ----------------------------------------------------------------------------
 * deleteUser — the cascade.
 * ------------------------------------------------------------------------- */
harness_section('User::deleteUser — cascade and survivor');

$doomed = seed_user($admin, 'doomed', 'doomed@example.test', 1, 1);
$seedDescription($doomed, 'en_US', 'to be removed');
seed_exec(
    $admin,
    "INSERT INTO {$prefix}t_user_email_tmp (fk_i_user_id, s_new_email, dt_date) VALUES (?, ?, NOW())",
    'is',
    array($doomed, 'new@example.test')
);

pin('deleteUser(null) is a no-op returning false', false, $model->deleteUser(null));
pin('deleting a user reports true', true, $model->deleteUser($doomed));
pin('the user row is gone', 0, $rawCount("SELECT COUNT(*) c FROM {$prefix}t_user WHERE pk_i_id = $doomed"));
pin('its description rows are gone', 0, $rawCount("SELECT COUNT(*) c FROM {$prefix}t_user_description WHERE fk_i_user_id = $doomed"));
pin('its pending email-change row is gone', 0, $rawCount("SELECT COUNT(*) c FROM {$prefix}t_user_email_tmp WHERE fk_i_user_id = $doomed"));
pin('the victim survives untouched', 1, $rawCount("SELECT COUNT(*) c FROM {$prefix}t_user WHERE pk_i_id = $victim"));
pin('deleting a missing id returns false', false, $model->deleteUser(999999));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/user.php */
