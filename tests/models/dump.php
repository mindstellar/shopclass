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
 * Characterization pins for the Dump model (database backup / export).
 *
 * Written against the legacy implementation and required to pass UNCHANGED once
 * showTables()/table_structure()/table_data() move off the legacy DBCommandClass
 * wrapper.
 *
 * Dump is the §3.5 "dynamic table identifier" model: every query it runs targets
 * a table whose NAME is discovered at runtime rather than being a compile-time
 * literal.
 *
 *  - showTables()      runs `SHOW TABLES` (no identifier at all) and returns the
 *                      row list. Legacy returned each row as a one-key assoc
 *                      array keyed `Tables_in_<db>`, all values strings; the
 *                      empty/failure branch returns array().
 *  - table_structure() runs `SHOW CREATE TABLE <ident>` and writes the CREATE
 *                      statement (rewritten to CREATE TABLE IF NOT EXISTS) to a
 *                      file. Returns false only when the path is unwritable;
 *                      otherwise true, even when the query finds nothing (a
 *                      failed/absent table writes just the header comment).
 *  - table_data()      runs `SELECT * FROM <ident>` and writes INSERT statements
 *                      for every row. It drives per-column quoting off the mysqli
 *                      RESULT-SET FIELD METADATA (fetch_fields()->type), which the
 *                      parameterized Connection layer does not expose, so its read
 *                      stays on the metadata-bearing legacy path; only the dynamic
 *                      identifier is hardened. t_category rows are re-ordered so a
 *                      parent always precedes its children.
 *
 * The value-quoting quirk is pinned exactly because table_data() emits FILE TEXT,
 * not a live query, so its bytes are the contract: DBCommandClass::escape()
 * returns an is_numeric() value BARE (unquoted) unless it is longer than one char
 * and starts with '0'. A VARCHAR holding "123" therefore dumps unquoted while
 * "0123" dumps quoted. This is preserved verbatim (unlike the query-comparison
 * coercion that other models deliberately drop) because changing it would change
 * the produced backup.
 *
 * Where the identifier is caller-supplied and cannot be validated it is treated
 * exactly like a failed query: table_structure() writes only its header and still
 * returns true, table_data() writes only its trailing newline and still returns
 * true — the same observable shape the legacy failed-query branch produced.
 *
 * Usage:  php tests/models/dump.php          (standalone, own scratch database)
 *         php tests/run-models.php dump      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_dump');

$model = Dump::newInstance();

/**
 * Create a fresh, writable, EMPTY temp file and return its path. table_structure
 * and table_data both APPEND, and both require an already-writable path (the real
 * caller pre-creates the file before dumping into it), so every content pin uses
 * a clean file it can read back afterwards.
 *
 * @return string
 */
$freshFile = static function (): string {
    $path = tempnam(sys_get_temp_dir(), 'dumptest_');
    file_put_contents($path, '');

    return $path;
};

/* ----------------------------------------------------------------------------
 * Surface (C2): the public API must survive the conversion byte-identical.
 * Public + protected only (amendment R). Dump declares no protected methods.
 * ------------------------------------------------------------------------- */
harness_section('Dump: public surface');

pin(
    'newInstance signature is unchanged',
    'public static newInstance()',
    harness_method_signature('Dump', 'newInstance')
);
pin(
    'showTables signature is unchanged',
    'public showTables()',
    harness_method_signature('Dump', 'showTables')
);
pin(
    'table_structure signature is unchanged',
    'public table_structure($path, $table)',
    harness_method_signature('Dump', 'table_structure')
);
pin(
    'table_data signature is unchanged',
    'public table_data($path, $table)',
    harness_method_signature('Dump', 'table_data')
);
pin(
    'Dump declares exactly newInstance/showTables/table_structure/table_data (plus private helpers)',
    array('newInstance', 'showTables', 'table_data', 'table_structure'),
    (static function (): array {
        // Only methods DECLARED on Dump itself; the inherited DAO base surface is
        // pinned separately by tests/dao-contract.php and is out of scope here.
        $names = array();
        foreach ((new ReflectionClass('Dump'))->getMethods() as $m) {
            if ($m->getDeclaringClass()->getName() === 'Dump' && !$m->isPrivate()) {
                $names[] = $m->getName();
            }
        }
        sort($names);

        return $names;
    })()
);

