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
 * Characterization pins for the FieldGroup model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * the method bodies move to the parameterized query layer.
 *
 * FieldGroup spans four tables, so most of what it does is only visible through
 * side effects rather than return values: t_meta_group (the form itself),
 * t_meta_group_categories (which categories a form applies to),
 * t_meta_group_fields (which fields a form holds, and in what order) and the
 * deprecated t_meta_fields.fk_i_group_id column that deleteByPrimaryKey() still
 * clears. Every multi-table effect below is read back with raw mysqli on the
 * admin connection, never through the code under test.
 *
 * Four behaviours are easy to lose in a rewrite and are pinned deliberately:
 *
 *  - insertGroup() returns the new id as an int and bool false when the insert
 *    fails; the failure value is exercised with an explicit NULL into the NOT
 *    NULL s_name column, which is still an error with the session's relaxed
 *    sql_mode.
 *  - categories() returns an int[] — it casts each id — while every other read
 *    on this model returns the raw string columns. A stringify pass applied to
 *    it would change its element type.
 *  - deleteByPrimaryKey() and cleanCategoriesFromGroup() take an id straight
 *    into a where clause. A null id makes DBCommandClass emit a comparison with
 *    no right-hand side, the query fails and the method reports bool false,
 *    whereas an id that simply matches nothing succeeds and reports int 0. The
 *    two are pinned separately because they are not the same value.
 *  - deleteByPrimaryKey(), setFieldSingleGroup() and insertCategories() throw
 *    the return value of every statement but their last one away, so a failure
 *    on any single statement has never stopped the ones after it from running.
 *    Foreign-key and duplicate-key fixtures below exercise exactly that.
 *
 * findByCategory() issues one further query per matching form (Field::
 * findByGroup) on top of the ancestry walk. That cost is measured at two
 * fixture sizes so the growth is visible, as the baseline for the batching fix
 * scheduled separately. It is a pin of the CURRENT cost, not an endorsement.
 *
 * Usage:  php tests/models/fieldgroup.php          (standalone, own scratch database)
 *         php tests/run-models.php fieldgroup      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';
// findByCategory() reaches Field::newInstance(), whose constructor resolves the
// current locale through osc_current_user_locale() -> osc_language(). These are
// the real helpers, not stand-ins: with no session and no preferences seeded
// they resolve to the empty locale code, which is all this model needs.
require_once __DIR__ . '/../../oc-includes/osclass/helpers/hLocale.php';
require_once __DIR__ . '/../../oc-includes/osclass/helpers/hPreference.php';

$admin = scratchdb_session('osc_models_fieldgroup');

$table     = DB_TABLE_PREFIX . 't_meta_group';
$catLink   = DB_TABLE_PREFIX . 't_meta_group_categories';
$fieldLink = DB_TABLE_PREFIX . 't_meta_group_fields';
$fieldsTbl = DB_TABLE_PREFIX . 't_meta_fields';

$model = FieldGroup::newInstance();

/**
 * Insert a form row directly, bypassing insertGroup()'s slug derivation, so a
 * read can be pinned against a known position and slug.
 *
 * @return int The new group id
 */
$seedGroup = static function (
    string $name,
    string $slug,
    int $position = 0,
    ?string $meta = null
) use ($admin, $table): int {
    return seed_exec(
        $admin,
        "INSERT INTO $table (s_name, s_slug, i_position, s_meta) VALUES (?, ?, ?, ?)",
        'ssis',
        array($name, $slug, $position, $meta)
    );
};

/**
 * Insert a field definition. t_meta_fields has no seed helper in scratchdb.php,
 * and the link table's foreign key needs a real row to point at.
 *
 * @return int The new field id
 */
$seedField = static function (
    string $name,
    string $slug,
    ?int $legacyGroupId = null
) use ($admin, $fieldsTbl): int {
    return seed_exec(
        $admin,
        "INSERT INTO $fieldsTbl (s_name, s_slug, e_type, i_position, fk_i_group_id)
         VALUES (?, ?, 'TEXT', 0, ?)",
        'ssi',
        array($name, $slug, $legacyGroupId)
    );
};

/**
 * Link a field into a form at a given position, bypassing setFieldSingleGroup().
 */
$seedLink = static function (int $groupId, int $fieldId, int $position = 0) use ($admin, $fieldLink): void {
    seed_exec(
        $admin,
        "INSERT INTO $fieldLink (fk_i_group_id, fk_i_field_id, i_position) VALUES (?, ?, ?)",
        'iii',
        array($groupId, $fieldId, $position)
    );
};

/**
 * Every row of a table, read back raw so a side effect is observed rather than
 * inferred from a return value.
 *
 * @return array
 */
