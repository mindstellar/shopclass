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
 * Characterization pins for the Page model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * the method bodies move to the parameterized query layer.
 *
 * Two things here are pinned as observed rather than as documented:
 *
 *  - listAll()'s $start and $limit arguments are semantically swapped. The
 *    legacy call passes them to the query layer in an order that makes $start
 *    the row count and $limit the offset. Every pin below uses fixtures where
 *    the two differ, so the swap is recorded rather than hidden by symmetric
 *    values.
 *  - insert() reads the connection's affected-row count after its own write to
 *    decide success. That side channel is read only inside this model; no
 *    caller reaches it, which is what makes the write convertible at all.
 *
 * listAll() also issues one description query per page it returns. That is the
 * single most-executed N+1 in the codebase, since the footer calls it on every
 * public page render. Its cost is pinned at two page counts so the batching work
 * has a measured baseline rather than an estimate.
 *
 * Usage:  php tests/models/page.php          (standalone, own scratch database)
 *         php tests/run-models.php page      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_page');

$locale = seed_locale($admin);
seed_locale($admin, 'fr_FR', 'French');

$model = Page::newInstance();
$table = DB_TABLE_PREFIX . 't_pages';
$dtbl  = DB_TABLE_PREFIX . 't_pages_description';

/** Raw, unprepared reads so numeric columns come back as strings. */
$rawRows = static function (string $sql) use ($admin): array {
    $res  = $admin->query($sql);
    $rows = array();
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $res->free();

    return $rows;
};
$rawCount = static function (string $sql) use ($admin): int {
    return (int)$admin->query($sql)->fetch_assoc()['c'];
};

/** Seed $n pages with contiguous i_order starting at 0. */
$seedPages = static function (int $n) use ($admin, $table): array {
    $ids = array();
    for ($i = 1; $i <= $n; $i++) {
        $ids[] = seed_page($admin, 'p' . $i, 'Page ' . $i);
    }
    $admin->query('SET @r := -1');
    $admin->query("UPDATE $table SET i_order = (@r := @r + 1) ORDER BY pk_i_id");

    return $ids;
};

$resetPages = static function () use ($admin, $table, $dtbl): void {
    $admin->query("DELETE FROM $dtbl");
    $admin->query("DELETE FROM $table");
};

/* ----------------------------------------------------------------------------
 * Surface (C2).
 * ------------------------------------------------------------------------- */
harness_section('Page: public surface');