pin('newInstance returns the singleton', true, Dump::newInstance() === $model);
pin('model exposes a live DBCommandClass dao (C5)', true, $model->dao instanceof DBCommandClass);

/* ----------------------------------------------------------------------------
 * showTables(): SHOW TABLES over the connection's own database.
 * ------------------------------------------------------------------------- */
harness_section('Dump: showTables');

$tables = $model->showTables();

check('showTables returns an array', is_array($tables));
check('showTables is non-empty on a populated schema', count($tables) > 0);
check('every showTables row is a one-key assoc array', (static function ($rows): bool {
    foreach ($rows as $row) {
        if (!is_array($row) || count($row) !== 1) {
            return false;
        }
    }

    return true;
})($tables));
check('showTables values are all strings (C4)', all_rows_string($tables));

$tableNames = array_map(static function ($row) {
    return current($row);
}, $tables);

check(
    'showTables includes the widget table',
    in_array(DB_TABLE_PREFIX . 't_widget', $tableNames, true)
);
check(
    'showTables includes the category table',
    in_array(DB_TABLE_PREFIX . 't_category', $tableNames, true)
);

pin(
    'showTables costs exactly one query',
    1,
    harness_query_count(static function () use ($model) {
        $model->showTables();
    })
);

/* ----------------------------------------------------------------------------
 * table_structure(): SHOW CREATE TABLE <ident> -> file.
 * ------------------------------------------------------------------------- */
harness_section('Dump: table_structure');

pin(
    'table_structure returns false for an unwritable path',
    false,
    $model->table_structure('/no_such_dir_xyz/dump.sql', DB_TABLE_PREFIX . 't_widget')
);

$structFile = $freshFile();
$structOk   = $model->table_structure($structFile, DB_TABLE_PREFIX . 't_widget');
$structBody = file_get_contents($structFile);
@unlink($structFile);

pin('table_structure returns true on success', true, $structOk);
check(
    'table_structure writes the header comment for the table',
    strpos($structBody, '/* Table structure for table `' . DB_TABLE_PREFIX . 't_widget` */') !== false
);
check(
    'table_structure rewrites CREATE TABLE to CREATE TABLE IF NOT EXISTS',
    strpos($structBody, 'CREATE TABLE IF NOT EXISTS `' . DB_TABLE_PREFIX . 't_widget`') !== false
);

$structMissFile = $freshFile();
$structMissOk   = $model->table_structure($structMissFile, 'oc_no_such_table_zzz');
$structMissBody = file_get_contents($structMissFile);
@unlink($structMissFile);

pin('table_structure returns true even when the table does not exist', true, $structMissOk);
check(
    'table_structure still writes the header for a missing table',
    strpos($structMissBody, '/* Table structure for table `oc_no_such_table_zzz` */') !== false
);
check(
    'table_structure writes no CREATE statement for a missing table',
    strpos($structMissBody, 'CREATE TABLE IF NOT EXISTS') === false
);

pin(
    'table_structure costs exactly one query',
    1,
    (static function () use ($model, $freshFile): int {
        $f = $freshFile();
        $n = harness_query_count(static function () use ($model, $f) {
            $model->table_structure($f, DB_TABLE_PREFIX . 't_widget');
        });
        @unlink($f);

        return $n;
    })()
);

/* ----------------------------------------------------------------------------
 * table_data(): SELECT * FROM <ident> -> INSERT statements, with the exact
 * value-quoting quirk pinned byte-for-byte. A purpose-built table gives full
 * control over column types and values.
 * ------------------------------------------------------------------------- */
harness_section('Dump: table_data value formatting');

