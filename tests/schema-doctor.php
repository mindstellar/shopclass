<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * SchemaDoctor reports where a live database differs from struct.sql.
 *
 * Two halves matter equally. It has to see the drift the upgrade's repair pass is blind to —
 * nullability, a column core stopped declaring, an index whose columns are in the wrong order.
 * And it has to stay quiet about everything else: a tool whose entire output is "this looks
 * wrong" is worthless the moment it cries wolf, and the shapes below all look different while
 * meaning the same thing.
 *
 * Needs a database. Usage:
 *   DRIFT_DB_HOST=127.0.0.1 DRIFT_DB_USER=root DRIFT_DB_PASS=root php tests/schema-doctor.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');
define('DB_TABLE_PREFIX', 'oc_');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\database\Connection;
use mindstellar\database\SchemaDoctor;

$host = getenv('DRIFT_DB_HOST') ?: '127.0.0.1';
$user = getenv('DRIFT_DB_USER') ?: 'root';
$pass = getenv('DRIFT_DB_PASS') ?: 'root';
$port = (int) (getenv('DRIFT_DB_PORT') ?: 3306);
$db   = 'shopclass_doctor_' . getmypid();

$root = @new mysqli($host, $user, $pass, '', $port);
if ($root->connect_errno) {
    fwrite(STDERR, "SKIP: no database ({$root->connect_error})\n");
    exit(0);
}
$root->query('DROP DATABASE IF EXISTS `' . $db . '`');
$root->query('CREATE DATABASE `' . $db . '`');
$root->select_db($db);

/** Load struct.sql, so the fixture starts exactly as a fresh install does. */
$sql = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/installer/struct.sql');
$sql = str_replace('/*TABLE_PREFIX*/', DB_TABLE_PREFIX, $sql);
foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $statement) {
    if ($statement === '') {
        continue;
    }
    $root->query($statement);
}

$conn   = new Connection($root);
$doctor = static function () use ($conn): array {
    return (new SchemaDoctor($conn))->diagnose();
};

/** Findings of one kind, as "table.name". */
$of = static function (array $found, string $kind): array {
    $out = array();
    foreach ($found as $f) {
        if ($f['kind'] === $kind) {
            $out[] = $f['table'] . '.' . $f['name'];
        }
    }
    sort($out);

    return $out;
};

harness_section('A fresh install is silent');

pin('a database built straight from struct.sql reports nothing', array(), $doctor());

harness_section('The drift the upgrade cannot see');

$root->query('ALTER TABLE oc_t_user MODIFY s_email VARCHAR(100) NULL');
pin(
    'a column that should be NOT NULL but accepts NULL is reported',
    array('oc_t_user.s_email'),
    $of($doctor(), SchemaDoctor::NULLABILITY)
);

$root->query("ALTER TABLE oc_t_user ADD COLUMN i_permissions VARCHAR(2) NULL DEFAULT '0'");
pin(
    'a column core no longer declares is reported',
    array('oc_t_user.i_permissions'),
    $of($doctor(), SchemaDoctor::EXTRA_COLUMN)
);

$root->query('ALTER TABLE oc_t_item DROP COLUMN s_contact_email');
pin(
    'a column core declares that is not there is reported',
    array('oc_t_item.s_contact_email'),
    $of($doctor(), SchemaDoctor::MISSING_COLUMN)
);

// Dropping the column above took its index with it, which is reported too -- correctly.
$root->query('DROP INDEX idx_s_slug ON oc_t_country');
pin(
    'an index core declares that is not there is reported',
    array('oc_t_country.idx_s_slug', 'oc_t_item.idx_s_contact_email'),
    $of($doctor(), SchemaDoctor::MISSING_INDEX)
);

$root->query('CREATE INDEX idx_doctor_spare ON oc_t_country (s_name)');
pin(
    'an index core does not declare is reported',
    array('oc_t_country.idx_doctor_spare'),
    $of($doctor(), SchemaDoctor::EXTRA_INDEX)
);

harness_section('...and what it must stay quiet about');

// Every one of these looks different and means the same thing. Each was a real false
// positive before it was handled.
$before = count($doctor());

// A PRIMARY KEY can carry the guarantee core declares as a named UNIQUE KEY. Old installs
// have exactly this on t_preference.
$root->query('ALTER TABLE oc_t_preference DROP INDEX uk_preference_section_name');
$root->query('ALTER TABLE oc_t_preference ADD PRIMARY KEY (s_section, s_name)');
pin(
    'a declared unique key satisfied by the primary key is not reported',
    $before,
    count($doctor())
);

check(
    'and it is not then reported as a spare index either',
    $of($doctor(), SchemaDoctor::EXTRA_INDEX) === array('oc_t_country.idx_doctor_spare'),
    implode(' | ', $of($doctor(), SchemaDoctor::EXTRA_INDEX))
);

// An integer's display width is decoration the server adds or drops by version.
$root->query('ALTER TABLE oc_t_widget MODIFY i_order INT(11) NOT NULL DEFAULT 0');
pin('int(11) against a declared int is not a difference', $before, count($doctor()));

// The server spells an enum without the spaces struct.sql writes for readability.
check(
    'enum spacing is not a difference',
    $of($doctor(), SchemaDoctor::COLUMN_TYPE) === array(),
    implode(' | ', $of($doctor(), SchemaDoctor::COLUMN_TYPE))
);

harness_section('A real type change is still reported');

$root->query('ALTER TABLE oc_t_widget MODIFY i_order BIGINT NOT NULL DEFAULT 0');
pin(
    'int widened to bigint is a difference',
    array('oc_t_widget.i_order'),
    $of($doctor(), SchemaDoctor::COLUMN_TYPE)
);

$root->query('DROP DATABASE `' . $db . '`');

exit(harness_result());
