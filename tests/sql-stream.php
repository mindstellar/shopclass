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
 * SqlStream splits a script read from a stream: quoted values keep their `;` and comment
 * markers, and on the schema file it agrees with SqlScript statement for statement.
 *
 * No database. Usage:  php tests/sql-stream.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');
define('DB_TABLE_PREFIX', 'oc_');
define('OSCLASS_VERSION', '9.9.9');

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

use mindstellar\database\SqlScript;
use mindstellar\database\SqlStream;

/** Statements SqlStream yields for $sql. */
$split = static function (string $sql): array {
    $handle = fopen('php://memory', 'w+b');
    fwrite($handle, $sql);
    rewind($handle);
    $out = iterator_to_array(SqlStream::statements($handle), false);
    fclose($handle);

    return $out;
};

harness_section('Quoted values');

pin('a ; inside a string is text', array("INSERT INTO t VALUES ('a;b')", 'SELECT 1'), $split("INSERT INTO t VALUES ('a;b');\nSELECT 1;"));
pin('escaped and doubled quotes stay inside', array("INSERT INTO t VALUES ('it\\'s;','o''k;', \"x;\\\"y\")"), $split("INSERT INTO t VALUES ('it\\'s;','o''k;', \"x;\\\"y\");"));
pin('comment markers inside a string are text', array("INSERT INTO t VALUES ('-- a', '# b', '/* c */')"), $split("INSERT INTO t VALUES ('-- a', '# b', '/* c */');"));
pin('a value spanning lines', array("INSERT INTO t VALUES ('line1\nline2;\nline3')"), $split("INSERT INTO t VALUES ('line1\nline2;\nline3');"));
pin('a backtick identifier holding a ;', array('SELECT `a;b` FROM t'), $split('SELECT `a;b` FROM t;'));

harness_section('Comments, tokens and delimiters');

pin('comments are dropped', array("SELECT 1", "SELECT \n 2"), $split("/* head */\n-- note\nSELECT 1; # tail\n# whole line\nSELECT -- inline\n 2;"));
pin('5--3 is not a comment', array('SELECT 5--3'), $split('SELECT 5--3;'));
pin('schema tokens are expanded', array('CREATE TABLE oc_t_x (v VARCHAR(9) DEFAULT \'9.9.9\')'), $split("CREATE TABLE /*TABLE_PREFIX*/t_x (v VARCHAR(9) DEFAULT '/*OSCLASS_VERSION*/');"));
pin('DELIMITER changes the delimiter', array('CREATE TRIGGER x BEFORE INSERT ON t FOR EACH ROW BEGIN SET @a = 1; END', 'SELECT 1'), $split("DELIMITER //\nCREATE TRIGGER x BEFORE INSERT ON t FOR EACH ROW BEGIN SET @a = 1; END//\nDELIMITER ;\nSELECT 1;"));
pin('the last statement needs no delimiter', array('SELECT 1', 'SELECT 2'), $split("SELECT 1;\r\nSELECT 2\r\n"));
pin('empty pieces and a lone 0 are skipped', array('SELECT 1'), $split(";;\n0;\nSELECT 1;\n\n"));

harness_section('Agrees with SqlScript on the schema file');

$struct = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/struct.sql');
pin('struct.sql splits the same way', SqlScript::statements($struct), $split($struct));

exit(harness_result());

/* file end: ./tests/sql-stream.php */