$admin->query('DROP TABLE IF EXISTS `oc_dumptest`');
$admin->query(
    'CREATE TABLE `oc_dumptest` (
        pk_i_id INT NOT NULL,
        s_name VARCHAR(50) NULL,
        d_when DATE NULL,
        s_num VARCHAR(20) NULL
    )'
);

pin(
    'table_data returns false for an unwritable path',
    false,
    $model->table_data('/no_such_dir_xyz/dump.sql', 'oc_dumptest')
);

// Empty table: no dumping comment, just the trailing newline the method always
// appends.
$emptyFile = $freshFile();
$emptyOk   = $model->table_data($emptyFile, 'oc_dumptest');
$emptyBody = file_get_contents($emptyFile);
@unlink($emptyFile);

pin('table_data returns true for an empty table', true, $emptyOk);
pin('table_data writes only the trailing newline for an empty table', "\n", $emptyBody);

// Rows chosen to exercise every _quotes() branch: a numeric column (bare), a
// string column (quoted, apostrophe escaped), a date column (quoted), a numeric
// string that escape() passes through bare ("123") and one it quotes ("0123").
$admin->query(
    "INSERT INTO `oc_dumptest` (pk_i_id, s_name, d_when, s_num) VALUES
        (1, 'Alice',   '2026-01-01', '123'),
        (2, NULL,      NULL,         '0123'),
        (3, 'O''Brien','2026-02-02', '9')"
);

$dataFile = $freshFile();
$dataOk   = $model->table_data($dataFile, 'oc_dumptest');
$dataBody = file_get_contents($dataFile);
@unlink($dataFile);

$expectedData = "/* dumping data for table `oc_dumptest` */\n"
    . "insert into `oc_dumptest` values\n"
    . "(1,'Alice','2026-01-01',123),\n"
    . "(2,null,null,'0123'),\n"
    . "(3,'O\\'Brien','2026-02-02',9);\n"
    . "\n";

pin('table_data returns true on success', true, $dataOk);
pin('table_data emits INSERT statements with the exact value quoting', $expectedData, $dataBody);

pin(
    'table_data costs exactly one query',
    1,
    (static function () use ($model, $freshFile): int {
        $f = $freshFile();
        $n = harness_query_count(static function () use ($model, $f) {
            $model->table_data($f, 'oc_dumptest');
        });
        @unlink($f);

        return $n;
    })()
);

$admin->query('DROP TABLE IF EXISTS `oc_dumptest`');

/* ----------------------------------------------------------------------------
 * table_data(): the t_category special case re-orders rows so every parent
 * precedes its children, regardless of the natural SELECT order.
 * ------------------------------------------------------------------------- */
harness_section('Dump: table_data t_category ordering');

$catTable = DB_TABLE_PREFIX . 't_category';
$admin->query('TRUNCATE TABLE `' . $catTable . '`');

// Child gets the LOWER primary key, so a bare SELECT * would list it first; the
// dump must still put the parent first. All non-key columns take their table
// defaults (i_expiration_days 0, i_position 0, b_enabled 1, b_price_enabled 1,
// s_icon NULL) so the emitted bytes are deterministic.
$admin->query(
    'INSERT INTO `' . $catTable . '`
        (pk_i_id, fk_i_parent_id, i_expiration_days, i_position, b_enabled, b_price_enabled, s_icon)
     VALUES
        (1, 2,    0, 0, 1, 1, NULL),
        (2, NULL, 0, 0, 1, 1, NULL)'
);

$catFile = $freshFile();
$catOk   = $model->table_data($catFile, $catTable);
$catBody = file_get_contents($catFile);
@unlink($catFile);

$expectedCat = '/* dumping data for table `' . $catTable . "` */\n"
    . 'insert into `' . $catTable . "` values\n"
    . "(2,null,0,0,1,1,null),\n"
    . "(1,2,0,0,1,1,null);\n"
    . "\n";

pin('table_data returns true for t_category', true, $catOk);
pin('table_data orders parent rows before their children', $expectedCat, $catBody);

$admin->query('TRUNCATE TABLE `' . $catTable . '`');

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}

/* file end: ./tests/models/dump.php */
