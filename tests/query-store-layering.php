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
 * The data-access rule: a …Query only reads, and reads through stores or SQL, not the legacy
 * models; a …Store does not lean on a …Query. Each exception is listed with its reason.
 *
 * DB-free.  Usage: php tests/query-store-layering.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';

/** A write: an execute, an insert, an update or a delete. */
const QUERY_WRITE = '/\bDb::(execute|insertGetId)\s*\(|->(insert|insertGetId|update|delete|upsert|replace|truncate|execute|query)\s*\(/';

/** A store naming a query other than RowHashQuery, the shared row-version reader. */
const STORE_USES_QUERY = '/(?:\bnew\s+|\b)\\\\?(?:\w+\\\\)*(?!RowHashQuery\b)\w*Query(?:::|\s*\()/';

/** Lines allowed to break a rule, as "path: the code on the line" => why. */
const LAYERING_ALLOWED = array(
    'oc-includes/osclass/classes/user/UserQuery.php: $user = \User::getInstance()->findByPrimaryKey($id);'
        => 'the legacy User row, cached by the model, is the shape its callers read',
);

$legacy = array();
foreach (glob(ABS_PATH . 'oc-includes/osclass/classes/model/*.php') ?: array() as $file) {
    $source = (string) file_get_contents($file);
    // The namespaced classes beside them are the new models, not the legacy ones.
    if (preg_match('/^namespace /m', $source) !== 1 && preg_match('/^(?:(?:final|abstract|readonly) )*class (\w+)/m', $source, $m) === 1) {
        $legacy[] = $m[1];
    }
}
$legacyCall = '/\\\\?\b(' . implode('|', $legacy) . ')::(getInstance|newInstance)\s*\(|\bnew\s+\\\\?(' . implode('|', $legacy) . ')\s*\(/';

harness_section('The patterns');
check('the legacy models are found', in_array('User', $legacy, true) && in_array('Item', $legacy, true), implode(', ', $legacy));
check('a write is caught', preg_match(QUERY_WRITE, "Db::execute('DELETE FROM t');") === 1 && preg_match(QUERY_WRITE, '$this->table()->update($row);') === 1);
check('a read is not', preg_match(QUERY_WRITE, '$this->table()->where("a", 1)->get();') === 0);
check('a legacy model call is caught', preg_match($legacyCall, '\Item::getInstance()->findByPrimaryKey($id)') === 1 && preg_match($legacyCall, '$u = new \User();') === 1);
check('the new namespaced models are not counted as legacy', !in_array('KeyValue', $legacy, true) && !in_array('Resource', $legacy, true));
check('other writes are caught', preg_match(QUERY_WRITE, 'Connection::getInstance()->execute($sql);') === 1 && preg_match(QUERY_WRITE, '$this->table()->replace($row);') === 1);
check('a store naming a query in any form is caught, RowHashQuery aside', preg_match(STORE_USES_QUERY, '$q = new \mindstellar\listing\ListingQuery();') === 1
    && preg_match(STORE_USES_QUERY, 'ListingQuery::find(1);') === 1 && preg_match(STORE_USES_QUERY, 'RowHashQuery::keyedHashes($t, $k, true);') === 0);

$found = array();
foreach (harness_class_files('oc-includes/osclass/classes', static fn (string $path): bool => preg_match('/(Query|Store)\.php$/', $path) === 1) as $path => $source) {
    $kind = str_ends_with($path, 'Query.php') ? 'query' : 'store';
    foreach (explode("\n", harness_code_only($source)) as $line) {
        $broken = $kind === 'query'
            ? preg_match(QUERY_WRITE, $line) === 1 || preg_match($legacyCall, $line) === 1
            : preg_match(STORE_USES_QUERY, $line) === 1;
        if ($broken && !isset(LAYERING_ALLOWED[$path . ': ' . trim($line)])) {
            $found[] = $path . ': ' . trim($line);
        }
    }
}

harness_section('Queries and stores');
pin('no query writes or calls a legacy model, and no store leans on a query', array(), $found);

exit(harness_result());
