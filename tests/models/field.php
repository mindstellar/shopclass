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
 * Characterization pins for the Field model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * the method bodies move to the parameterized query layer — with a single
 * documented exception: the section labelled "AMENDMENT T" pins a deliberate
 * behaviour change (dropping the escape() numeric-coercion on VARCHAR
 * comparisons) and is therefore RED against the pre-conversion code by exactly
 * those pins. That is the sanctioned carve-out to the green-pre-conversion rule.
 *
 * Field resolves custom fields across the category inheritance tree with UNION
 * queries and a per-category ancestry walk (categoryPath). Most of what it does
 * is only visible through what its reads return for a seeded tree, so every
 * fixture is written with raw mysqli on the admin connection, never through the
 * code under test. The tables involved: t_meta_fields (the field), t_meta_group
 * / t_meta_group_fields / t_meta_group_categories (forms and their membership),
 * t_meta_categories (loose field-to-category links), t_item_meta (stored values)
 * and t_category (the tree the walk climbs).
 *
 * Behaviours pinned deliberately because they are easy to lose in a rewrite:
 *
 *  - Every read runs its DB row through extendField(): a NULL/empty s_meta is a
 *    no-op, a JSON s_meta is merged onto the row (bringing native JSON types),
 *    and a 'locale' entry for the current locale is always present afterwards.
 *  - categoryPath() re-walks the tree on EVERY call with one query per ancestor
 *    level and no memoization (inventory F8). The cost is pinned at two depths
 *    and across two consecutive calls so the re-walk is visible. It is a pin of
 *    the current cost, not an endorsement.
 *  - findByCategory / findByCategoryItem / findIDSearchableByCategories resolve
 *    a field assigned to an ANCESTOR for a descendant category, de-duplicate by
 *    field id, and order by group then field position.
 *  - categories() returns the raw string ids every legacy read produced (NOT the
 *    int[] its FieldGroup sibling returns); findIDSearchableByCategories likewise
 *    returns string ids.
 *  - insertField() writes the field and then, because an external caller reads
 *    $model->dao->insertedId() straight after it (CAdminAjax), the new insert id
 *    still has to be readable off the shared handle (trap 2.2 propagation).
 *  - insertCategories(id, array()) returns bool false — an empty array is loosely
 *    equal to null, so it never enters the write loop (differs from FieldGroup).
 *  - cleanCategoriesFromField(null) / a null id in a write where-clause reports
 *    bool false (malformed clause), while an id matching nothing reports int 0.
 *
 * Usage:  php tests/models/field.php          (standalone, own scratch database)
 *         php tests/run-models.php field       (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';
// Field's constructor resolves the current locale through
// osc_current_user_locale() -> osc_language(); with no session and no
// preferences seeded these resolve to the empty locale code, which is all this
// model needs. These are the real helpers, not stand-ins.
require_once __DIR__ . '/../../oc-includes/osclass/helpers/hLocale.php';
require_once __DIR__ . '/../../oc-includes/osclass/helpers/hPreference.php';

$admin = scratchdb_session('osc_models_field');

// findIDSearchableByCategories() constructs Category::newInstance()
// unconditionally (even for numeric ids), and Category's constructor builds its
// tree cache through hDefines/hCache helpers the harness cannot load
// (hDefines.php redeclares osc_uploads_path() unguarded, per amendment R). Guard
// a local stand-in for each: osc_base_url() only feeds a cache key, and forcing
// every cache lookup to miss makes Category re-query t_category, which is exactly
// the behaviour a characterization fixture wants.
if (!function_exists('osc_base_url')) {
    function osc_base_url($add_index = false)
    {
        return 'http://localhost/';
    }
}
if (!function_exists('osc_cache_get')) {
    function osc_cache_get($key, &$found = null)
    {
        $found = false;

        return false;
    }
}
if (!function_exists('osc_cache_set')) {
    function osc_cache_set($key, $value, $expiration = 0)
    {
        return false;
    }
}

// Category::__construct() reads OC_ADMIN unguarded (Field's own constructor
// guards it with defined()). findIDSearchableByCategories() resolves non-numeric
// ids through Category::newInstance(), so the constant has to exist. false is the
// non-admin (public) context, matching this harness's locale resolution.
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', false);
}
if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 0);
}

$fieldsTbl = DB_TABLE_PREFIX . 't_meta_fields';
$metaCat   = DB_TABLE_PREFIX . 't_meta_categories';
$group     = DB_TABLE_PREFIX . 't_meta_group';
$groupFld  = DB_TABLE_PREFIX . 't_meta_group_fields';
$groupCat  = DB_TABLE_PREFIX . 't_meta_group_categories';
$itemMeta  = DB_TABLE_PREFIX . 't_item_meta';

$model = Field::newInstance();
$locale = $model->currentLocaleCode; // '' in this harness

/**
 * Insert a field definition directly, returning its id.
 */