pin('listAll signature is unchanged', 'public listAll($indelible = NULL, $b_link = NULL, $locale = NULL, $start = NULL, $limit = NULL)', harness_method_signature('Page', 'listAll'));
pin('insert signature is unchanged', 'public insert($aFields, $aFieldsDescription = NULL)', harness_method_signature('Page', 'insert'));
pin('extendDescription signature is unchanged', 'public extendDescription($aPage, $locale = NULL)', harness_method_signature('Page', 'extendDescription'));
pin('deleteByPrimaryKey signature is unchanged', 'public deleteByPrimaryKey($id)', harness_method_signature('Page', 'deleteByPrimaryKey'));
check('Page still extends DAO', is_subclass_of('Page', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('description table name is unchanged', $dtbl, $model->getDescriptionTableName());
pin('primary key is unchanged', 'pk_i_id', $model->getPrimaryKey());

/* ----------------------------------------------------------------------------
 * listAll — filters, ordering and the swapped paging arguments.
 * ------------------------------------------------------------------------- */
harness_section('Page::listAll — ordering and filters');

$resetPages();
$ids = $seedPages(6);

$all = $model->listAll();
pin('listAll() returns every page', 6, count($all));
pin('ordered by i_order ascending', array('p1', 'p2', 'p3', 'p4', 'p5', 'p6'), array_column($all, 's_internal_name'));
/* Every scalar column is a string; 'locale' is the nested description block the
 * model attaches, so it is excluded from the all-strings check rather than
 * making the check weaker. */
check(
    'every scalar value in a row is a string or null (C4)',
    all_values_string(array_diff_key($all[0], array('locale' => 1))),
    describe($all[0])
);
check('the locale key holds a nested array, not a scalar', is_array($all[0]['locale']));
check('each row carries its locale block', isset($all[0]['locale'][$locale]['s_title']));
pin('the locale block holds the description title', 'Page 1', $all[0]['locale'][$locale]['s_title']);

$admin->query("UPDATE $table SET b_indelible = 1 WHERE s_internal_name IN ('p1','p2')");
pin('filtering on indelible = 1 returns only those', 2, count($model->listAll(1)));
pin('filtering on indelible = 0 returns the rest', 4, count($model->listAll(0)));

$admin->query("UPDATE $table SET b_link = 0 WHERE s_internal_name = 'p3'");
$linked = $model->listAll(null, 1);
check('filtering on b_link = 1 excludes the unlinked page', !in_array('p3', array_column($linked, 's_internal_name'), true));

harness_section('Page::listAll — paging arguments are swapped');

/* Every case below uses a row count different from the offset, so the mapping
 * cannot be read two ways. $start behaves as the ROW COUNT and $limit as the
 * OFFSET, the reverse of what the parameter names say. Preserved, not corrected:
 * the admin pages table is the only caller and would change behaviour. */
$paged = static function ($start, $limit) use ($model) {
    return array_column($model->listAll(null, null, null, $start, $limit), 's_internal_name');
};

pin('listAll(start=1, limit=3) takes 1 row from offset 3', array('p4'), $paged(1, 3));
pin('listAll(start=3, limit=1) takes 3 rows from offset 1', array('p2', 'p3', 'p4'), $paged(3, 1));
pin('listAll(start=2, limit=1) takes 2 rows from offset 1', array('p2', 'p3'), $paged(2, 1));
pin('listAll(start=4, limit=2) takes 4 rows from offset 2', array('p3', 'p4', 'p5', 'p6'), $paged(4, 2));

/* A zero offset collapses the clause to its single-argument form, which is why
 * the first page of the admin table looks correct despite the swap. */
pin('listAll(start=2, limit=0) falls back to a plain row count', array('p1', 'p2'), $paged(2, 0));
pin('a null limit disables paging entirely', 6, count($model->listAll(null, null, null, 3, null)));

harness_section('Page::listAll — empty and locale-filtered');

$resetPages();
pin('an empty table returns an empty array', array(), $model->listAll());
$seedPages(2);
pin('an unknown locale drops every page — none has a description in it', array(), $model->listAll(null, null, 'de_DE'));

/* ----------------------------------------------------------------------------
 * The N+1 baseline (inventory item F7).
 * ------------------------------------------------------------------------- */
harness_section('Page::listAll — description query per page (N+1 baseline)');

$resetPages();
$seedPages(2);
$costTwo = harness_query_count(static function () use ($model) {
    $model->listAll();
});
$resetPages();
$seedPages(5);
$costFive = harness_query_count(static function () use ($model) {
    $model->listAll();
});

/* Was one listing query plus one per page. Now a listing query and a single
 * batched description lookup, whatever the page count. */
pin('two pages cost two queries', 2, $costTwo);
pin('five pages cost two queries', 2, $costFive);
check(
    'the cost no longer grows with the number of pages',
    $costTwo === $costFive,
    "n=2 -> $costTwo, n=5 -> $costFive"
);
harness_assert_no_n_plus_1(
    'listAll is flat across page counts',
    static function (int $n) use ($model, $resetPages, $seedPages) {
        $resetPages();
        $seedPages($n);
        $model->listAll();
    },
    2,
    8
);

/* ----------------------------------------------------------------------------
 * extendDescription.
 * ------------------------------------------------------------------------- */
harness_section('Page::extendDescription');

$resetPages();
$pid = seed_page($admin, 'about', 'About us');
seed_exec(
    $admin,
    "INSERT INTO $dtbl (fk_i_pages_id, fk_c_locale_code, s_title, s_text) VALUES (?, 'fr_FR', ?, ?)",
    'iss',
    array($pid, 'A propos', '<p>fr</p>')
);

$page = $model->findByPrimaryKey($pid);
$ext  = $model->extendDescription($page);
check('both locales are attached', isset($ext['locale'][$locale], $ext['locale']['fr_FR']));
pin('the requested locale narrows the block to one', array('fr_FR'), array_keys($model->extendDescription($page, 'fr_FR')['locale']));
pin('a page with no description row at all returns an empty array', array(), $model->extendDescription(array('pk_i_id' => 999999)));

/* A blank description row is skipped when building the locale block, but the
 * row still exists, so the page comes back with an EMPTY locale block rather
 * than being dropped. That distinction matters: listAll() keeps such a page,
 * because what it tests is whether the returned array is non-empty. */
$blank = seed_page($admin, 'blank', '');
$admin->query("UPDATE $dtbl SET s_title = '', s_text = '' WHERE fk_i_pages_id = $blank");
$blankExt = $model->extendDescription(array('pk_i_id' => $blank));
check('a blank-description page is still returned', $blankExt !== array(), describe($blankExt));
pin('its locale block is empty', array(), $blankExt['locale']);
check(
    'and listAll still includes it',
    in_array('blank', array_column($model->listAll(), 's_internal_name'), true)
);

/* ----------------------------------------------------------------------------
 * Single-row lookups.
 * ------------------------------------------------------------------------- */
harness_section('Page: single-row lookups');

pin('findByPrimaryKey returns the page', 'about', $model->findByPrimaryKey($pid)['s_internal_name']);
pin('findByPrimaryKey on a missing id returns an empty array', array(), $model->findByPrimaryKey(999999));
pin('findByInternalName returns the page', (string)$pid, $model->findByInternalName('about')['pk_i_id']);
pin('findByInternalName on an unknown name returns an empty array', array(), $model->findByInternalName('nope'));
check('the lookup row is all strings (C4)', all_values_string(array_diff_key($model->findByPrimaryKey($pid), array('locale' => 1))));

$resetPages();
$ids = $seedPages(4);
pin('findByOrder finds the page at that position', 'p3', $model->findByOrder(2)['s_internal_name']);
pin('findByOrder past the end returns an empty array', array(), $model->findByOrder(99));

harness_section('Page: prev/next navigation');

pin('findPrevPage returns the page below the given order', 'p2', $model->findPrevPage(2)['s_internal_name']);
pin('findNextPage returns the page above the given order', 'p4', $model->findNextPage(2)['s_internal_name']);
pin('findPrevPage at the first position returns an empty array', array(), $model->findPrevPage(0));
pin('findNextPage at the last position returns an empty array', array(), $model->findNextPage(3));

/* ----------------------------------------------------------------------------
 * count / existDescription / internalNameExists / isIndelible.
 * ------------------------------------------------------------------------- */
harness_section('Page: counts and existence checks');

pin('count() returns a string total', '4', $model->count());
$admin->query("UPDATE $table SET b_indelible = 1 WHERE s_internal_name = 'p1'");
pin('count(1) counts only indelible pages', '1', $model->count(1));
pin('count(0) counts only deletable pages', '3', $model->count(0));

pin('existDescription is true for a present row', true, $model->existDescription(array('fk_i_pages_id' => $ids[0], 'fk_c_locale_code' => $locale)));
pin('existDescription is false for a missing row', false, $model->existDescription(array('fk_i_pages_id' => $ids[0], 'fk_c_locale_code' => 'de_DE')));

pin('internalNameExists is true when another page holds the name', true, $model->internalNameExists($ids[1], 'p1'));
pin('internalNameExists is false for the page itself', false, $model->internalNameExists($ids[0], 'p1'));

pin('isIndelible is true for an indelible page', true, $model->isIndelible($ids[0]));
pin('isIndelible is false otherwise', false, $model->isIndelible($ids[1]));

/* ----------------------------------------------------------------------------
 * insert — including the affected-rows side channel.
 * ------------------------------------------------------------------------- */
harness_section('Page::insert');

$resetPages();
$ok = $model->insert(
    array('s_internal_name' => 'new', 'b_indelible' => 0, 'b_link' => 1, 's_meta' => ''),
    array($locale => array('s_title' => 'New page', 's_text' => '<p>body</p>'))
);
pin('a successful insert returns bool true', true, $ok);
pin('exactly one page row was written', 1, $rawCount("SELECT COUNT(*) c FROM $table"));
pin('its description row was written too', 1, $rawCount("SELECT COUNT(*) c FROM $dtbl"));

$rows = $rawRows("SELECT * FROM $table");
pin('the first page gets order 0', '0', $rows[0]['i_order']);
check('dt_pub_date was populated', $rows[0]['dt_pub_date'] !== null);

$model->insert(
    array('s_internal_name' => 'second', 'b_indelible' => 0, 'b_link' => 1, 's_meta' => ''),
    array($locale => array('s_title' => 'Second', 's_text' => 'x'))
);
$rows = $rawRows("SELECT * FROM $table ORDER BY i_order");
pin('the next page takes the following order', '1', $rows[1]['i_order']);

/* b_link is forced to 0 for an indelible page created without one. */
$model->insert(
    array('s_internal_name' => 'sys', 'b_indelible' => 1, 'b_link' => '', 's_meta' => ''),
    array($locale => array('s_title' => 'Sys', 's_text' => 'x'))
);
pin('an indelible page created with a blank link flag stores 0', '0', $rawRows("SELECT b_link FROM $table WHERE s_internal_name = 'sys'")[0]['b_link']);

/* ----------------------------------------------------------------------------
 * updateDescription — insert when absent, update when present.
 * ------------------------------------------------------------------------- */
harness_section('Page::updateDescription');

$target = $model->findByInternalName('new')['pk_i_id'];
pin('updating an existing description reports one changed row', 1, $model->updateDescription($target, $locale, 'Changed', '<p>changed</p>'));
pin('the new title landed', 'Changed', $rawRows("SELECT s_title FROM $dtbl WHERE fk_i_pages_id = $target AND fk_c_locale_code = '$locale'")[0]['s_title']);
pin('describing a locale that has no row inserts one', true, $model->updateDescription($target, 'fr_FR', 'Nouveau', '<p>fr</p>'));
pin('the inserted locale row exists', 1, $rawCount("SELECT COUNT(*) c FROM $dtbl WHERE fk_i_pages_id = $target AND fk_c_locale_code = 'fr_FR'"));

harness_section('Page: field updates');

pin('updateInternalName reports one changed row', 1, $model->updateInternalName($target, 'renamed'));
pin('the name changed', 'renamed', $model->findByPrimaryKey($target)['s_internal_name']);
pin('updateLink reports one changed row', 1, $model->updateLink($target, 0));
pin('the link flag changed', '0', $model->findByPrimaryKey($target)['b_link']);
pin('updateMeta reports one changed row', 1, $model->updateMeta($target, 'a:0:{}'));
pin('the meta changed', 'a:0:{}', $model->findByPrimaryKey($target)['s_meta']);
pin('updating to the same value reports zero changed rows', 0, $model->updateMeta($target, 'a:0:{}'));

/* ----------------------------------------------------------------------------
 * deleteByPrimaryKey — and the reordering it triggers.
 * ------------------------------------------------------------------------- */
harness_section('Page::deleteByPrimaryKey');

$resetPages();
$ids = $seedPages(4);

pin('deleting a page reports one removed row', 1, $model->deleteByPrimaryKey($ids[1]));
pin('the page is gone', 0, $rawCount("SELECT COUNT(*) c FROM $table WHERE pk_i_id = {$ids[1]}"));
pin('its description rows went with it', 0, $rawCount("SELECT COUNT(*) c FROM $dtbl WHERE fk_i_pages_id = {$ids[1]}"));
pin(
    'the pages after it close the gap in i_order',
    array('p1' => '0', 'p3' => '1', 'p4' => '2'),
    array_column($rawRows("SELECT s_internal_name, i_order FROM $table ORDER BY i_order"), 'i_order', 's_internal_name')
);

/* A missing id reads i_order off the empty lookup result before checking it,
 * so the delete still runs and reports nothing removed. */
$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('deleting a missing id reports zero removed rows', 0, $model->deleteByPrimaryKey(999999));
error_reporting($prevLevel);

harness_section('Page::deleteByInternalName');

pin('deleting by internal name removes the page', 1, $model->deleteByInternalName('p3'));
pin('it is gone', 0, $rawCount("SELECT COUNT(*) c FROM $table WHERE s_internal_name = 'p3'"));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/page.php */
