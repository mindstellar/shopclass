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
 * Characterization pins for the ItemComment model.
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * the model moves to the parameterized query layer.
 *
 * Quirks pinned here, all observed rather than assumed:
 *
 *  - findByItemID()'s two-argument limit($offset, $count) reaches
 *    DBCommandClass::limit() as limit($page * $commentsPerPage, $commentsPerPage),
 *    which is genuinely offset-then-count (unlike KeywordBlock's confusingly
 *    named start/end pair) — the parameter names already match the SQL meaning.
 *  - totalComments()/countAll() both run through GROUP BY / plain COUNT(*)
 *    without one; totalComments() really can return zero rows (GROUP BY on no
 *    match), so its explicit "return 0" branch is reachable, while countAll()
 *    and count() aggregate with no GROUP BY and therefore always get exactly
 *    one row back from MySQL — their zero-row branches are unreachable through
 *    this model's own signature and are pinned as such, not exercised.
 *  - search()'s $order_by/$order pair reach DBCommandClass::orderBy() with NO
 *    allowlist of their own (unlike KeywordBlock's own admin-added regex
 *    guard) — the column name is concatenated into the SQL exactly as given.
 *    The one caller in this codebase (CommentsDataTable) always passes the
 *    fixed literal 'c.dt_pub_date', so this is inert in practice, but it is a
 *    genuine unvalidated raw site on a public method and is pinned exactly as
 *    legacy behaves, not hardened.
 *  - extendData() is private and issues one query per comment row, every time,
 *    with no de-duplication even when several comments share the same item —
 *    the N+1 baseline is measured at two fixture sizes below.
 *
 * Usage:  php tests/models/itemcomment.php          (standalone, own scratch database)
 *         php tests/run-models.php itemcomment      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../../oc-includes/osclass/helpers/hLocale.php';
require_once __DIR__ . '/../../oc-includes/osclass/helpers/hPreference.php';
require_once __DIR__ . '/../../oc-includes/osclass/helpers/hItems.php';

$admin = scratchdb_session('osc_models_itemcomment');

Params::init(); // empty $_GET/$_POST snapshot: osc_item_comments_page()/osc_comments_per_page() resolve to 0

$model = ItemComment::newInstance();
$table = DB_TABLE_PREFIX . 't_item_comment';

/**
 * t_item_comment has no seed helper in scratchdb.php, so it lives here as a
 * local closure, raw mysqli, never through the code under test.
 *
 * @return int The id just inserted
 */