$rawAll = static function (string $sql) use ($admin): array {
    $res = $admin->query($sql);
    if (!$res) {
        return array();
    }
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();

    return $rows;
};

/* ----------------------------------------------------------------------------
 * Surface (C2): the public API must survive the conversion byte-identical.
 * ------------------------------------------------------------------------- */
harness_section('FieldGroup: public surface');

pin('newInstance signature is unchanged', 'public static newInstance()', harness_method_signature('FieldGroup', 'newInstance'));
pin('findByPrimaryKey signature is unchanged', 'public findByPrimaryKey($id)', harness_method_signature('FieldGroup', 'findByPrimaryKey'));
pin('findBySlug signature is unchanged', 'public findBySlug($slug)', harness_method_signature('FieldGroup', 'findBySlug'));
pin('listAll signature is unchanged', 'public listAll()', harness_method_signature('FieldGroup', 'listAll'));
pin(
    'insertGroup signature is unchanged',
    "public insertGroup(\$name, \$slug = '', \$position = 0)",
    harness_method_signature('FieldGroup', 'insertGroup')
);
pin('uniqueSlug signature is unchanged', 'public uniqueSlug($base)', harness_method_signature('FieldGroup', 'uniqueSlug'));
pin('deleteByPrimaryKey signature is unchanged', 'public deleteByPrimaryKey($id)', harness_method_signature('FieldGroup', 'deleteByPrimaryKey'));
pin('setMeta signature is unchanged', 'public setMeta($id, $key, $value)', harness_method_signature('FieldGroup', 'setMeta'));
pin(
    'setFieldSingleGroup signature is unchanged',
    'public setFieldSingleGroup($fieldId, $groupId)',
    harness_method_signature('FieldGroup', 'setFieldSingleGroup')
);
pin('categories signature is unchanged', 'public categories($id)', harness_method_signature('FieldGroup', 'categories'));
pin(
    'insertCategories signature is unchanged',
    'public insertCategories($id, $categories = NULL)',
    harness_method_signature('FieldGroup', 'insertCategories')
);
pin(
    'cleanCategoriesFromGroup signature is unchanged',
    'public cleanCategoriesFromGroup($id)',
    harness_method_signature('FieldGroup', 'cleanCategoriesFromGroup')
);
pin('findByCategory signature is unchanged', 'public findByCategory($categoryId)', harness_method_signature('FieldGroup', 'findByCategory'));

