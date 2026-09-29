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
 * What a restore accepts: a backup from a newer Shopclass is refused, an older one runs
 * the updates after it, a bare .sql file is taken with a note, and an old dump whose
 * tables carry another prefix is refused while a dump with the prefix token is not.
 *
 * No database. Usage:  php tests/backup-manifest.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');
define('OSCLASS_VERSION', '6.4.0');
define('DB_TABLE_PREFIX', 'sc_');

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

use mindstellar\backup\Manifest;

$from = static function (string $version): array {
    return array('shopclass_version' => $version) + Manifest::build(array('what' => 'everything'));
};
$stream = static function (string $sql) {
    $h = fopen('php://memory', 'w+b');
    fwrite($h, $sql);
    rewind($h);

    return $h;
};

harness_section('Version');

$newer = Manifest::check($from('6.5.0'), '6.4.0', 'sc_');
pin('a newer backup is refused', false, $newer['ok']);
pin('...saying to update first', 'This backup comes from Shopclass 6.5.0. This site runs 6.4.0. Update Shopclass first, then restore.', $newer['reason']);
pin('a newer pre-release is refused too', false, Manifest::check($from('6.4.1.beta1'), '6.4.0', 'sc_')['ok']);
$older = Manifest::check($from('6.3.2'), '6.4.0', 'sc_');
pin('an older backup is allowed', true, $older['ok']);
pin('...and the updates run after it', true, $older['migrate']);
pin('...which the confirm says', 'From Shopclass 6.3.2. Updates run after the restore.', $older['note']);
$same = Manifest::check($from('6.4.0'), '6.4.0', 'sc_');
pin('the same version needs no updates', array(true, false, ''), array($same['ok'], $same['migrate'], $same['note']));
pin('a manifest backup restores onto any prefix', true, Manifest::check($from('6.4.0') + array('table_prefix' => 'oc_'), '6.4.0', 'sc_')['ok']);

harness_section('A bare .sql file');

$bare = Manifest::check(null, '6.4.0', 'sc_');
pin('a file with no manifest is allowed', true, $bare['ok']);
pin('...with the updates run just in case', true, $bare['migrate']);
pin('...and a note saying so', 'This file has no version information. Updates run after it, just in case.', $bare['note']);

$legacy = "/* OSCLASS MYSQL Autobackup */\n/* Table structure for table `oc_t_locale` */\n"
    . "CREATE TABLE IF NOT EXISTS `oc_t_locale` (\n  `pk_c_code` char(5) NOT NULL\n);\n"
    . "insert into `oc_t_locale` values\n('en_US');\n";
$prefix = Manifest::sqlPrefix($stream($legacy));
pin('a legacy dump names its prefix', 'oc_', $prefix);
$mismatch = Manifest::check(null, '6.4.0', 'sc_', $prefix);
pin('a legacy dump with another prefix is refused', false, $mismatch['ok']);
pin('...saying why', 'This backup uses tables named oc_…. This site uses sc_…. It cannot be restored here.', $mismatch['reason']);
pin('a legacy dump with this prefix is allowed', true, Manifest::check(null, '6.4.0', 'oc_', $prefix)['ok']);

$token = "/* Table structure for table `oc_t_item` */\nCREATE TABLE IF NOT EXISTS `/*TABLE_PREFIX*/t_item` (\n  `pk_i_id` int\n);\n";
pin('a dump with the prefix token names no prefix', null, Manifest::sqlPrefix($stream($token)));
pin('...so it is allowed on any site', true, Manifest::check(null, '6.4.0', 'sc_', Manifest::sqlPrefix($stream($token)))['ok']);
pin('a plugin table is not read as the prefix', 'ab_', Manifest::sqlPrefix($stream(
    "CREATE TABLE IF NOT EXISTS `ab_t_plugin_things` (x int);\nCREATE TABLE IF NOT EXISTS `ab_t_preference` (x int);\n"
)));
pin('a prefix ending in t_ is read whole', 'at_', Manifest::sqlPrefix($stream("insert into `at_t_item` values\n(1);\n")));
pin('a file naming no core table says nothing', null, Manifest::sqlPrefix($stream("INSERT INTO `x` VALUES (1);\n")));

harness_section('Reading a manifest');

$built = Manifest::build(array('what' => 'database', 'kind' => 'safety', 'contents' => array('database' => array('tables' => 3))));
pin('a built manifest parses back', $built, Manifest::parse((string) json_encode($built)));
pin('it records this site', array('6.4.0', 'sc_', 'safety'), array($built['shopclass_version'], $built['table_prefix'], $built['kind']));
check('it holds no secret-looking key', !preg_match('/pass|secret|key|token/i', implode(' ', array_keys($built))));
pin('junk is not a manifest', null, Manifest::parse('{"hello":1}'));
pin('an unknown "what" is not a manifest', null, Manifest::parse((string) json_encode(array('what' => 'photos') + $built)));
pin('broken JSON is not a manifest', null, Manifest::parse('{'));

exit(harness_result());

/* file end: ./tests/backup-manifest.php */
