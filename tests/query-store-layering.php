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
const QUERY_WRITE = '/\bDb::(execute|insertGetId)\(|->(insert|insertGetId|update|delete|upsert)\(/';

/** Lines allowed to break a rule, as "path: the code on the line" => why. */
const LAYERING_ALLOWED = array(
    'oc-includes/osclass/classes/user/UserQuery.php: $user = \User::getInstance()->findByPrimaryKey($id);'
        => 'the legacy User row, cached by the model, is the shape its callers read',
);

$legacy = array();
foreach (glob(ABS_PATH . 'oc-includes/osclass/classes/model/*.php') ?: array() as $file) {
    if (preg_match('/^class (\w+)/m', (string) file_get_contents($file), $m) === 1) {
        $legacy[] = $m[1];
    }
}
$legacyCall = '/\\\\?\b(' . implode('|', $legacy) . ')::(getInstance|newInstance)\(/';

harness_section('The patterns');
check('the legacy models are found', in_array('User', $legacy, true) && in_array('Item', $legacy, true), implode(', ', $legacy));
check('a write is caught', preg_match(QUERY_WRITE, "Db::execute('DELETE FROM t');") === 1 && preg_match(QUERY_WRITE, '$this->table()->update($row);') === 1);
check('a read is not', preg_match(QUERY_WRITE, '$this->table()->where("a", 1)->get();') === 0);
check('a legacy model call is caught', preg_match($legacyCall, '\Item::getInstance()->findByPrimaryKey($id)') === 1);

$found = array();
$it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ABS_PATH . 'oc-includes/osclass/classes', FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $name = $file->getFilename();
    $kind = preg_match('/Query\.php$/', $name) ? 'query' : (preg_match('/Store\.php$/', $name) ? 'store' : '');
    if ($kind === '') {
        continue;
    }
    $path = substr($file->getPathname(), strlen(ABS_PATH));
    foreach (explode("\n", harness_code_only((string) file_get_contents($file->getPathname()))) as $line) {
        $broken = $kind === 'query'
            ? preg_match(QUERY_WRITE, $line) === 1 || preg_match($legacyCall, $line) === 1
            : preg_match('/\b(?!RowHash)\w+Query::|new \w+Query\(/', $line) === 1;
        if ($broken && !isset(LAYERING_ALLOWED[$path . ': ' . trim($line)])) {
            $found[] = $path . ': ' . trim($line);
        }
    }
}

harness_section('Queries and stores');
pin('no query writes or calls a legacy model, and no store leans on a query', array(), $found);

exit(harness_result());
