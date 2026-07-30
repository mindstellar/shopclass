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
 * Characterization pins for the Preference model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * toArray()/findValueByName()/findBySection()/replace() move to the
 * parameterized query layer.
 *
 * Preference is a cache wrapped around a table, and the cache and the table
 * disagree with each other in ways that are load-bearing, not accidental:
 *
 *  - get()/getSection() read ONLY $this->pref, the in-process map. They cost
 *    zero queries and are never refreshed by a write.
 *  - findValueByName()/findBySection() read ONLY the table, bypassing
 *    $this->pref entirely. They see a write the instant it lands.
 *  - replace() (and the inherited DAO::insert()/delete()) write ONLY the
 *    table. They never touch $this->pref, so get() keeps returning whatever
 *    it returned before the write — silently stale — until something calls
 *    toArray() again.
 *  - set() writes ONLY $this->pref, in memory, with no query at all. It is
 *    visible to get() immediately and invisible to findValueByName() forever
 *    (nothing lands in the table).
 *  - toArray() is the one method that ever changes what get() sees. It MERGES
 *    the table into $this->pref key by key; it does not clear the map first.
 *    A row deleted from the table therefore survives in the cache across an
 *    explicit toArray() reload — merge, never replace. And when toArray()'s
 *    own read fails or returns zero rows, it returns false and leaves
 *    $this->pref completely untouched, stale values included.
 *  - newInstance() is a process-lifetime singleton: toArray() runs exactly
 *    once, in the constructor, the first time anything asks for the
 *    instance. Everything above was verified by direct observation against
 *    the running legacy code before being pinned, not assumed from the method
 *    names or the surrounding comments.
 *
 * findBySection() has the null-where divergence described for other reads in
 * this effort, but in an unusual direction: DBCommandClass::_where() building
 * a comparison with no right-hand side for a null argument is what legacy
 * ALREADY treats as a query failure ($result == false -> array()), while a
 * valid query that simply matches zero rows takes the OTHER branch
 * ($result->numRows() == 0 -> false). So the malformed-input case and the
 * empty-result case return DIFFERENT values here, the reverse of the more
 * common convergent case — both are pinned below.
 *
 * findValueByName() does not have that problem: both of its failure paths
 * (query error, zero rows) already return the same value (false).
 *
 * Usage:  php tests/models/preference.php          (standalone, own scratch database)
 *         php tests/run-models.php preference      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_preference');
$table = DB_TABLE_PREFIX . 't_preference';

/**
 * t_preference has no AUTO_INCREMENT column and no seed helper in
 * scratchdb.php, so this test carries its own fixture writer. Always goes
 * straight to the table with raw mysqli, never through the model.
 */
$seedPref = static function (
    string $section,
    string $name,
    string $value,
    string $type = 'STRING'
) use ($admin, $table): void {
    seed_exec(
        $admin,
        "INSERT INTO $table (s_section, s_name, s_value, e_type) VALUES (?, ?, ?, ?)",
        'ssss',
        array($section, $name, $value, $type)
    );
};