check('FieldGroup still extends DAO', is_subclass_of('FieldGroup', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
check(
    '$model->dao still answers a smoke query (C5)',
    is_array($model->dao->query('SELECT 1 AS one')->row()),
    'dao->query() did not return a usable recordset'
);
pin('newInstance is a singleton', true, FieldGroup::newInstance() === $model);
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged', 'pk_i_id', $model->getPrimaryKey());
pin(
    'field allowlist is unchanged',
    array('pk_i_id', 's_name', 's_slug', 'i_position', 's_meta'),
    $model->getFields()
);
pin(
    'the model adds exactly these methods of its own',
    array(
        '__construct', 'categories', 'cleanCategoriesFromGroup', 'deleteByPrimaryKey',
        'findByCategory', 'findByPrimaryKey', 'findBySlug', 'insertCategories',
        'insertGroup', 'listAll', 'newInstance', 'setFieldSingleGroup', 'setMeta',
        'uniqueSlug',
    ),
    array_values(array_intersect(
        array_keys(harness_public_method_map('FieldGroup')),
        array(
            '__construct', 'newInstance', 'findByPrimaryKey', 'findBySlug', 'listAll',
            'insertGroup', 'uniqueSlug', 'deleteByPrimaryKey', 'setMeta',
            'setFieldSingleGroup', 'categories', 'insertCategories',
            'cleanCategoriesFromGroup', 'findByCategory',
        )
    ))
);

/* ----------------------------------------------------------------------------
 * findByPrimaryKey() / findBySlug() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('FieldGroup::findByPrimaryKey — a match');

scratchdb_truncate_all($admin);
$gAlpha = $seedGroup('Alpha form', 'alpha-form', 3, '{"placeable":1}');
$gBeta  = $seedGroup('Beta form', 'beta-form', 1);

$row = $model->findByPrimaryKey($gAlpha);
check('a match returns a single assoc row, not a list', is_array($row) && isset($row['pk_i_id']), describe($row));
pin(
    'the row carries exactly the five schema columns',
    array('pk_i_id', 's_name', 's_slug', 'i_position', 's_meta'),
    array_keys($row)
);
pin('pk_i_id is the requested id, as a string (C4)', (string)$gAlpha, $row['pk_i_id']);
pin('s_name round-trips', 'Alpha form', $row['s_name']);
pin('s_slug round-trips', 'alpha-form', $row['s_slug']);
pin('i_position is a string, not an int (C4)', '3', $row['i_position']);
pin('s_meta round-trips as its raw JSON text', '{"placeable":1}', $row['s_meta']);
check('every value in the row is a string or null (C4)', all_values_string($row), describe($row));
pin('a NULL s_meta stays null rather than becoming an empty string', null, $model->findByPrimaryKey($gBeta)['s_meta']);

harness_section('FieldGroup::findByPrimaryKey — no match and malformed lookup');

pin('an unknown id returns an empty array', array(), $model->findByPrimaryKey(999999));
/* A null id builds a comparison with no right-hand side, so the query fails at
 * the driver. row() on the failure branch and row() on a zero-row result both
 * produce an empty array, so the two converge here. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null id returns an empty array rather than raising', array(), $model->findByPrimaryKey(null));
error_reporting($prevLevel);

harness_section('FieldGroup::findBySlug — the return ledger');

pin('a known slug resolves to its row', (string)$gBeta, $model->findBySlug('beta-form')['pk_i_id']);
check('the matched row is all strings or null (C4)', all_values_string($model->findBySlug('beta-form')), 'not all strings');
pin('an unknown slug returns an empty array', array(), $model->findBySlug('no-such-slug'));
pin('the empty-string slug matches nothing', array(), $model->findBySlug(''));
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null slug returns an empty array rather than raising', array(), $model->findBySlug(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * listAll() — the return ledger and the ordering.
 * ------------------------------------------------------------------------- */
harness_section('FieldGroup::listAll — empty table');

scratchdb_truncate_all($admin);
pin('no rows at all returns an empty array', array(), $model->listAll());

harness_section('FieldGroup::listAll — ordered by i_position ASC');

// Insertion order, pk order and position order all disagree, so a dropped or
// inverted ORDER BY fails loudly rather than passing by coincidence.
$gThird  = $seedGroup('Third', 'third', 30);
$gFirst  = $seedGroup('First', 'first', 10);
$gSecond = $seedGroup('Second', 'second', 20);

$all = $model->listAll();
check('the result is a plain list of rows', is_array($all) && array_keys($all) === array(0, 1, 2), describe($all));
pin('exactly three rows', 3, count($all));
pin(
    'rows come back by i_position ASC, not by insertion or pk order',
    array((string)$gFirst, (string)$gSecond, (string)$gThird),
    array_column($all, 'pk_i_id')
);
pin(
    'each row carries exactly the five schema columns',
    array('pk_i_id', 's_name', 's_slug', 'i_position', 's_meta'),
    array_keys($all[0])
);
check('every value in every row is a string or null (C4)', all_rows_string($all), describe($all));

harness_section('FieldGroup::listAll — equal positions');

/* No secondary sort key exists, so rows sharing a position have no defined
 * order; only membership is pinned. */
scratchdb_truncate_all($admin);
$tieA = $seedGroup('Tie A', 'tie-a', 5);
$tieB = $seedGroup('Tie B', 'tie-b', 5);
$tied = array_column($model->listAll(), 'pk_i_id');
sort($tied);
pin('both equal-position rows are returned', array((string)$tieA, (string)$tieB), $tied);

/* ----------------------------------------------------------------------------
 * uniqueSlug() — slug derivation and collision handling.
 * ------------------------------------------------------------------------- */
harness_section('FieldGroup::uniqueSlug — derivation from a name');

scratchdb_truncate_all($admin);
pin('a plain name lower-cases and dashes', 'contact-us', $model->uniqueSlug('Contact Us'));
pin('runs of illegal characters collapse to one dash', 'a-b-c-', $model->uniqueSlug('A  B--C!!'));
pin('an already-clean slug is returned untouched', 'already_clean-1', $model->uniqueSlug('already_clean-1'));
/* The "group" fallback only fires for a slug that comes out EMPTY. A name made
 * entirely of illegal characters collapses to a single dash instead, which is
 * non-empty and therefore kept — pinned as observed, not as the docblock's
 * "deriving a slug from the name" reads. */
pin('a name made only of illegal characters collapses to a bare dash', '-', $model->uniqueSlug('!!!'));
pin('only a base that yields nothing at all falls back to "group"', 'group', $model->uniqueSlug(''));

harness_section('FieldGroup::uniqueSlug — collisions append _N');

$seedGroup('Taken', 'contact-us', 0);
pin('a taken slug gains _1', 'contact-us_1', $model->uniqueSlug('Contact Us'));
$seedGroup('Taken too', 'contact-us_1', 0);
pin('the counter keeps climbing past taken suffixes', 'contact-us_2', $model->uniqueSlug('Contact Us'));
$seedGroup('Fallback taken', 'group', 0);
pin('the "group" fallback is uniquified too', 'group_1', $model->uniqueSlug(''));

/* ----------------------------------------------------------------------------
 * insertGroup() — the return ledger and what it writes.
 * ------------------------------------------------------------------------- */
harness_section('FieldGroup::insertGroup — a fresh form');

scratchdb_truncate_all($admin);
$newId = $model->insertGroup('Contact Us');
check('the new id is a positive int, not a string or bool', is_int($newId) && $newId > 0, describe($newId));
$stored = $model->findByPrimaryKey($newId);
pin('s_name is stored verbatim', 'Contact Us', $stored['s_name']);
pin('the slug is derived from the name when none is given', 'contact-us', $stored['s_slug']);
pin('i_position defaults to 0', '0', $stored['i_position']);
pin('s_meta is left NULL on insert', null, $stored['s_meta']);

harness_section('FieldGroup::insertGroup — explicit slug and position');

$withSlug = $model->insertGroup('Anything', 'My Custom Slug', 7);
$storedTwo = $model->findByPrimaryKey($withSlug);
pin('an explicit slug is still normalised, not stored verbatim', 'my-custom-slug', $storedTwo['s_slug']);
pin('the given position is stored', '7', $storedTwo['i_position']);
pin('a position given as a numeric string is cast to int before storage', '4', $model->findByPrimaryKey($model->insertGroup('Cast me', '', '4'))['i_position']);

harness_section('FieldGroup::insertGroup — slug collision');

$collide = $model->insertGroup('Contact Us');
pin('a second form with the same name gets a uniquified slug', 'contact-us_1', $model->findByPrimaryKey($collide)['s_slug']);
check('and a distinct id', $collide !== $newId, 'ids collided');

harness_section('FieldGroup::insertGroup — the insert fails');

/* s_name is NOT NULL. The session's sql_mode is relaxed, which silently coerces
 * out-of-range and wrong-type values, but an explicit NULL into a NOT NULL
 * column is still an error — the one reliable way to reach this branch. */
$rowsBefore = count($model->listAll());
$prevLevel  = error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED);
pin('a failed insert returns bool false, not 0 and not null', false, $model->insertGroup(null));
error_reporting($prevLevel);
pin('and no row was created', $rowsBefore, count($model->listAll()));

/* ----------------------------------------------------------------------------
 * setMeta() — the return ledger and the s_meta JSON it maintains.
 * ------------------------------------------------------------------------- */
harness_section('FieldGroup::setMeta — setting a key');

scratchdb_truncate_all($admin);
$gMeta = $seedGroup('Meta form', 'meta-form', 0);

pin('setting a key on a row with no s_meta reports one changed row', 1, $model->setMeta($gMeta, 'placeable', 1));
pin('the key lands in s_meta as JSON', '{"placeable":1}', $model->findByPrimaryKey($gMeta)['s_meta']);
pin('a second key is merged, not replacing the first', 1, $model->setMeta($gMeta, 'columns', 2));
pin('both keys are present', '{"placeable":1,"columns":2}', $model->findByPrimaryKey($gMeta)['s_meta']);

harness_section('FieldGroup::setMeta — writing the value it already holds');

/* affected_rows counts CHANGED rows, so a no-op write reports 0 while still
 * succeeding. A caller treating 0 as failure would be wrong, which is exactly
 * why the value is pinned. */
pin('rewriting an identical value reports int 0, not false', 0, $model->setMeta($gMeta, 'placeable', 1));

harness_section('FieldGroup::setMeta — clearing a key');

pin('an empty-string value removes the key', 1, $model->setMeta($gMeta, 'placeable', ''));
pin('the remaining key survives', '{"columns":2}', $model->findByPrimaryKey($gMeta)['s_meta']);
pin('a null value removes the key too', 1, $model->setMeta($gMeta, 'columns', null));
pin('the now-empty meta is stored as SQL NULL, not as "[]" or "{}"', null, $model->findByPrimaryKey($gMeta)['s_meta']);
pin('clearing a key that was never set reports int 0', 0, $model->setMeta($gMeta, 'never-set', ''));

harness_section('FieldGroup::setMeta — malformed stored JSON');

$gBadJson = $seedGroup('Bad json', 'bad-json', 0, 'not json at all');
pin('unparseable s_meta is discarded rather than raising', 1, $model->setMeta($gBadJson, 'placeable', 1));
pin('and is replaced by a fresh object holding just the new key', '{"placeable":1}', $model->findByPrimaryKey($gBadJson)['s_meta']);

harness_section('FieldGroup::setMeta — no such row');

pin('an unknown id reports int 0, not false', 0, $model->setMeta(999999, 'placeable', 1));
/* The id is cast with (int) before it reaches the where clause, so null becomes
 * 0 and the comparison stays well formed — no malformed-clause branch here. */
pin('a null id reports int 0 rather than raising', 0, $model->setMeta(null, 'placeable', 1));

/* ----------------------------------------------------------------------------
 * categories() / insertCategories() / cleanCategoriesFromGroup().
 * ------------------------------------------------------------------------- */
harness_section('FieldGroup::categories — the return ledger');

scratchdb_truncate_all($admin);
$catMotors = seed_category($admin, 'Motors');
$catCars   = seed_category($admin, 'Cars', $catMotors);
$catBoats  = seed_category($admin, 'Boats');
$gCats     = $seedGroup('Cat form', 'cat-form', 0);
$gNoCats   = $seedGroup('No cats', 'no-cats', 0);

pin('a form with no assignments returns an empty array', array(), $model->categories($gNoCats));

pin('assigning two categories reports bool true', true, $model->insertCategories($gCats, array($catMotors, $catBoats)));
$assigned = $model->categories($gCats);
pin('both ids come back', array($catMotors, $catBoats), $assigned);
check(
    'the ids are native ints, NOT the strings every other read returns',
    $assigned === array_map('intval', $assigned) && is_int($assigned[0]),
    describe($assigned[0])
);

/* A null id builds the malformed comparison again; on this read path the
 * failure branch and the zero-row branch converge on an empty array. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null id returns an empty array rather than raising', array(), $model->categories(null));
error_reporting($prevLevel);
pin('an unknown id returns an empty array', array(), $model->categories(999999));

harness_section('FieldGroup::insertCategories — bad input');

pin('a null category list returns bool false', false, $model->insertCategories($gNoCats));
pin('a non-array category list returns bool false', false, $model->insertCategories($gNoCats, 'Motors'));
pin('an empty array returns bool true without writing anything', true, $model->insertCategories($gNoCats, array()));
pin('and really wrote nothing', array(), $model->categories($gNoCats));

harness_section('FieldGroup::insertCategories — a failing row does not stop the rest');

/* (fk_i_group_id, fk_i_category_id) is the primary key and fk_i_category_id is
 * a foreign key, so a repeat and an unknown category are both rejected. Each
 * insert's outcome is folded into one bool: the method reports false, yet every
 * other id in the same call is still written. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin(
    'a duplicate assignment makes the whole call report false',
    false,
    $model->insertCategories($gCats, array($catMotors))
);
pin(
    'an unknown category id makes the call report false',
    false,
    $model->insertCategories($gCats, array(424242))
);
pin(
    'a good id in the same call as a bad one is still written',
    false,
    $model->insertCategories($gCats, array(424242, $catCars))
);
error_reporting($prevLevel);
pin(
    'the good id survived the failing one in the same call',
    array($catMotors, $catCars, $catBoats),
    $model->categories($gCats)
);

harness_section('FieldGroup::cleanCategoriesFromGroup — the return ledger');

pin('clearing three assignments reports int 3', 3, $model->cleanCategoriesFromGroup($gCats));
pin('the assignments are really gone', array(), $model->categories($gCats));
pin('clearing a form that has none reports int 0, not false', 0, $model->cleanCategoriesFromGroup($gCats));

/* Unlike the reads above, this write's two branches do NOT converge: a null id
 * fails the query and reports bool false, while an id that matches nothing
 * succeeds and reports int 0. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null id returns bool false, not int 0', false, $model->cleanCategoriesFromGroup(null));
error_reporting($prevLevel);
pin('an unknown id returns int 0, not false', 0, $model->cleanCategoriesFromGroup(999999));

harness_section('FieldGroup::cleanCategoriesFromGroup — other forms are untouched');

$model->insertCategories($gCats, array($catMotors));
$model->insertCategories($gNoCats, array($catBoats));
pin('clearing one form reports only its own row count', 1, $model->cleanCategoriesFromGroup($gCats));
pin('the other form keeps its assignment', array($catBoats), $model->categories($gNoCats));

/* ----------------------------------------------------------------------------
 * setFieldSingleGroup() — the link table it rewrites.
 * ------------------------------------------------------------------------- */
harness_section('FieldGroup::setFieldSingleGroup — attaching a field');

scratchdb_truncate_all($admin);
$gOne   = $seedGroup('Form one', 'form-one', 0);
$gTwo   = $seedGroup('Form two', 'form-two', 0);
$fColour = $seedField('Colour', 'colour');
$fSize   = $seedField('Size', 'size');

pin('the method returns null — it declares no return value', null, $model->setFieldSingleGroup($fColour, $gOne));
pin(
    'the field is linked to the form at position 0',
    array(array('fk_i_group_id' => (string)$gOne, 'fk_i_field_id' => (string)$fColour, 'i_position' => '0')),
    $rawAll("SELECT * FROM $fieldLink")
);

$model->setFieldSingleGroup($fSize, $gOne);
pin(
    'a second field is appended after the first, not at 0',
    '1',
    $rawAll("SELECT * FROM $fieldLink WHERE fk_i_field_id = $fSize")[0]['i_position']
);

harness_section('FieldGroup::setFieldSingleGroup — moving a field between forms');

$model->setFieldSingleGroup($fColour, $gTwo);
pin(
    'the field now belongs to exactly one form',
    array(array('fk_i_group_id' => (string)$gTwo, 'fk_i_field_id' => (string)$fColour, 'i_position' => '0')),
    $rawAll("SELECT * FROM $fieldLink WHERE fk_i_field_id = $fColour")
);
pin('the other form keeps its own field', 1, count($rawAll("SELECT * FROM $fieldLink WHERE fk_i_group_id = $gOne")));

harness_section('FieldGroup::setFieldSingleGroup — group 0 detaches the field');

$model->setFieldSingleGroup($fColour, 0);
pin('every link for that field is removed', array(), $rawAll("SELECT * FROM $fieldLink WHERE fk_i_field_id = $fColour"));
pin('and nothing is inserted in its place', 1, count($rawAll("SELECT * FROM $fieldLink")));
$model->setFieldSingleGroup($fSize, -3);
pin('a negative group id detaches too', array(), $rawAll("SELECT * FROM $fieldLink"));

harness_section('FieldGroup::setFieldSingleGroup — the insert fails');

/* The unlink runs first and is never conditional on the insert succeeding, so a
 * form id with no row behind it leaves the field detached: the delete stands,
 * the foreign key rejects the insert, and the failure is swallowed. */
$seedLink($gOne, $fColour, 0);
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a non-existent form id returns null, not an error', null, $model->setFieldSingleGroup($fColour, 999999));
error_reporting($prevLevel);
pin('the unlink still happened even though the insert failed', array(), $rawAll("SELECT * FROM $fieldLink"));

/* ----------------------------------------------------------------------------
 * deleteByPrimaryKey() — the cascade and the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('FieldGroup::deleteByPrimaryKey — the full cascade');

scratchdb_truncate_all($admin);
$catA = seed_category($admin, 'Motors');
$catB = seed_category($admin, 'Boats');
$gGone  = $seedGroup('Doomed', 'doomed', 0);
$gStays = $seedGroup('Survivor', 'survivor', 0);
$fGone  = $seedField('Doomed field', 'doomed-field', $gGone);
$fStays = $seedField('Other field', 'other-field', $gStays);
$seedLink($gGone, $fGone, 0);
$seedLink($gStays, $fStays, 0);
seed_exec($admin, "INSERT INTO $catLink (fk_i_group_id, fk_i_category_id) VALUES (?, ?)", 'ii', array($gGone, $catA));
seed_exec($admin, "INSERT INTO $catLink (fk_i_group_id, fk_i_category_id) VALUES (?, ?)", 'ii', array($gGone, $catB));
seed_exec($admin, "INSERT INTO $catLink (fk_i_group_id, fk_i_category_id) VALUES (?, ?)", 'ii', array($gStays, $catA));

pin('deleting a form reports int 1, not bool true', 1, $model->deleteByPrimaryKey($gGone));
pin('the form row is gone', array(), $model->findByPrimaryKey($gGone));
pin('its category assignments are gone', array(), $rawAll("SELECT * FROM $catLink WHERE fk_i_group_id = $gGone"));
pin('its field links are gone', array(), $rawAll("SELECT * FROM $fieldLink WHERE fk_i_group_id = $gGone"));
pin(
    'the deprecated fk_i_group_id column on its fields is cleared to NULL',
    null,
    $rawAll("SELECT fk_i_group_id FROM $fieldsTbl WHERE pk_i_id = $fGone")[0]['fk_i_group_id']
);
pin('the field definition itself survives — it just becomes loose', 1, count($rawAll("SELECT * FROM $fieldsTbl WHERE pk_i_id = $fGone")));
pin('the other form keeps its category assignment', 1, count($rawAll("SELECT * FROM $catLink WHERE fk_i_group_id = $gStays")));
pin('the other form keeps its field link', 1, count($rawAll("SELECT * FROM $fieldLink WHERE fk_i_group_id = $gStays")));
pin(
    'the other form keeps its deprecated fk_i_group_id value',
    (string)$gStays,
    $rawAll("SELECT fk_i_group_id FROM $fieldsTbl WHERE pk_i_id = $fStays")[0]['fk_i_group_id']
);

harness_section('FieldGroup::deleteByPrimaryKey — no such form');

pin('an unknown id returns int 0, not false', 0, $model->deleteByPrimaryKey(999999));

harness_section('FieldGroup::deleteByPrimaryKey — malformed id');

/* The null id makes every one of the four statements malformed. The first three
 * have their return values discarded, and the fourth reports the failure as
 * bool false — a different value from the int 0 an unmatched id produces. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null id returns bool false, not int 0', false, $model->deleteByPrimaryKey(null));
error_reporting($prevLevel);
pin('and the surviving form is untouched', 1, count($rawAll("SELECT * FROM $table WHERE pk_i_id = $gStays")));
pin('its field link survives the malformed call', 1, count($rawAll("SELECT * FROM $fieldLink WHERE fk_i_group_id = $gStays")));
pin('its category assignment survives the malformed call', 1, count($rawAll("SELECT * FROM $catLink WHERE fk_i_group_id = $gStays")));

/* ----------------------------------------------------------------------------
 * findByCategory() — inheritance, ordering, and the empty-form rule.
 * ------------------------------------------------------------------------- */
harness_section('FieldGroup::findByCategory — bad input and no match');

scratchdb_truncate_all($admin);
$root  = seed_category($admin, 'Motors');
$child = seed_category($admin, 'Cars', $root);
$alone = seed_category($admin, 'Boats');

pin('category 0 returns an empty array without touching the database', array(), $model->findByCategory(0));
pin('a null category returns an empty array', array(), $model->findByCategory(null));
pin('a negative category returns an empty array', array(), $model->findByCategory(-1));
pin('a category with no forms returns an empty array', array(), $model->findByCategory($alone));

harness_section('FieldGroup::findByCategory — inheritance and ordering');

// Position order (Late=5, Early=2) is the reverse of pk order, so a dropped
// ORDER BY fails loudly. "Late" is attached to the PARENT category, "Early" to
// the child, so a lost ancestry walk fails loudly too.
$gLate  = $seedGroup('Late', 'late', 5);
$gEarly = $seedGroup('Early', 'early', 2);
$gEmpty = $seedGroup('Empty', 'empty', 1);
$fOne   = $seedField('One', 'one');
$fTwo   = $seedField('Two', 'two');
$seedLink($gLate, $fOne, 0);
$seedLink($gEarly, $fTwo, 0);
seed_exec($admin, "INSERT INTO $catLink (fk_i_group_id, fk_i_category_id) VALUES (?, ?)", 'ii', array($gLate, $root));
seed_exec($admin, "INSERT INTO $catLink (fk_i_group_id, fk_i_category_id) VALUES (?, ?)", 'ii', array($gEarly, $child));
seed_exec($admin, "INSERT INTO $catLink (fk_i_group_id, fk_i_category_id) VALUES (?, ?)", 'ii', array($gEmpty, $child));

$forms = $model->findByCategory($child);
pin('the child sees its own form and the parent\'s, and not the field-less one', 2, count($forms));
pin(
    'forms come back by i_position ASC, not by pk or assignment order',
    array((string)$gEarly, (string)$gLate),
    array_column($forms, 'pk_i_id')
);
pin(
    'each form is its own row plus a "fields" key',
    array('pk_i_id', 's_name', 's_slug', 'i_position', 's_meta', 'fields'),
    array_keys($forms[0])
);
$formRowOnly = $forms[0];
unset($formRowOnly['fields']);
check('the form columns are all strings or null (C4)', all_values_string($formRowOnly), describe($formRowOnly));
pin('the child\'s form carries its one field', array((string)$fTwo), array_column($forms[0]['fields'], 'pk_i_id'));
pin('the inherited form carries its own field', array((string)$fOne), array_column($forms[1]['fields'], 'pk_i_id'));
pin('a form with no member fields is skipped entirely', false, in_array((string)$gEmpty, array_column($forms, 'pk_i_id'), true));

harness_section('FieldGroup::findByCategory — the parent sees only its own form');

$parentForms = $model->findByCategory($root);
pin('inheritance runs downwards only', array((string)$gLate), array_column($parentForms, 'pk_i_id'));

harness_section('FieldGroup::findByCategory — one form reachable by two ancestors');

/* The GROUP BY collapses the duplicate rows the cross join produces when a form
 * is assigned to both a category and one of its ancestors. */
seed_exec($admin, "INSERT INTO $catLink (fk_i_group_id, fk_i_category_id) VALUES (?, ?)", 'ii', array($gLate, $child));
pin('the doubly-assigned form appears exactly once', array((string)$gEarly, (string)$gLate), array_column($model->findByCategory($child), 'pk_i_id'));

/* ----------------------------------------------------------------------------
 * Query cost. Counts are comparable across the conversion: a prepared statement
 * contributes exactly one Question, the same as a legacy query().
 * ------------------------------------------------------------------------- */
harness_section('FieldGroup: query cost');

scratchdb_truncate_all($admin);
$costCat   = seed_category($admin, 'Motors');
$costGroup = $seedGroup('Cost', 'cost', 0);
$costField = $seedField('Cost field', 'cost-field');

pin('findByPrimaryKey costs one query', 1, harness_query_count(static function () use ($model, $costGroup) {
    $model->findByPrimaryKey($costGroup);
}));
pin('findBySlug costs one query', 1, harness_query_count(static function () use ($model) {
    $model->findBySlug('cost');
}));
pin('listAll costs one query', 1, harness_query_count(static function () use ($model) {
    $model->listAll();
}));
pin('categories costs one query', 1, harness_query_count(static function () use ($model, $costGroup) {
    $model->categories($costGroup);
}));
pin('insertGroup costs two queries: one slug probe plus the insert', 2, harness_query_count(static function () use ($model) {
    $model->insertGroup('Probe one');
}));
pin('uniqueSlug costs one query per candidate tried', 1, harness_query_count(static function () use ($model) {
    $model->uniqueSlug('never-taken-slug');
}));
pin('setMeta costs two queries: the read plus the update', 2, harness_query_count(static function () use ($model, $costGroup) {
    $model->setMeta($costGroup, 'placeable', 1);
}));
pin('insertCategories costs one query per category', 2, harness_query_count(static function () use ($model, $costGroup, $costCat) {
    $model->insertCategories($costGroup, array($costCat, $costCat));
}));
pin('cleanCategoriesFromGroup costs one query', 1, harness_query_count(static function () use ($model, $costGroup) {
    $model->cleanCategoriesFromGroup($costGroup);
}));
pin('deleteByPrimaryKey costs four queries', 4, harness_query_count(static function () use ($model) {
    $model->deleteByPrimaryKey(999999);
}));
pin('setFieldSingleGroup costs one query when detaching', 1, harness_query_count(static function () use ($model, $costField) {
    $model->setFieldSingleGroup($costField, 0);
}));
pin('setFieldSingleGroup costs three queries when attaching', 3, harness_query_count(static function () use ($model, $costField, $costGroup) {
    $model->setFieldSingleGroup($costField, $costGroup);
}));

harness_section('FieldGroup::findByCategory — per-form query cost baseline');

/* Baseline for the batching fix scheduled separately: one query per matching
 * form on top of the ancestry walk and the form lookup. Measured at two fixture
 * sizes so the growth is visible rather than asserted. */
scratchdb_truncate_all($admin);
$nCat = seed_category($admin, 'Flat');

$attachForms = static function (int $n) use ($seedGroup, $seedField, $seedLink, $admin, $catLink, $nCat) {
    for ($i = 0; $i < $n; $i++) {
        $g = $seedGroup("Form $i", "form-$i", $i);
        $f = $seedField("Field $i", "field-$i");
        $seedLink($g, $f, 0);
        seed_exec($admin, "INSERT INTO $catLink (fk_i_group_id, fk_i_category_id) VALUES (?, ?)", 'ii', array($g, $nCat));
    }
};

$attachForms(2);
pin('a top-level category with 2 forms costs 1 walk + 1 lookup + 2 per-form queries', 4, harness_query_count(static function () use ($model, $nCat) {
    $model->findByCategory($nCat);
}));
$attachForms(2);
pin('with 4 forms the cost grows to 6 — one more query per added form', 6, harness_query_count(static function () use ($model, $nCat) {
    $model->findByCategory($nCat);
}));
pin('and the results grow with it', 4, count($model->findByCategory($nCat)));

harness_section('FieldGroup::findByCategory — ancestry walk cost');

scratchdb_truncate_all($admin);
$lvl1 = seed_category($admin, 'L1');
$lvl2 = seed_category($admin, 'L2', $lvl1);
$lvl3 = seed_category($admin, 'L3', $lvl2);
pin('a three-level category with no forms costs three walk queries plus one lookup', 4, harness_query_count(static function () use ($model, $lvl3) {
    $model->findByCategory($lvl3);
}));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/fieldgroup.php */