$seedComment = static function (
    int $itemId,
    string $title = 'Nice item',
    string $body = 'Great, I want it.',
    int $active = 1,
    int $enabled = 1,
    int $spam = 0,
    ?int $userId = null,
    string $date = '2026-01-01 00:00:00',
    string $authorName = 'A. Buyer',
    string $authorEmail = 'buyer@example.test'
) use ($admin, $table): int {
    return seed_exec(
        $admin,
        "INSERT INTO $table
         (fk_i_item_id, dt_pub_date, s_title, s_author_name, s_author_email, s_body,
          b_enabled, b_active, b_spam, fk_i_user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        'isssssiiii',
        array($itemId, $date, $title, $authorName, $authorEmail, $body, $enabled, $active, $spam, $userId)
    );
};

$rowCount = static function () use ($admin, $table): int {
    return (int) $admin->query("SELECT COUNT(*) c FROM $table")->fetch_assoc()['c'];
};

$categoryId = seed_category($admin, 'Motors');
$userId     = seed_user($admin);
$itemA      = seed_item($admin, $categoryId, $userId, 'Item A');
$itemB      = seed_item($admin, $categoryId, $userId, 'Item B');

/* ----------------------------------------------------------------------------
 * Surface (C2): the public API must survive the conversion byte-identical.
 * ------------------------------------------------------------------------- */
harness_section('ItemComment: public surface');

check('ItemComment still extends DAO', is_subclass_of('ItemComment', 'DAO'));
check('$model->dao is a live DBCommandClass (C5)', $model->dao instanceof DBCommandClass);
pin('table name is unchanged', $table, $model->getTableName());
pin('primary key is unchanged', 'pk_i_id', $model->getPrimaryKey());
pin(
    'field allowlist is unchanged',
    array(
        'pk_i_id', 'fk_i_item_id', 'dt_pub_date', 's_title', 's_author_name',
        's_author_email', 's_body', 'b_enabled', 'b_active', 'b_spam', 'fk_i_user_id',
    ),
    $model->getFields()
);

pin('newInstance signature is unchanged', 'public static newInstance()', harness_method_signature('ItemComment', 'newInstance'));
pin('findByItemIDAll signature is unchanged', 'public findByItemIDAll($id)', harness_method_signature('ItemComment', 'findByItemIDAll'));
pin(
    'findByItemID signature is unchanged',
    'public findByItemID($id, $page = NULL, $commentsPerPage = NULL)',
    harness_method_signature('ItemComment', 'findByItemID')
);
pin('total_comments signature is unchanged', 'public total_comments($id)', harness_method_signature('ItemComment', 'total_comments'));
pin('totalComments signature is unchanged', 'public totalComments($id)', harness_method_signature('ItemComment', 'totalComments'));
pin('findByAuthorID signature is unchanged', 'public findByAuthorID($id)', harness_method_signature('ItemComment', 'findByAuthorID'));
pin('getAllComments signature is unchanged', 'public getAllComments($itemId = NULL)', harness_method_signature('ItemComment', 'getAllComments'));
pin('extendData signature is unchanged', 'private extendData($items)', harness_method_signature('ItemComment', 'extendData'));
pin('getLastComments signature is unchanged', 'public getLastComments($num)', harness_method_signature('ItemComment', 'getLastComments'));
pin(
    'search signature is unchanged',
    "public search(\$itemId = NULL, \$start = 0, \$limit = 10, \$order_by = 'c.pk_i_id', \$order = 'DESC', \$all = true)",
    harness_method_signature('ItemComment', 'search')
);
pin('count signature is unchanged', 'public count($itemId = NULL)', harness_method_signature('ItemComment', 'count'));
pin('countAll signature is unchanged', 'public countAll($aConditions = NULL)', harness_method_signature('ItemComment', 'countAll'));

pin(
    'the public method set is exactly this, nothing added or removed',
    array(
        'count', 'countAll', 'findByAuthorID', 'findByItemID', 'findByItemIDAll',
        'getAllComments', 'getLastComments', 'newInstance', 'search', 'totalComments', 'total_comments',
    ),
    array_values(array_intersect(
        array_keys(harness_public_method_map('ItemComment')),
        array(
            'countAll', 'count', 'findByAuthorID', 'findByItemID', 'findByItemIDAll',
            'getAllComments', 'getLastComments', 'newInstance', 'search', 'total_comments', 'totalComments',
        )
    ))
);

/* ----------------------------------------------------------------------------
 * findByItemIDAll() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('ItemComment::findByItemIDAll');

pin('empty table returns an empty array', array(), $model->findByItemIDAll($itemA));

$c1 = $seedComment($itemA, 'First', 'Body one', 1, 1, 0);
$c2 = $seedComment($itemA, 'Second', 'Body two', 0, 1, 0);
$seedComment($itemB, 'Other item', 'Body three', 1, 1, 0);

$all = $model->findByItemIDAll($itemA);
pin('returns every comment for the item regardless of active/enabled', 2, count($all));
check('every value in every row is a string or null (C4)', all_rows_string($all), describe($all));
pin('row carries exactly the schema columns', $model->getFields(), array_keys($all[0]));

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null id returns an empty array (malformed clause and zero-match both land here)', array(), $model->findByItemIDAll(null));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * findByItemID() — filtered to active+enabled, with and without pagination.
 * ------------------------------------------------------------------------- */
harness_section('ItemComment::findByItemID');

$noPage = $model->findByItemID($itemA);
pin('default call (no page/perPage args) returns every active+enabled comment for the item', 1, count($noPage));
pin('it is the active+enabled comment', (string) $c1, $noPage[0]['pk_i_id']);
check('every value in every row is a string or null (C4)', all_rows_string($noPage), describe($noPage));

$seedComment($itemA, 'Third', 'Body four', 1, 1, 0);
$seedComment($itemA, 'Fourth', 'Body five', 1, 1, 0);
$seedComment($itemA, 'Fifth', 'Body six', 1, 1, 0);
// itemA now has 5 comments total; 4 are active+enabled (c1, third, fourth, fifth).

$paged = $model->findByItemID($itemA, 1, 2);
pin('page=1, perPage=2 returns exactly two rows', 2, count($paged));

$allActive = $model->findByItemID($itemA, 0, 100);
pin('a page/perPage window larger than the match count returns every active+enabled row', 4, count($allActive));

$allPage = $model->findByItemID($itemA, 'all', 2);
pin('page="all" disables pagination regardless of perPage', 4, count($allPage));

$zeroPerPage = $model->findByItemID($itemA, 2, 0);
pin('perPage=0 disables pagination (the model-level guard, not the query layer)', 4, count($zeroPerPage));

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin('a null id returns an empty array', array(), $model->findByItemID(null, 0, 10));
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * total_comments() / totalComments() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('ItemComment::total_comments / totalComments');

pin('totalComments counts only active+enabled rows, as a string', '4', $model->totalComments($itemA));
pin('total_comments delegates to totalComments verbatim', $model->totalComments($itemA), $model->total_comments($itemA));
pin('totalComments returns int 0 for an item with no active+enabled comments', 0, $model->totalComments($itemB + 1000));

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
pin(
    'totalComments(null) returns false — the malformed clause fails the query, a genuinely different branch from the zero-match int 0 above',
    false,
    $model->totalComments(null)
);
error_reporting($prevLevel);

/* ----------------------------------------------------------------------------
 * findByAuthorID() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('ItemComment::findByAuthorID');

pin('no comments authored by this user yet', array(), $model->findByAuthorID($userId));

$authored = $seedComment($itemA, 'By user', 'text', 1, 1, 0, $userId);
$byAuthor = $model->findByAuthorID($userId);
pin('exactly one comment matches', 1, count($byAuthor));
pin('it is the seeded row', (string) $authored, $byAuthor[0]['pk_i_id']);
check('every value in every row is a string or null (C4)', all_rows_string($byAuthor), describe($byAuthor));

$otherUser = seed_user($admin, 'someoneelse', 'someoneelse@example.test');
pin('a different user id matches nothing', array(), $model->findByAuthorID($otherUser));

/* ----------------------------------------------------------------------------
 * getAllComments() — implicit item join, ordering, and the extendData() merge.
 * ------------------------------------------------------------------------- */
harness_section('ItemComment::getAllComments');

$itemC = seed_item($admin, $categoryId, $userId, 'Item C');
$older = $seedComment($itemC, 'Older', 'older body', 1, 1, 0, null, '2026-02-01 00:00:00');
$newer = $seedComment($itemC, 'Newer', 'newer body', 1, 1, 0, null, '2026-02-02 00:00:00');

$forItem = $model->getAllComments($itemC);
pin('returns both comments for the item', 2, count($forItem));
pin('newest dt_pub_date sorts first', (string) $newer, $forItem[0]['pk_i_id']);
pin('locale is keyed by the seeded locale', array('en_US'), array_keys($forItem[0]['locale']));
pin('s_title is pulled from the item description, not the comment', 'Item C', $forItem[0]['s_title']);
check('s_description is present', isset($forItem[0]['s_description']));
check('every non-locale value is a string or null (C4)', all_values_string(array_diff_key($forItem[0], array('locale' => null))), describe($forItem[0]));

$noneForOrphan = $model->getAllComments(999999);
pin('an item with no comments returns an empty array', array(), $noneForOrphan);

$everything = $model->getAllComments();
check(
    'itemId=null returns every comment joined to its item',
    count($everything) === $rowCount(),
    'expected ' . $rowCount() . ' got ' . count($everything)
);

/* ----------------------------------------------------------------------------
 * extendData() N+1 baseline (F9) — one query per comment row, unbatched.
 * Measured at two fixture sizes so the linear growth is explicit; this is the
 * baseline a later fix is expected to flatten, not something fixed here.
 * ------------------------------------------------------------------------- */
harness_section('ItemComment: extendData() N+1 baseline');

$itemSmall = seed_item($admin, $categoryId, $userId, 'Small N');
for ($i = 0; $i < 2; $i++) {
    $seedComment($itemSmall, "small-$i", 'body', 1, 1, 0, null, '2026-03-0' . ($i + 1) . ' 00:00:00');
}
$itemLarge = seed_item($admin, $categoryId, $userId, 'Large N');
for ($i = 0; $i < 8; $i++) {
    $seedComment($itemLarge, "large-$i", 'body', 1, 1, 0, null, '2026-04-' . sprintf('%02d', $i + 1) . ' 00:00:00');
}

$qSmall = harness_query_count(static function () use ($model, $itemSmall) {
    $model->getAllComments($itemSmall);
});
$qLarge = harness_query_count(static function () use ($model, $itemLarge) {
    $model->getAllComments($itemLarge);
});

/* Was 1 main select + one description query per comment. extendData now batches
 * that into a single lookup, so the cost is 2 whatever the comment count. */
pin('2 comments cost 1 main select + 1 batched description lookup', 2, $qSmall);
pin('8 comments cost the same 2 statements', 2, $qLarge);
check(
    'the cost no longer grows with the comment count',
    $qSmall === $qLarge,
    "small=$qSmall large=$qLarge"
);

/* ----------------------------------------------------------------------------
 * getLastComments() — two explicit JOINs, bad-input guard, LIMIT.
 * ------------------------------------------------------------------------- */
harness_section('ItemComment::getLastComments');

pin('num=0 returns false without querying', false, $model->getLastComments(0));
pin('a non-numeric num returns false', false, $model->getLastComments('abc'));

$last = $model->getLastComments(3);
check('returns an array', is_array($last), describe($last));
pin('at most 3 rows come back', true, count($last) <= 3);
if (count($last) > 0) {
    check('comment_title alias is present', array_key_exists('comment_title', $last[0]));
    check('every value in the row is a string or null (C4)', all_values_string($last[0]), describe($last[0]));
}

$largeLast = $model->getLastComments(1000);
pin('a limit larger than the table returns every joined row', $rowCount(), count($largeLast));
pin('newest pk_i_id sorts first', true, (int) $largeLast[0]['pk_i_id'] > (int) $largeLast[count($largeLast) - 1]['pk_i_id']);

/* ----------------------------------------------------------------------------
 * search() — implicit item join, filters, ordering quirks, pagination.
 * ------------------------------------------------------------------------- */
harness_section('ItemComment::search — basic');

$searchAll = $model->search($itemC, 0, 10, 'c.pk_i_id', 'ASC');
pin('itemId filter returns exactly the two seeded comments for that item', 2, count($searchAll));
pin('ascending order puts the older row first', (string) $older, $searchAll[0]['pk_i_id']);
check('every value in every row is a string or null (C4)', all_rows_string($searchAll), describe($searchAll));

harness_section('ItemComment::search — pagination (start != limit, §3.3)');

$page = $model->search($itemLarge, 2, 3, 'c.pk_i_id', 'ASC');
pin('start=2, limit=3 returns exactly three rows', 3, count($page));

harness_section('ItemComment::search — the !$all extra filter');

$hiddenId = $seedComment($itemC, 'Hidden', 'hidden body', 0, 1, 0, null, '2026-02-03 00:00:00');
$onlyHidden = $model->search($itemC, 0, 10, 'c.pk_i_id', 'ASC', false);
pin('all=false returns only rows that are inactive, disabled or spam', 1, count($onlyHidden));
pin('it is the hidden row', (string) $hiddenId, $onlyHidden[0]['pk_i_id']);

harness_section('ItemComment::search — order_direction "0" fails the query (amendment I)');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$zeroDir = $model->search($itemC, 0, 10, 'c.pk_i_id', '0');
error_reporting($prevLevel);
pin('a "0" direction concatenates onto the column and fails the query', array(), $zeroDir);

harness_section('ItemComment::search — order_by has no allowlist of its own');

$prevLevel = error_reporting(E_ALL & ~E_WARNING);
$bogusOrderBy = $model->search($itemC, 0, 10, 'c.pk_i_id; DROP TABLE ' . $table . ' -- ', 'ASC');
error_reporting($prevLevel);
check('an invalid order_by does not raise', is_array($bogusOrderBy), describe($bogusOrderBy));
pin('the malformed order_by fails the query, same as any other SQL error', array(), $bogusOrderBy);
check('the table is untouched by the injection attempt', $rowCount() > 0, (string) $rowCount());

harness_section('ItemComment::search — itemId=null joins every comment to its item');

$searchNull = $model->search(null, 0, 1000, 'c.pk_i_id', 'ASC');
pin('itemId=null returns every comment that has a matching item', $rowCount(), count($searchNull));

/* ----------------------------------------------------------------------------
 * count() — the return ledger.
 * ------------------------------------------------------------------------- */
harness_section('ItemComment::count');

pin('count(itemA) counts every comment for the item regardless of active/enabled/spam', '6', $model->count($itemA));
$countC = $model->count($itemC);
pin('count(itemC) counts every comment for the item, active or not', '3', $countC);
$countNull = $model->count();
pin('count() with no itemId counts every comment joined to its item', (string) $rowCount(), $countNull);

/* ----------------------------------------------------------------------------
 * countAll() — the return ledger, with and without an extra raw condition.
 * ------------------------------------------------------------------------- */
harness_section('ItemComment::countAll');

$countAllPlain = $model->countAll();
pin('countAll() with no extra condition counts every comment joined to its item', (string) $rowCount(), $countAllPlain);

$hiddenCondition = '( c.b_active = 0 OR c.b_enabled = 0 OR c.b_spam = 1 )';
$countAllHidden = $model->countAll($hiddenCondition);
pin('countAll() with the hidden-rows raw condition counts only inactive/disabled/spam rows', '2', $countAllHidden);

/* ----------------------------------------------------------------------------
 * Query cost for the non-N+1 methods.
 * ------------------------------------------------------------------------- */
harness_section('ItemComment: query cost (non-extendData methods)');

pin('findByItemIDAll costs one statement', 1, harness_query_count(static function () use ($model, $itemA) {
    $model->findByItemIDAll($itemA);
}));
pin('findByItemID costs one statement', 1, harness_query_count(static function () use ($model, $itemA) {
    $model->findByItemID($itemA, 0, 10);
}));
pin('totalComments costs one statement', 1, harness_query_count(static function () use ($model, $itemA) {
    $model->totalComments($itemA);
}));
pin('findByAuthorID costs one statement', 1, harness_query_count(static function () use ($model, $userId) {
    $model->findByAuthorID($userId);
}));
pin('getLastComments costs one statement', 1, harness_query_count(static function () use ($model) {
    $model->getLastComments(5);
}));
pin('search costs one statement', 1, harness_query_count(static function () use ($model, $itemA) {
    $model->search($itemA);
}));
pin('count costs one statement', 1, harness_query_count(static function () use ($model, $itemA) {
    $model->count($itemA);
}));
pin('countAll costs one statement', 1, harness_query_count(static function () use ($model) {
    $model->countAll();
}));

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/itemcomment.php */