/** One row read back with raw mysqli, never through the model. */
$rowFor = static function (string $section, string $name) use ($admin, $table): ?array {
    $stmt = $admin->prepare("SELECT * FROM $table WHERE s_section = ? AND s_name = ?");
    $stmt->bind_param('ss', $section, $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
};

$rowCount = static function () use ($admin, $table): int {
    return (int) $admin->query("SELECT COUNT(*) c FROM $table")->fetch_assoc()['c'];
};

$model = Preference::newInstance();

/* ----------------------------------------------------------------------------
 * Surface (C2): the public API must survive the conversion byte-identical.
 * ------------------------------------------------------------------------- */
harness_section('Preference: public surface');

pin('__construct signature is unchanged', 'public __construct()', harness_method_signature('Preference', '__construct'));
pin('toArray signature is unchanged', 'public toArray()', harness_method_signature('Preference', 'toArray'));
pin(
    'newInstance signature is unchanged',
    'public static newInstance()',
    harness_method_signature('Preference', 'newInstance')
);
pin(
    'findValueByName signature is unchanged',
    'public findValueByName($name)',
    harness_method_signature('Preference', 'findValueByName')
);
pin(
    'findBySection signature is unchanged',
    'public findBySection($name)',
    harness_method_signature('Preference', 'findBySection')
);
pin('get signature is unchanged', "public get(\$key, \$section = 'osclass')", harness_method_signature('Preference', 'get'));
pin(
    'getSection signature is unchanged',
    "public getSection(\$section = 'osclass')",
    harness_method_signature('Preference', 'getSection')
);
pin(
    'set signature is unchanged',
    "public set(\$key, \$value, \$section = 'osclass')",
    harness_method_signature('Preference', 'set')
);
pin(
    'replace signature is unchanged',
    "public replace(\$key, \$value, \$section = 'osclass', \$type = 'STRING')",
    harness_method_signature('Preference', 'replace')
);
check('Preference still extends DAO', is_subclass_of('Preference', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('no primary key is set (unchanged)', null, $model->getPrimaryKey());
pin(
    'field allowlist is unchanged',
    array('s_section', 's_name', 's_value', 'e_type'),
    $model->getFields()
);
pin(
    'the model adds exactly nine methods of its own',
    array('__construct', 'findBySection', 'findValueByName', 'get', 'getSection', 'newInstance', 'replace', 'set', 'toArray'),
    array_values(array_intersect(
        array_keys(harness_public_method_map('Preference')),
        array('__construct', 'toArray', 'newInstance', 'findValueByName', 'findBySection', 'get', 'getSection', 'set', 'replace')
    ))
);

/* ----------------------------------------------------------------------------
 * findValueByName() — the return ledger. Bypasses the cache: always a live
 * table read.
 * ------------------------------------------------------------------------- */
harness_section('Preference::findValueByName — empty table');

pin('no rows at all returns bool false', false, $model->findValueByName('siteName'));

harness_section('Preference::findValueByName — single match');

$seedPref('osclass', 'siteName', 'MySite');
$seedPref('osclass', 'siteEmail', 'a@b.com');

pin('the value round-trips', 'MySite', $model->findValueByName('siteName'));
check('the returned value is a string (C4)', is_string($model->findValueByName('siteName')));

harness_section('Preference::findValueByName — no match');

pin('an unknown name returns bool false', false, $model->findValueByName('doesNotExist'));

harness_section('Preference::findValueByName — duplicate name across sections');

/* s_name alone is not unique — only (s_section, s_name) together is — so the
 * same name can legitimately appear in more than one section. */
$seedPref('plugin_x', 'siteName', 'DIFFERENT-SECTION-SAME-NAME');

pin(
    'a name duplicated across sections returns a single value: the first inserted row',
    'MySite',
    $model->findValueByName('siteName')
);

harness_section('Preference::findValueByName — malformed lookup');

/* Passing null builds a comparison with no right-hand side, so the query
 * fails and the failure surfaces as "not found" — the same quirk pinned for
 * Cron::getCronByType(null) and CityArea::findByName(null). Both of
 * findValueByName's failure paths already return the same value, so there is
 * no divergence to reconcile here. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null name returns bool false rather than raising', false, $model->findValueByName(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * findBySection() — the return ledger. Also bypasses the cache. The one
 * method here where the failure branch and the zero-row branch diverge.
 * ------------------------------------------------------------------------- */
harness_section('Preference::findBySection — no rows for a valid section');

pin('a section with no rows returns bool false, NOT an empty array', false, $model->findBySection('nonexistent_section'));

harness_section('Preference::findBySection — match');

$rows = $model->findBySection('osclass');
check('a match returns an array', is_array($rows), describe($rows));
pin('two rows in the section come back', 2, count($rows));
$namesInSection = array_column($rows, 's_name');
sort($namesInSection);
pin('both preference names are present', array('siteEmail', 'siteName'), $namesInSection);
check('every value in every row is a string or null (C4)', all_rows_string($rows), describe($rows));

$rowsPluginX = $model->findBySection('plugin_x');
pin('a different section returns only its own row', 1, count($rowsPluginX));
pin('the row carries exactly the four schema columns', array('s_section', 's_name', 's_value', 'e_type'), array_keys($rowsPluginX[0]));

harness_section('Preference::findBySection — malformed lookup (the divergent branch)');

/* This is the case the null-where correction warns about: legacy's failure
 * branch (SQL error -> array()) and its zero-row branch (valid query, no
 * match -> false) return DIFFERENT values, and a null argument reaches the
 * error branch, not the zero-row one. A bound null parameter in the new
 * stack is valid SQL that simply matches nothing, which would land on the
 * WRONG branch (false) if not special-cased. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null section returns an empty array, NOT bool false, unlike the valid-but-empty case above', array(), $model->findBySection(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * get()/getSection() — cache-only reads. Zero queries, and blind to any write
 * that has not gone through toArray().
 * ------------------------------------------------------------------------- */
harness_section('Preference: get()/getSection() read only the in-process cache');

/* $model's cache was populated once, in its constructor, against whatever the
 * table held before this file seeded anything — i.e. an empty table. Every
 * row seeded above is invisible to get()/getSection() until toArray() runs
 * again, no matter how long ago it landed in the table. */
pin('get() on a key seeded after construction is stale: empty string, not the table value', '', $model->get('siteName', 'osclass'));
pin('get() on an unknown key/section still defaults to empty string', '', $model->get('unknownKey', 'unknownSection'));
pin('getSection() on a section seeded after construction is stale: empty array', array(), $model->getSection('osclass'));

pin('get() costs zero queries (C9)', 0, harness_query_count(static function () use ($model) {
    $model->get('siteName', 'osclass');
}));
pin('getSection() costs zero queries (C9)', 0, harness_query_count(static function () use ($model) {
    $model->getSection('osclass');
}));

/* ----------------------------------------------------------------------------
 * toArray() — the only method that ever changes what get() sees. Reloads by
 * MERGING into $this->pref; never clears it first.
 * ------------------------------------------------------------------------- */
harness_section('Preference::toArray() — explicit reload brings the cache in sync');

pin('toArray() returns bool true when rows exist', true, $model->toArray());
pin('get() now sees the table value (C9 invalidation point)', 'MySite', $model->get('siteName', 'osclass'));
pin('getSection() now sees both rows', array('siteEmail' => 'a@b.com', 'siteName' => 'MySite'), $model->getSection('osclass'));
pin('a different section is reachable too', 'DIFFERENT-SECTION-SAME-NAME', $model->get('siteName', 'plugin_x'));

harness_section('Preference::toArray() — a row deleted from the table survives in the cache (merge, not replace)');

$admin->query("DELETE FROM $table WHERE s_section = 'osclass' AND s_name = 'siteEmail'");
pin('before any reload, the cache still has the pre-delete value', 'a@b.com', $model->get('siteEmail', 'osclass'));
pin('toArray() reloads successfully (other rows still exist)', true, $model->toArray());
pin(
    'AFTER the reload, the deleted key is still cached — toArray() only merges, it never removes stale keys',
    'a@b.com',
    $model->get('siteEmail', 'osclass')
);

harness_section('Preference::toArray() — a failed/empty reload leaves the existing cache completely untouched');

$admin->query("TRUNCATE TABLE $table");
pin('toArray() over an empty table returns bool false', false, $model->toArray());
pin('the cache built by the earlier successful reload is unaffected by the failed one', 'MySite', $model->get('siteName', 'osclass'));

harness_section('Preference::toArray() — query cost');

$seedPref('osclass', 'siteName', 'MySite');
pin('one toArray() reload costs one query', 1, harness_query_count(static function () use ($model) {
    $model->toArray();
}));

/* ----------------------------------------------------------------------------
 * set() — cache-only write. No query at all; never reaches the table.
 * ------------------------------------------------------------------------- */
harness_section('Preference::set() — writes only the in-process cache, never the table');

pin('set() has no return value', null, $model->set('newKey', 'newVal', 'osclass'));
pin('get() sees the new value immediately', 'newVal', $model->get('newKey', 'osclass'));
pin('set() never reached the table: findValueByName() (a live read) does not see it', false, $model->findValueByName('newKey'));
pin('set() costs zero queries', 0, harness_query_count(static function () use ($model) {
    $model->set('anotherKey', 'v', 'osclass');
}));

/* ----------------------------------------------------------------------------
 * replace() — the write ledger. Writes only the table; never touches the
 * cache (the write side of the same invalidation story).
 * ------------------------------------------------------------------------- */
harness_section('Preference::replace() — success');

pin('a fresh key returns bool true', true, $model->replace('newPref', 'v1', 'osclass'));
$row = $rowFor('osclass', 'newPref');
check('the row was actually written', $row !== null, describe($row));
pin('s_value round-trips', 'v1', $row['s_value'] ?? null);
pin('e_type defaults to STRING', 'STRING', $row['e_type'] ?? null);

pin('replacing the same key again still returns bool true', true, $model->replace('newPref', 'v2', 'osclass'));
pin('the value was overwritten in the table, not duplicated', 'v2', $rowFor('osclass', 'newPref')['s_value'] ?? null);
pin('the unique key (s_section, s_name) means still exactly one row', 1, $rowFor('osclass', 'newPref') !== null ? 1 : 0);

harness_section('Preference::replace() — e_type sanitization');

$model->replace('typedInt', '5', 'osclass', 'INTEGER');
pin('a valid enum type is stored as given', 'INTEGER', $rowFor('osclass', 'typedInt')['e_type'] ?? null);

$model->replace('typedBogus', 'x', 'osclass', 'NOT_A_TYPE');
pin('an invalid type falls back to STRING', 'STRING', $rowFor('osclass', 'typedBogus')['e_type'] ?? null);

harness_section('Preference::replace() — does NOT invalidate the cache');

pin('get() right after a successful replace() is still stale', '', $model->get('newPref', 'osclass'));
pin('the live table read sees the replace() immediately, unlike get()', 'v2', $model->findValueByName('newPref'));

harness_section('Preference::replace() — failure (NOT NULL violation)');

/* s_name is NOT NULL; strict SQL mode is off, so this is one of the few
 * writes that can still be made to fail outright rather than being silently
 * coerced. */
$beforeCount = $rowCount();
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null key returns bool false', false, $model->replace(null, 'x', 'osclass'));
error_reporting($prevLevel);
pin('a failed replace() leaves no row behind', $beforeCount, $rowCount());

harness_section('Preference::replace() — query cost');

pin('one replace() call costs one query', 1, harness_query_count(static function () use ($model) {
    $model->replace('costProbe', 'v', 'osclass');
}));

/* ----------------------------------------------------------------------------
 * The singleton: newInstance() is process-lifetime, so the cache built above
 * survives across every later call to newInstance() in this process.
 * ------------------------------------------------------------------------- */
harness_section('Preference: singleton persistence (C9)');

$modelAgain = Preference::newInstance();
check('newInstance() returns the SAME object on a later call', $model === $modelAgain);
pin('the cache built earlier in this process is still there', 'newVal', $modelAgain->get('newKey', 'osclass'));

/* ----------------------------------------------------------------------------
 * Constructor-time loading, exercised on a FRESH instance.
 *
 * Preference::newInstance() is a process-lifetime singleton, so it only ever
 * runs its constructor once per process — not enough to characterize what the
 * constructor does at construction time against different table states.
 * __construct() is public and does nothing singleton-specific itself (only
 * newInstance() enforces the singleton), so `new Preference()` bypasses the
 * singleton entirely and gives a real, independent constructor run for this
 * pin, without touching tests/lib/* to add a reset hook.
 * ------------------------------------------------------------------------- */
harness_section('Preference: constructor-time loading (fresh instance, bypassing the singleton)');

$admin->query("TRUNCATE TABLE $table");
$freshEmpty = new Preference();
pin('a fresh instance constructed over an empty table has an empty cache', '', $freshEmpty->get('anything', 'osclass'));

$seedPref('osclass', 'fromConstructor', 'seenAtConstruction');
$freshLoaded = new Preference();
pin(
    'a fresh instance constructed after seeding loads the row at construction time',
    'seenAtConstruction',
    $freshLoaded->get('fromConstructor', 'osclass')
);

$seedPref('osclass', 'afterConstruction', 'notSeenYet');
pin(
    'a row inserted AFTER construction is invisible to that already-constructed instance',
    '',
    $freshLoaded->get('afterConstruction', 'osclass')
);
pin(
    'the live table read sees it anyway, because it never goes through the cache',
    'notSeenYet',
    $freshLoaded->findValueByName('afterConstruction')
);

check(
    'nothing above touched the process-wide singleton',
    Preference::newInstance() === $modelAgain
);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/preference.php */
