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
 * Pins PersonalData::map() against the schema.
 *
 * The map is the one place recording what an access request hands over and what an
 * erasure removes. Its whole value is being complete: a table holding something about a
 * person that nobody listed is data no request can reach, and nothing about adding such a
 * table would otherwise make that obvious. So this reads struct.sql, finds every table
 * tied to a person, and fails if the map has not been told about it.
 *
 * Adding a table with a user column and no map entry is meant to fail here. The fix is an
 * entry saying what happens to it and why — including "retained", which is a legitimate
 * answer as long as it is a stated one.
 *
 * Usage: php tests/personal-data-map.php
 */

define('ABS_PATH', __DIR__ . '/../');
define('DB_TABLE_PREFIX', 'oc_');

require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

// PersonalData::map() is a pure literal, so it can be read without booting the app.
$src = file_get_contents(__DIR__ . '/../oc-includes/osclass/classes/privacy/PersonalData.php');
preg_match_all("/'(t_[a-z_]+)' => array\(/", $src, $m);
$mapped = array_values(array_unique($m[1]));

$schema = file_get_contents(__DIR__ . '/../oc-includes/osclass/installer/struct.sql');
preg_match_all('#CREATE TABLE /\*TABLE_PREFIX\*/(\w+)\s*\((.*?)\n\)\s*ENGINE#s', $schema, $tables, PREG_SET_ORDER);

// A table is "about a person" if it names an account or carries something that identifies
// one directly. Kept deliberately broad: a false positive costs one map entry saying
// "retained, and why", a false negative costs a hole nobody sees.
$personal = array();
foreach ($tables as $t) {
    if (preg_match('/fk_i_user_id|s_email|s_access_ip|\bs_ip\b/i', $t[2])) {
        $personal[] = $t[1];
    }
}

harness_section('every table tied to a person is accounted for');
foreach ($personal as $table) {
    pin($table . ' appears in the map', true, in_array($table, $mapped, true));
}

harness_section('the map does not describe tables that no longer exist');
$schemaTables = array_column($tables, 1);
foreach ($mapped as $table) {
    pin($table . ' still exists in struct.sql', true, in_array($table, $schemaTables, true));
}

harness_section('every entry states what erasure does and why');
preg_match_all(
    "/'(t_[a-z_]+)' => array\(\s*'user_key' => (null|'[a-z_]+'),\s*'export'   => (true|false),\s*'erase'    => self::(ERASE_[A-Z]+),/",
    $src,
    $entries,
    PREG_SET_ORDER
);
pin('every mapped table parsed an entry', count($mapped), count($entries));

foreach ($entries as $e) {
    // An exported table has to say which column ties a row to the account, or the export
    // has no way to select the person's own rows.
    if ($e[3] === 'true') {
        pin($e[1] . ': exported, so it names a user column', true, $e[2] !== 'null');
    }
}

harness_section('a reason is recorded for every table');
foreach ($mapped as $table) {
    $ok = (bool)preg_match("/'" . $table . "' => array\(.*?'why'      => '/s", $src);
    pin($table . ' has a why', true, $ok);
}

exit(harness_result());