$seedField = static function (
    string $name,
    string $slug,
    string $type = 'TEXT',
    int $required = 0,
    int $searchable = 0,
    ?string $options = null,
    ?string $meta = null,
    int $position = 0,
    ?int $legacyGroupId = null
) use ($admin, $fieldsTbl): int {
    return seed_exec(
        $admin,
        "INSERT INTO $fieldsTbl
         (s_name, s_slug, e_type, s_options, b_required, b_searchable, s_meta, i_position, fk_i_group_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        'ssssiisii',
        array($name, $slug, $type, $options, $required, $searchable, $meta, $position, $legacyGroupId)
    );
};

$seedGroup = static function (string $name, string $slug, int $position = 0) use ($admin, $group): int {
    return seed_exec(
        $admin,
        "INSERT INTO $group (s_name, s_slug, i_position) VALUES (?, ?, ?)",
        'ssi',
        array($name, $slug, $position)
    );
};

$linkFieldToCat = static function (int $catId, int $fieldId) use ($admin, $metaCat): void {
    seed_exec($admin, "INSERT INTO $metaCat (fk_i_category_id, fk_i_field_id) VALUES (?, ?)", 'ii', array($catId, $fieldId));
};

$linkFieldToGroup = static function (int $groupId, int $fieldId, int $position = 0) use ($admin, $groupFld): void {
    seed_exec($admin, "INSERT INTO $groupFld (fk_i_group_id, fk_i_field_id, i_position) VALUES (?, ?, ?)", 'iii', array($groupId, $fieldId, $position));
};

$linkGroupToCat = static function (int $groupId, int $catId) use ($admin, $groupCat): void {
    seed_exec($admin, "INSERT INTO $groupCat (fk_i_group_id, fk_i_category_id) VALUES (?, ?)", 'ii', array($groupId, $catId));
};

$seedItemMeta = static function (int $itemId, int $fieldId, ?string $value, string $multi = '') use ($admin, $itemMeta): void {
    seed_exec($admin, "INSERT INTO $itemMeta (fk_i_item_id, fk_i_field_id, s_value, s_multi) VALUES (?, ?, ?, ?)", 'iiss', array($itemId, $fieldId, $value, $multi));
};

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
harness_section('Field: public surface');

pin('newInstance signature is unchanged', 'public static newInstance()', harness_method_signature('Field', 'newInstance'));
pin('findByPrimaryKey signature is unchanged', 'public findByPrimaryKey($id)', harness_method_signature('Field', 'findByPrimaryKey'));
pin('deleteByPrimaryKey signature is unchanged', 'public deleteByPrimaryKey($id)', harness_method_signature('Field', 'deleteByPrimaryKey'));
pin('listAll signature is unchanged', 'public listAll()', harness_method_signature('Field', 'listAll'));
pin('categoryPath signature is unchanged', 'public categoryPath($catId)', harness_method_signature('Field', 'categoryPath'));
pin('findByGroup signature is unchanged', 'public findByGroup($groupId)', harness_method_signature('Field', 'findByGroup'));
pin('findByCategory signature is unchanged', 'public findByCategory($id)', harness_method_signature('Field', 'findByCategory'));
pin('findIDSearchableByCategories signature is unchanged', 'public findIDSearchableByCategories($ids)', harness_method_signature('Field', 'findIDSearchableByCategories'));
pin('findByCategoryItem signature is unchanged', 'public findByCategoryItem($catId, $itemId)', harness_method_signature('Field', 'findByCategoryItem'));
pin('findByItem signature is unchanged', 'public findByItem($itemId)', harness_method_signature('Field', 'findByItem'));
pin('findByName signature is unchanged', 'public findByName($name)', harness_method_signature('Field', 'findByName'));
pin('getDateIntervalByPrimaryKey signature is unchanged', 'public getDateIntervalByPrimaryKey($item_id, $field_id)', harness_method_signature('Field', 'getDateIntervalByPrimaryKey'));
pin('categories signature is unchanged', 'public categories($id)', harness_method_signature('Field', 'categories'));
pin('insertField signature is unchanged', 'public insertField($name, $type, $slug, $required, $options, $categories = NULL)', harness_method_signature('Field', 'insertField'));
pin('findBySlug signature is unchanged', 'public findBySlug($slug)', harness_method_signature('Field', 'findBySlug'));
pin('insertCategories signature is unchanged', 'public insertCategories($id, $categories = NULL)', harness_method_signature('Field', 'insertCategories'));
pin('cleanCategoriesFromField signature is unchanged', 'public cleanCategoriesFromField($id)', harness_method_signature('Field', 'cleanCategoriesFromField'));
pin('replace signature is unchanged', 'public replace($itemId, $field, $value)', harness_method_signature('Field', 'replace'));
pin('updateJsonMeta signature is unchanged', 'public updateJsonMeta($metaId, $fieldName, $fieldValue)', harness_method_signature('Field', 'updateJsonMeta'));
pin('getJsonMetaValue signature is unchanged', 'public getJsonMetaValue($fieldName, $field = NULL, $metaId = NULL)', harness_method_signature('Field', 'getJsonMetaValue'));

check('Field still extends DAO', is_subclass_of('Field', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
check(
    '$model->dao still answers a smoke query (C5)',
    is_array($model->dao->query('SELECT 1 AS one')->row()),
    'dao->query() did not return a usable recordset'
);
pin('newInstance is a singleton', true, Field::newInstance() === $model);
pin('table name is unchanged', $fieldsTbl, $model->getTableName());
pin('primary key is unchanged', 'pk_i_id', $model->getPrimaryKey());
pin(
    'field allowlist is unchanged',
    array('pk_i_id', 's_name', 'e_type', 'b_required', 'b_searchable', 's_slug', 's_options', 's_meta', 'i_position', 'fk_i_group_id'),
    $model->getFields()
);
pin(
    'the model declares exactly these methods of its own',
    array(
        '__construct', 'categories', 'categoryPath', 'cleanCategoriesFromField',
        'deleteByPrimaryKey', 'findByCategory', 'findByCategoryItem', 'findByGroup',
        'findByItem', 'findByName', 'findByPrimaryKey', 'findBySlug',
        'findIDSearchableByCategories', 'getDateIntervalByPrimaryKey',
        'getJsonMetaValue', 'insertCategories', 'insertField', 'listAll',
        'newInstance', 'replace', 'updateJsonMeta',
    ),
    array_values(array_intersect(
        array_keys(harness_public_method_map('Field')),
        array(
            '__construct', 'newInstance', 'findByPrimaryKey', 'deleteByPrimaryKey',
            'listAll', 'categoryPath', 'findByGroup', 'findByCategory',
            'findIDSearchableByCategories', 'findByCategoryItem', 'findByItem',
            'findByName', 'getDateIntervalByPrimaryKey', 'categories', 'insertField',
            'findBySlug', 'insertCategories', 'cleanCategoriesFromField', 'replace',
            'updateJsonMeta', 'getJsonMetaValue',
        )
    ))
);

/* ----------------------------------------------------------------------------
 * findByPrimaryKey() / extendField() shape.
 * ------------------------------------------------------------------------- */
harness_section('Field::findByPrimaryKey — a match and the extendField shape');

scratchdb_truncate_all($admin);
$fPlain = $seedField('Colour', 'colour', 'TEXT', 1, 0, 'opt-a', null, 3);

$row = $model->findByPrimaryKey($fPlain);
check('a match returns a single assoc row', is_array($row) && isset($row['pk_i_id']), describe($row));
pin(
    'the row carries the ten schema columns plus the appended locale key',
    array('pk_i_id', 's_name', 's_slug', 'e_type', 's_options', 'b_required', 'b_searchable', 's_meta', 'i_position', 'fk_i_group_id', 'locale'),
    array_keys($row)
);
pin('pk_i_id is the requested id, as a string (C4)', (string)$fPlain, $row['pk_i_id']);
pin('s_name round-trips', 'Colour', $row['s_name']);
pin('e_type round-trips', 'TEXT', $row['e_type']);
pin('b_required is a string, not an int (C4)', '1', $row['b_required']);
pin('i_position is a string, not an int (C4)', '3', $row['i_position']);
pin('a NULL s_meta stays null', null, $row['s_meta']);
pin('a NULL fk_i_group_id stays null', null, $row['fk_i_group_id']);
pin(
    'extendField adds a locale entry for the current locale from s_name',
    array($locale => array('s_name' => 'Colour')),
    $row['locale']
);
$colsOnly = $row;
unset($colsOnly['locale']);
check('every scalar column is a string or null (C4)', all_values_string($colsOnly), describe($colsOnly));

harness_section('Field::findByPrimaryKey — s_meta JSON is merged onto the row');

$fMeta = $seedField('Tabbed', 'tabbed', 'URL', 0, 0, null, '{"b_new_tab":1,"custom":"x"}');
$rowMeta = $model->findByPrimaryKey($fMeta);
pin('a JSON key from s_meta lands on the row as its native JSON type', 1, $rowMeta['b_new_tab']);
pin('a JSON string key is merged as a string', 'x', $rowMeta['custom']);
pin('s_meta itself is still the raw JSON text', '{"b_new_tab":1,"custom":"x"}', $rowMeta['s_meta']);

harness_section('Field::findByPrimaryKey — no match and malformed lookup');

pin('an unknown id returns an empty array', array(), $model->findByPrimaryKey(999999));
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null id returns an empty array rather than raising', array(), $model->findByPrimaryKey(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * findByName() / findBySlug() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('Field::findByName / findBySlug — the return ledger');

scratchdb_truncate_all($admin);
$fNamed = $seedField('Engine size', 'engine-size', 'NUMBER');

pin('findByName resolves a field by its name', (string)$fNamed, $model->findByName('Engine size')['pk_i_id']);
pin('findByName on an unknown name returns an empty array', array(), $model->findByName('no such field'));
pin('findBySlug resolves a field by its slug', (string)$fNamed, $model->findBySlug('engine-size')['pk_i_id']);
pin('findBySlug on an unknown slug returns an empty array', array(), $model->findBySlug('no-such-slug'));
pin('findBySlug on the empty string returns an empty array', array(), $model->findBySlug(''));
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('findByName(null) returns an empty array rather than raising', array(), $model->findByName(null));
pin('findBySlug(null) returns an empty array rather than raising', array(), $model->findBySlug(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * listAll() — ordering and the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('Field::listAll — empty table and ordering');

scratchdb_truncate_all($admin);
pin('an empty table returns an empty array', array(), $model->listAll());

$fThird  = $seedField('Third', 'third', 'TEXT', 0, 0, null, null, 30);
$fFirst   = $seedField('First', 'first', 'TEXT', 0, 0, null, null, 10);
$fSecond = $seedField('Second', 'second', 'TEXT', 0, 0, null, null, 20);

$all = $model->listAll();
check('the result is a plain list', is_array($all) && array_keys($all) === array(0, 1, 2), describe($all));
pin('exactly three rows', 3, count($all));
pin(
    'rows come back by i_position ASC, not by insertion or pk order',
    array((string)$fFirst, (string)$fSecond, (string)$fThird),
    array_column($all, 'pk_i_id')
);
pin(
    'each row carries the schema columns plus the appended locale key',
    array('pk_i_id', 's_name', 'e_type', 'b_required', 'b_searchable', 's_slug', 's_options', 's_meta', 'i_position', 'fk_i_group_id', 'locale'),
    array_keys($all[0])
);
$firstColsOnly = $all[0];
unset($firstColsOnly['locale']);
check('every scalar column of every row is a string or null (C4)', all_values_string($firstColsOnly), describe($firstColsOnly));

/* ----------------------------------------------------------------------------
 * categoryPath() — the F8 ancestry walk. Costs pinned at two depths and across
 * two calls so the re-walk (no memoization) is visible.
 * ------------------------------------------------------------------------- */
harness_section('Field::categoryPath — the inheritance path');

scratchdb_truncate_all($admin);
$cRoot  = seed_category($admin, 'Motors');
$cMid   = seed_category($admin, 'Cars', $cRoot);
$cLeaf  = seed_category($admin, 'Sedans', $cMid);
$cAlone = seed_category($admin, 'Boats');

pin('a leaf resolves to leaf-then-ancestors, nearest first, as native ints', array($cLeaf, $cMid, $cRoot), $model->categoryPath($cLeaf));
pin('a root resolves to just itself', array($cRoot), $model->categoryPath($cRoot));
pin('an unknown category still returns itself (the walk stops at the missing parent)', array(424242), $model->categoryPath(424242));
pin('category 0 returns an empty array without a query', array(), $model->categoryPath(0));
pin('a negative category returns an empty array', array(), $model->categoryPath(-5));
pin('a non-numeric category returns an empty array', array(), $model->categoryPath('not a number'));

harness_section('Field::categoryPath — the F8 re-walk cost (baseline, not a fix)');

pin('a root path costs one query (depth 1)', 1, harness_query_count(static function () use ($model, $cRoot) {
    $model->categoryPath($cRoot);
}));
pin('a three-deep path costs three queries — one per ancestor level', 3, harness_query_count(static function () use ($model, $cLeaf) {
    $model->categoryPath($cLeaf);
}));
pin('the walk is repeated in full on every call — two calls cost double', 6, harness_query_count(static function () use ($model, $cLeaf) {
    $model->categoryPath($cLeaf);
    $model->categoryPath($cLeaf);
}));

/* ----------------------------------------------------------------------------
 * findByGroup() — fields of a form, ordered by their per-form position.
 * ------------------------------------------------------------------------- */
harness_section('Field::findByGroup — the link-table membership and ordering');

scratchdb_truncate_all($admin);
$gForm = $seedGroup('Contact', 'contact', 0);
$fA = $seedField('A field', 'a-field', 'TEXT', 0, 0, null, null, 99);
$fB = $seedField('B field', 'b-field', 'TEXT', 0, 0, null, null, 1);
// Link order is deliberately the reverse of both pk and field i_position, so the
// per-form gf.i_position ordering is what is actually being tested.
$linkFieldToGroup($gForm, $fA, 0);
$linkFieldToGroup($gForm, $fB, 1);

$grouped = $model->findByGroup($gForm);
pin('exactly the two linked fields come back', 2, count($grouped));
pin(
    'they are ordered by the link table position (gf.i_position), not by pk or field position',
    array((string)$fA, (string)$fB),
    array_column($grouped, 'pk_i_id')
);
check('each grouped row is extended (carries a locale key)', isset($grouped[0]['locale']), describe($grouped[0]));
pin('a group with no fields returns an empty array', array(), $model->findByGroup($seedGroup('Empty', 'empty', 0)));
pin('an unknown group returns an empty array', array(), $model->findByGroup(999999));

/* ----------------------------------------------------------------------------
 * findByCategory() — inheritance, loose + grouped union, dedup, ordering.
 * ------------------------------------------------------------------------- */
harness_section('Field::findByCategory — bad input and no match');

scratchdb_truncate_all($admin);
$dRoot  = seed_category($admin, 'Motors');
$dChild = seed_category($admin, 'Cars', $dRoot);
$dAlone = seed_category($admin, 'Boats');

pin('category 0 returns an empty array without touching the database', array(), $model->findByCategory(0));
pin('a category with no fields returns an empty array', array(), $model->findByCategory($dAlone));

harness_section('Field::findByCategory — loose inheritance and grouped fields unioned');

// A loose field on the PARENT (inherited by the child), a loose field on the
// child itself, and a grouped field whose form is assigned to the child.
$fLooseParent = $seedField('Loose parent', 'loose-parent', 'TEXT', 0, 0, null, null, 5);
$fLooseChild  = $seedField('Loose child', 'loose-child', 'TEXT', 0, 0, null, null, 2);
$fGrouped     = $seedField('Grouped', 'grouped', 'TEXT', 0, 0, null, null, 1);
$gCat         = $seedGroup('Specs', 'specs', 7);
$linkFieldToCat($dRoot, $fLooseParent);
$linkFieldToCat($dChild, $fLooseChild);
$linkFieldToGroup($gCat, $fGrouped, 0);
$linkGroupToCat($gCat, $dChild);

$catFields = $model->findByCategory($dChild);
$catIds = array_column($catFields, 'pk_i_id');
sort($catIds);
$expectedCatIds = array((string)$fLooseChild, (string)$fLooseParent, (string)$fGrouped);
sort($expectedCatIds);
pin(
    'the child sees its own loose field, the inherited parent one, and the grouped one',
    $expectedCatIds,
    $catIds
);
pin(
    'loose fields (cf_group_position 0) sort before grouped ones, then by field position',
    array((string)$fLooseChild, (string)$fLooseParent, (string)$fGrouped),
    array_column($catFields, 'pk_i_id')
);
pin(
    'each row is mf.* plus cf_group_position plus the appended locale key',
    array('pk_i_id', 's_name', 's_slug', 'e_type', 's_options', 'b_required', 'b_searchable', 's_meta', 'i_position', 'fk_i_group_id', 'cf_group_position', 'locale'),
    array_keys($catFields[0])
);
$catRowCols = $catFields[0];
unset($catRowCols['locale']);
check('every scalar column is a string or null (C4)', all_values_string($catRowCols), describe($catRowCols));

harness_section('Field::findByCategory — a loose field that is also grouped is deduped');

// A field both loosely assigned AND grouped reaches the union twice; GROUP BY
// pk_i_id collapses it to one row.
scratchdb_truncate_all($admin);
$eCat = seed_category($admin, 'Flat');
$fDup = $seedField('Dup', 'dup', 'TEXT', 0, 0, null, null, 0);
$gDup = $seedGroup('DupForm', 'dupform', 0);
$linkFieldToCat($eCat, $fDup);
$linkFieldToGroup($gDup, $fDup, 0);
$linkGroupToCat($gDup, $eCat);
// A loose field is only returned by the loose arm when it is in NO form, so a
// field that is both loose and grouped comes purely from the grouped arm here.
pin('a field reachable by both arms appears exactly once', 1, count($model->findByCategory($eCat)));

harness_section('Field::findByCategory — the parent does not see the child\'s fields');

scratchdb_truncate_all($admin);
$hRoot  = seed_category($admin, 'Root');
$hChild = seed_category($admin, 'Child', $hRoot);
$fOnChild = $seedField('On child', 'on-child', 'TEXT', 0, 0, null, null, 0);
$linkFieldToCat($hChild, $fOnChild);
pin('inheritance runs downwards only — the parent sees nothing', array(), $model->findByCategory($hRoot));
pin('the child sees the field', array((string)$fOnChild), array_column($model->findByCategory($hChild), 'pk_i_id'));

/* ----------------------------------------------------------------------------
 * findIDSearchableByCategories() — searchable ids across the inheritance path.
 * ------------------------------------------------------------------------- */
harness_section('Field::findIDSearchableByCategories — searchable resolution');

scratchdb_truncate_all($admin);
$sRoot  = seed_category($admin, 'Motors');
$sChild = seed_category($admin, 'Cars', $sRoot);
$fSearchLoose = $seedField('Searchable loose', 'search-loose', 'TEXT', 0, 1, null, null, 0);
$fNotSearch   = $seedField('Not searchable', 'not-search', 'TEXT', 0, 0, null, null, 0);
$fSearchGroup = $seedField('Searchable grouped', 'search-grouped', 'TEXT', 0, 1, null, null, 0);
$gSearch = $seedGroup('SearchForm', 'searchform', 0);
$linkFieldToCat($sRoot, $fSearchLoose);   // searchable, loose, on parent -> inherited
$linkFieldToCat($sRoot, $fNotSearch);     // not searchable -> excluded
$linkFieldToGroup($gSearch, $fSearchGroup, 0);
$linkGroupToCat($gSearch, $sChild);       // searchable, grouped, on child

$ids = $model->findIDSearchableByCategories($sChild);
$idsSorted = $ids;
sort($idsSorted);
$expectedSearch = array((string)$fSearchGroup, (string)$fSearchLoose);
sort($expectedSearch);
pin(
    'only searchable fields across the path are returned, deduped',
    $expectedSearch,
    $idsSorted
);
check('the ids are strings, as the legacy result path produced (C4)', $ids === array() ? true : is_string($ids[0]), describe($ids[0] ?? null));
pin('an array of ids is accepted, not just a scalar', true, is_array($model->findIDSearchableByCategories(array($sChild))));
pin('a category with no searchable fields returns an empty array', array(), $model->findIDSearchableByCategories(seed_category($admin, 'Bare')));
pin('an empty resolved path returns an empty array', array(), $model->findIDSearchableByCategories(0));

harness_section('Field::findIDSearchableByCategories — slug resolution of a non-numeric id');

// A non-numeric id is resolved through Category::findBySlug. Seed a category
// with a resolvable slug and a searchable field on it.
scratchdb_truncate_all($admin);
$slugCat = seed_category($admin, 'Bikes'); // slug 'bikes'
$fBikeSearch = $seedField('Bike search', 'bike-search', 'TEXT', 0, 1, null, null, 0);
$linkFieldToCat($slugCat, $fBikeSearch);
pin('a slug id resolves through Category and returns its searchable field', array((string)$fBikeSearch), $model->findIDSearchableByCategories('bikes'));
pin('an unresolvable slug contributes nothing', array(), $model->findIDSearchableByCategories('no-such-slug'));

/* ----------------------------------------------------------------------------
 * findByCategoryItem() — fields for a category joined with an item's values.
 * ------------------------------------------------------------------------- */
harness_section('Field::findByCategoryItem — bad input');

scratchdb_truncate_all($admin);
pin('a non-numeric category returns an empty array', array(), $model->findByCategoryItem('abc', 1));
pin('a non-numeric, non-null item returns an empty array', array(), $model->findByCategoryItem(1, 'abc'));

harness_section('Field::findByCategoryItem — values joined per field');

$iRoot  = seed_category($admin, 'Motors');
$iChild = seed_category($admin, 'Cars', $iRoot);
$fVal   = $seedField('Colour', 'colour', 'TEXT', 0, 0, null, null, 2);
$fGrpVal = $seedField('Doors', 'doors', 'NUMBER', 0, 0, null, null, 1);
$gForm2 = $seedGroup('CarSpecs', 'carspecs', 3);
$linkFieldToCat($iRoot, $fVal);          // loose on parent, inherited
$linkFieldToGroup($gForm2, $fGrpVal, 0); // grouped
$linkGroupToCat($gForm2, $iChild);
// A real item is needed only as a join target; seed one plus its stored values.
$itemCat = seed_category($admin, 'JoinCat');
$itemId  = seed_item($admin, $itemCat);
$seedItemMeta($itemId, $fVal, 'Blue');
$seedItemMeta($itemId, $fGrpVal, '4');

$ci = $model->findByCategoryItem($iChild, $itemId);
pin('both the inherited loose field and the grouped field resolve', 2, count($ci));
pin(
    'loose (cf_group_position 0) sorts before grouped, then by field position',
    array((string)$fVal, (string)$fGrpVal),
    array_column($ci, 'pk_i_id')
);
$byId = array();
foreach ($ci as $f) {
    $byId[$f['pk_i_id']] = $f;
}
pin('the loose field carries its stored value from t_item_meta', 'Blue', $byId[(string)$fVal]['s_value']);
pin('the grouped field carries its stored value', '4', $byId[(string)$fGrpVal]['s_value']);
pin('each row carries the item id it was joined against, as a string (C4)', (string)$itemId, $byId[(string)$fVal]['fk_i_item_id']);
pin(
    'each row is mf.* plus the group/field-position markers, the joined value/item, and locale',
    array('pk_i_id', 's_name', 's_slug', 'e_type', 's_options', 'b_required', 'b_searchable', 's_meta', 'i_position', 'fk_i_group_id', 'cf_group_name', 'cf_group_position', 'cf_field_position', 's_value', 'fk_i_item_id', 'locale'),
    array_keys($ci[0])
);

harness_section('Field::findByCategoryItem — a field with no stored value joins to null');

$ci2 = $model->findByCategoryItem($iChild, 999999); // no item_meta for this id
$byId2 = array();
foreach ($ci2 as $f) {
    $byId2[$f['pk_i_id']] = $f;
}
pin('the fields still resolve for an item with no stored values', 2, count($ci2));
pin('an unmatched value column is null', null, $byId2[(string)$fVal]['s_value']);

harness_section('Field::findByCategoryItem — dedup keeps the first occurrence');

// A field reachable both loosely and via a group reaches the result twice; the
// render-time dedup keeps only the first.
scratchdb_truncate_all($admin);
$jCat = seed_category($admin, 'Flat');
$fBoth = $seedField('Both', 'both', 'TEXT', 0, 0, null, null, 0);
$gBoth = $seedGroup('BothForm', 'bothform', 0);
$linkFieldToCat($jCat, $fBoth);
$linkFieldToGroup($gBoth, $fBoth, 0);
$linkGroupToCat($gBoth, $jCat);
$jItem = seed_item($admin, seed_category($admin, 'JItemCat'));
pin('a field reachable twice appears once', 1, count($model->findByCategoryItem($jCat, $jItem)));

/* ----------------------------------------------------------------------------
 * findByItem() — fields an item has a stored value for.
 * ------------------------------------------------------------------------- */
harness_section('Field::findByItem — stored values only, ordered by field position');

scratchdb_truncate_all($admin);
$kCat  = seed_category($admin, 'Motors');
$kItem = seed_item($admin, $kCat);
$fLate  = $seedField('Late', 'late', 'TEXT', 0, 0, null, null, 9);
$fEarly = $seedField('Early', 'early', 'NUMBER', 0, 0, null, null, 1);
$fUnused = $seedField('Unused', 'unused', 'TEXT', 0, 0, null, null, 0);
$seedItemMeta($kItem, $fLate, 'nine');
$seedItemMeta($kItem, $fEarly, '1');

pin('a non-numeric item returns an empty array', array(), $model->findByItem('abc'));
$fi = $model->findByItem($kItem);
pin('only fields with a stored value are returned', 2, count($fi));
pin(
    'ordered by the field i_position ASC',
    array((string)$fEarly, (string)$fLate),
    array_column($fi, 'pk_i_id')
);
pin(
    'each row carries exactly the selected alias columns plus locale',
    array('pk_i_id', 's_value', 's_name', 'e_type', 's_multi', 's_slug', 's_meta', 'locale'),
    array_keys($fi[0])
);
$byIdFi = array();
foreach ($fi as $f) {
    $byIdFi[$f['pk_i_id']] = $f;
}
pin('the stored value comes through as a string (C4)', 'nine', $byIdFi[(string)$fLate]['s_value']);
$fiCols = $fi[0];
unset($fiCols['locale']);
check('every scalar column is a string or null (C4)', all_values_string($fiCols), describe($fiCols));
pin('an item with no stored values returns an empty array', array(), $model->findByItem(seed_item($admin, seed_category($admin, 'Empty item cat'))));

/* ----------------------------------------------------------------------------
 * getDateIntervalByPrimaryKey() — the from/to pair keyed by s_multi.
 * ------------------------------------------------------------------------- */
harness_section('Field::getDateIntervalByPrimaryKey — the interval map');

scratchdb_truncate_all($admin);
$dCat  = seed_category($admin, 'Motors');
$dItem = seed_item($admin, $dCat);
$fInterval = $seedField('When', 'when', 'DATEINTERVAL', 0, 0, null, null, 0);
$seedItemMeta($dItem, $fInterval, '2026-01-01', 'from');
$seedItemMeta($dItem, $fInterval, '2026-12-31', 'to');

pin('a non-numeric item id returns an empty array', array(), $model->getDateIntervalByPrimaryKey('x', $fInterval));
pin('a non-numeric field id returns an empty array', array(), $model->getDateIntervalByPrimaryKey($dItem, 'x'));
pin(
    'the two rows come back keyed by s_multi with their s_value (C4)',
    array('from' => '2026-01-01', 'to' => '2026-12-31'),
    $model->getDateIntervalByPrimaryKey($dItem, $fInterval)
);
pin('no rows returns an empty array', array(), $model->getDateIntervalByPrimaryKey($dItem, 999999));

/* ----------------------------------------------------------------------------
 * categories() — the category ids linked to a field (raw strings).
 * ------------------------------------------------------------------------- */
harness_section('Field::categories — the linked category ids');

scratchdb_truncate_all($admin);
$catX = seed_category($admin, 'Motors');
$catY = seed_category($admin, 'Boats');
$fCats = $seedField('Multi', 'multi', 'TEXT', 0, 0, null, null, 0);
$linkFieldToCat($catX, $fCats);
$linkFieldToCat($catY, $fCats);

$cats = $model->categories($fCats);
sort($cats);
pin('both linked category ids come back', array((string)$catX, (string)$catY), $cats);
check(
    'the ids are STRINGS, unlike the FieldGroup sibling which casts to int (C4)',
    $cats === array() ? true : is_string($cats[0]),
    describe($cats[0] ?? null)
);
pin('a field with no links returns an empty array', array(), $model->categories($seedField('Nolinks', 'nolinks')));
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null id returns an empty array rather than raising', array(), $model->categories(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * insertField() — the write, the return ledger, and trap-2.2 propagation.
 * ------------------------------------------------------------------------- */
harness_section('Field::insertField — a fresh field');

scratchdb_truncate_all($admin);
$okName = $model->insertField('Warranty', 'TEXT', 'warranty', 0, '', array());
pin('a successful insert with no categories returns bool true', true, $okName);
// Trap 2.2: an external caller (CAdminAjax) reads $model->dao->insertedId()
// straight after insertField(); with no category writes the field insert is the
// last statement, so the new id must still be readable off the shared handle.
$insertedId = (int)$model->dao->insertedId();
check('the new field id is readable via dao->insertedId() after the call (trap 2.2)', $insertedId > 0, describe($insertedId));
$stored = $model->findBySlug('warranty');
pin('the field id from insertedId matches the stored row', (string)$insertedId, $stored['pk_i_id']);
pin('s_name is stored', 'Warranty', $stored['s_name']);
pin('e_type is stored', 'TEXT', $stored['e_type']);

harness_section('Field::insertField — a derived slug and slug collision');

scratchdb_truncate_all($admin);
$model->insertField('My Field', 'TEXT', '', 0, '', array());
pin('an empty slug is derived from the name', 'my-field', $model->listAll()[0]['s_slug']);
$model->insertField('My Field', 'TEXT', '', 0, '', array());
$slugs = array_column($model->listAll(), 's_slug');
sort($slugs);
pin('a colliding derived slug is uniquified with _1', array('my-field', 'my-field_1'), $slugs);

harness_section('Field::insertField — categories are linked');

scratchdb_truncate_all($admin);
$icA = seed_category($admin, 'Motors');
$icB = seed_category($admin, 'Boats');
$res = $model->insertField('Linked', 'TEXT', 'linked', 0, '', array($icA, $icB));
pin('inserting with valid categories returns bool true', true, $res);
$fid = $model->findBySlug('linked')['pk_i_id'];
$linked = array_column($rawAll("SELECT fk_i_category_id FROM $metaCat WHERE fk_i_field_id = $fid"), 'fk_i_category_id');
sort($linked);
pin('both categories are linked to the new field', array((string)$icA, (string)$icB), $linked);

/* ----------------------------------------------------------------------------
 * insertCategories() — note the empty-array-equals-null quirk.
 * ------------------------------------------------------------------------- */
harness_section('Field::insertCategories — the return ledger');

scratchdb_truncate_all($admin);
$pcA = seed_category($admin, 'Motors');
$pcB = seed_category($admin, 'Boats');
$fLink = $seedField('Linkable', 'linkable');

pin('assigning two categories returns bool true', true, $model->insertCategories($fLink, array($pcA, $pcB)));
$got = array_column($rawAll("SELECT fk_i_category_id FROM $metaCat WHERE fk_i_field_id = $fLink"), 'fk_i_category_id');
sort($got);
pin('both are written', array((string)$pcA, (string)$pcB), $got);
pin('a null category list returns bool false', false, $model->insertCategories($fLink));
// An empty array is loosely equal to null, so the != null guard skips the loop
// and the method returns false — NOT true as its FieldGroup sibling does.
pin('an empty array returns bool false (differs from FieldGroup)', false, $model->insertCategories($fLink, array()));

harness_section('Field::insertCategories — a failing row does not stop the rest');

scratchdb_truncate_all($admin);
$qcA = seed_category($admin, 'Motors');
$qcB = seed_category($admin, 'Boats');
$fFold = $seedField('Fold', 'fold');
$model->insertCategories($fFold, array($qcA));
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a duplicate assignment makes the whole call report false', false, $model->insertCategories($fFold, array($qcA)));
pin('a good id alongside a duplicate is still written', false, $model->insertCategories($fFold, array($qcA, $qcB)));
error_reporting($prevLevel);
$folded = array_column($rawAll("SELECT fk_i_category_id FROM $metaCat WHERE fk_i_field_id = $fFold"), 'fk_i_category_id');
sort($folded);
pin('the good id survived the failing one in the same call', array((string)$qcA, (string)$qcB), $folded);

/* ----------------------------------------------------------------------------
 * cleanCategoriesFromField() — the null/zero-row divergence.
 * ------------------------------------------------------------------------- */
harness_section('Field::cleanCategoriesFromField — the return ledger');

scratchdb_truncate_all($admin);
$rcA = seed_category($admin, 'Motors');
$rcB = seed_category($admin, 'Boats');
$fClean = $seedField('Clean', 'clean');
$model->insertCategories($fClean, array($rcA, $rcB));
pin('clearing two assignments reports int 2', 2, $model->cleanCategoriesFromField($fClean));
pin('the assignments are gone', array(), $model->categories($fClean));
pin('clearing a field with none reports int 0, not false', 0, $model->cleanCategoriesFromField($fClean));
// A null id makes the where clause malformed and the delete reports false; an id
// matching nothing succeeds and reports int 0. The two are not the same value.
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null id returns bool false, not int 0', false, $model->cleanCategoriesFromField(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * deleteByPrimaryKey() — the four-table cascade and the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('Field::deleteByPrimaryKey — the full cascade');

scratchdb_truncate_all($admin);
$delCat  = seed_category($admin, 'Motors');
$delItemCat = seed_category($admin, 'ItemCat');
$delItem = seed_item($admin, $delItemCat);
$fGone  = $seedField('Doomed', 'doomed');
$fStays = $seedField('Survivor', 'survivor');
$gGone  = $seedGroup('DoomedForm', 'doomedform', 0);
$linkFieldToCat($delCat, $fGone);
$linkFieldToCat($delCat, $fStays);
$linkFieldToGroup($gGone, $fGone, 0);
$seedItemMeta($delItem, $fGone, 'value');

pin('deleting a field reports int 1', 1, $model->deleteByPrimaryKey($fGone));
pin('the field row is gone', array(), $model->findByPrimaryKey($fGone));
pin('its t_item_meta values are gone', array(), $rawAll("SELECT * FROM $itemMeta WHERE fk_i_field_id = $fGone"));
pin('its t_meta_categories links are gone', array(), $rawAll("SELECT * FROM $metaCat WHERE fk_i_field_id = $fGone"));
pin('its t_meta_group_fields memberships are gone', array(), $rawAll("SELECT * FROM $groupFld WHERE fk_i_field_id = $fGone"));
pin('the other field survives', 1, count($rawAll("SELECT * FROM $fieldsTbl WHERE pk_i_id = $fStays")));
pin('the other field keeps its category link', 1, count($rawAll("SELECT * FROM $metaCat WHERE fk_i_field_id = $fStays")));

harness_section('Field::deleteByPrimaryKey — no such field and a malformed id');

pin('an unknown id returns int 0, not false', 0, $model->deleteByPrimaryKey(999999));
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null id returns bool false, not int 0', false, $model->deleteByPrimaryKey(null));
error_reporting($prevLevel);
pin('the surviving field is untouched by the malformed call', 1, count($rawAll("SELECT * FROM $fieldsTbl WHERE pk_i_id = $fStays")));

/* ----------------------------------------------------------------------------
 * replace() — REPLACE INTO t_item_meta, scalar and array forms.
 * ------------------------------------------------------------------------- */
harness_section('Field::replace — a scalar value');

scratchdb_truncate_all($admin);
$repCat  = seed_category($admin, 'Motors');
$repItem = seed_item($admin, $repCat);
$fRep = $seedField('Rep', 'rep');

pin('a scalar replace returns bool true', true, $model->replace($repItem, $fRep, 'first'));
pin(
    'the value is written with an empty s_multi',
    array(array('s_value' => 'first', 's_multi' => '')),
    $rawAll("SELECT s_value, s_multi FROM $itemMeta WHERE fk_i_item_id = $repItem AND fk_i_field_id = $fRep")
);
pin('a second scalar replace overwrites in place', true, $model->replace($repItem, $fRep, 'second'));
pin(
    'the row was replaced, not duplicated',
    array(array('s_value' => 'second', 's_multi' => '')),
    $rawAll("SELECT s_value, s_multi FROM $itemMeta WHERE fk_i_item_id = $repItem AND fk_i_field_id = $fRep")
);

harness_section('Field::replace — an array value writes one row per key and returns null');

$fRepArr = $seedField('RepArr', 'reparr');
pin('the array form returns null (no return statement)', null, $model->replace($repItem, $fRepArr, array('from' => 'a', 'to' => 'b')));
$arrRows = $rawAll("SELECT s_multi, s_value FROM $itemMeta WHERE fk_i_item_id = $repItem AND fk_i_field_id = $fRepArr ORDER BY s_multi");
pin('one row per key was written, keyed by s_multi', array(
    array('s_multi' => 'from', 's_value' => 'a'),
    array('s_multi' => 'to', 's_value' => 'b'),
), $arrRows);

/* ----------------------------------------------------------------------------
 * updateJsonMeta() / getJsonMetaValue() — the s_meta JSON column.
 * ------------------------------------------------------------------------- */
harness_section('Field::updateJsonMeta — setting, merging and clearing keys');

scratchdb_truncate_all($admin);
$fJson = $seedField('Jsonic', 'jsonic');
pin('setting a key on a NULL s_meta reports one changed row', 1, $model->updateJsonMeta($fJson, 'type', 'DROPDOWN'));
pin('the key lands as JSON', '{"type":"DROPDOWN"}', $model->findByPrimaryKey($fJson)['s_meta']);
pin('a second key is merged', 1, $model->updateJsonMeta($fJson, 'b_new_tab', 1));
pin('both keys are present', '{"type":"DROPDOWN","b_new_tab":1}', $model->findByPrimaryKey($fJson)['s_meta']);
pin('rewriting an identical value reports int 0 (no rows changed), not false', 0, $model->updateJsonMeta($fJson, 'type', 'DROPDOWN'));
pin('an empty-string value removes the key', 1, $model->updateJsonMeta($fJson, 'type', ''));
pin('the remaining key survives', '{"b_new_tab":1}', $model->findByPrimaryKey($fJson)['s_meta']);
pin('a null value removes the key too', 1, $model->updateJsonMeta($fJson, 'b_new_tab', null));
pin('the now-empty meta is stored as the JSON text "[]"', '[]', $model->findByPrimaryKey($fJson)['s_meta']);

harness_section('Field::updateJsonMeta — unknown and malformed ids');

// An unknown id: the read finds no row, the UPDATE matches nothing, and the
// method reports int 0 (the read does not fail).
pin('an unknown id reports int 0', 0, $model->updateJsonMeta(999999, 'type', 'X'));
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null id reports bool false (the read where-clause is malformed)', false, $model->updateJsonMeta(null, 'type', 'X'));
error_reporting($prevLevel);

harness_section('Field::getJsonMetaValue — from a passed row and from the database');

scratchdb_truncate_all($admin);
$fGet = $seedField('Getter', 'getter', 'TEXT', 0, 0, null, '{"type":"RADIO","b_new_tab":1}');
pin('a value is read straight from a passed field row without a query', 'RADIO', $model->getJsonMetaValue('type', array('s_meta' => '{"type":"RADIO"}')));
pin('a missing key on a passed row returns false', false, $model->getJsonMetaValue('nope', array('s_meta' => '{"type":"RADIO"}')));
pin('a passed row with empty s_meta returns false', false, $model->getJsonMetaValue('type', array('s_meta' => '')));
pin('reading by metaId from the database returns the JSON value', 'RADIO', $model->getJsonMetaValue('type', null, $fGet));
pin('a native JSON int comes back as an int', 1, $model->getJsonMetaValue('b_new_tab', null, $fGet));
pin('a missing key read by metaId returns false', false, $model->getJsonMetaValue('missing', null, $fGet));
pin('no field and no metaId returns false', false, $model->getJsonMetaValue('type'));
pin('an unknown metaId returns false', false, $model->getJsonMetaValue('type', null, 999999));

/* ----------------------------------------------------------------------------
 * Query cost of the union reads (F8 walk plus one union query each).
 * ------------------------------------------------------------------------- */
harness_section('Field: union-read query cost');

scratchdb_truncate_all($admin);
$costRoot  = seed_category($admin, 'Motors');
$costChild = seed_category($admin, 'Cars', $costRoot);
$fCost = $seedField('Cost', 'cost', 'TEXT', 0, 1, null, null, 0);
$linkFieldToCat($costRoot, $fCost);
$costItem = seed_item($admin, seed_category($admin, 'CostItemCat'));

pin('findByPrimaryKey costs one query', 1, harness_query_count(static function () use ($model, $fCost) {
    $model->findByPrimaryKey($fCost);
}));
pin('findByGroup costs one query', 1, harness_query_count(static function () use ($model) {
    $model->findByGroup(1);
}));
pin('findByItem costs one query', 1, harness_query_count(static function () use ($model, $costItem) {
    $model->findByItem($costItem);
}));
pin('findByCategory on a two-deep category costs the 2-level walk plus one union query', 3, harness_query_count(static function () use ($model, $costChild) {
    $model->findByCategory($costChild);
}));
pin('findByCategoryItem on a two-deep category costs the 2-level walk plus one union query', 3, harness_query_count(static function () use ($model, $costChild, $costItem) {
    $model->findByCategoryItem($costChild, $costItem);
}));
pin('findIDSearchableByCategories on a two-deep category costs the walk plus one union query', 3, harness_query_count(static function () use ($model, $costChild) {
    $model->findIDSearchableByCategories($costChild);
}));

/* ----------------------------------------------------------------------------
 * AMENDMENT T — deliberate behaviour change, RED against the pre-conversion
 * code (amendment X). The legacy layer left an is_numeric() value BARE in the
 * comparison, so `s_name = 0` / `s_slug = 0` coerced the VARCHAR column and a
 * numeric-looking lookup matched an unrelated alphabetic row. The parameterized
 * layer binds the value as a string, so the comparison stays a string
 * comparison. This is type confusion in the legacy path; per policy it is not
 * reproduced. These two pins are therefore expected to FAIL against the
 * unconverted model and PASS after conversion.
 * ------------------------------------------------------------------------- */
harness_section('Field: AMENDMENT T — VARCHAR coercion dropped (RED pre-conversion)');

scratchdb_truncate_all($admin);
$seedField('Alphabetic', 'alphabetic', 'TEXT'); // s_slug='alphabetic', s_name='Alphabetic'
// Legacy: `s_slug = 0` coerces 'alphabetic' -> 0 and returns that row.
// Converted: bound '0' compares as a string, so nothing matches.
pin('findBySlug("0") no longer coerces the VARCHAR column to a numeric match', array(), $model->findBySlug('0'));
pin('findByName("0") no longer coerces the VARCHAR column to a numeric match', array(), $model->findByName('0'));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/field.php */
