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
 * Migrations 0054-0055: orphans are cleared before a foreign key is added, legacy guest
 * alerts become NULL, empty and duplicate usernames are renamed before the unique key, and
 * each migration is a no-op on a second run and safe when runs overlap. The schema is put
 * back to struct.sql at the end.
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\database\Connection;

$admin = scratchdb_session('osc_models_schemafks');
$conn  = Connection::instance();
$p     = DB_TABLE_PREFIX;

$migrate = static function (string $file) use ($conn) {
    (require ABS_PATH . 'oc-includes/osclass/installer/migrations/' . $file)->up($conn);
};
$count = static function (string $sql) use ($admin): int {
    return (int)$admin->query($sql)->fetch_row()[0];
};
$column = static function (string $sql) use ($admin): array {
    $out = array();
    $res = $admin->query($sql);
    while ($row = $res->fetch_row()) {
        $out[] = $row[0];
    }

    return $out;
};
// [child, column, parent, ON DELETE rule], as struct.sql declares them.
$keys = array(
    array('t_item_description', 'fk_i_item_id', 't_item', 'CASCADE'),
    array('t_item_description', 'fk_c_locale_code', 't_locale', 'CASCADE'),
    array('t_meta_fields', 'fk_i_group_id', 't_meta_group', 'SET NULL'),
    array('t_form_submission', 'fk_i_group_id', 't_meta_group', 'CASCADE'),
    array('t_alerts', 'fk_i_user_id', 't_user', 'CASCADE'),
);
// "child.column -> parent RULE" for every key found, one entry per constraint.
$fkState = static function () use ($admin, $p, $keys): array {
    $out = array();
    foreach ($keys as [$child, $col, $parent]) {
        $res = $admin->query(
            "SELECT rc.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k
               JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                 ON rc.CONSTRAINT_SCHEMA = k.TABLE_SCHEMA AND rc.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND rc.TABLE_NAME = k.TABLE_NAME
              WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = '$p$child' AND k.COLUMN_NAME = '$col'
                AND k.REFERENCED_TABLE_NAME = '$p$parent'"
        );
        while ($row = $res->fetch_row()) {
            $out[] = "$child.$col -> $parent {$row[0]}";
        }
    }

    return $out;
};
$wanted = array_map(static fn ($k) => "{$k[0]}.{$k[1]} -> {$k[2]} {$k[3]}", $keys);
$dropKeys = static function () use ($admin, $p, $keys): void {
    foreach ($keys as [$child, $col, $parent]) {
        $res = $admin->query(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = '$p$child' AND COLUMN_NAME = '$col' AND REFERENCED_TABLE_NAME = '$p$parent'"
        );
        while ($row = $res->fetch_row()) {
            $admin->query("ALTER TABLE $p$child DROP FOREIGN KEY `{$row[0]}`");
        }
    }
};
$colType = static function (string $table, string $col) use ($admin, $p): string {
    $row = $admin->query(
        "SELECT CONCAT(COLUMN_TYPE, ' ', IFNULL(COLLATION_NAME, '-'), ' ', IS_NULLABLE) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$p$table' AND COLUMN_NAME = '$col'"
    )->fetch_row();

    return preg_replace('/^int\(\d+\)/', 'int', (string)$row[0]);
};
$orphans = static function () use ($count, $p): array {
    return array(
        $count("SELECT COUNT(*) FROM {$p}t_item_description c LEFT JOIN {$p}t_item x ON c.fk_i_item_id = x.pk_i_id WHERE x.pk_i_id IS NULL"),
        $count("SELECT COUNT(*) FROM {$p}t_item_description c LEFT JOIN {$p}t_locale x ON c.fk_c_locale_code = x.pk_c_code WHERE x.pk_c_code IS NULL"),
        $count("SELECT COUNT(*) FROM {$p}t_meta_fields c LEFT JOIN {$p}t_meta_group x ON c.fk_i_group_id = x.pk_i_id WHERE c.fk_i_group_id IS NOT NULL AND x.pk_i_id IS NULL"),
        $count("SELECT COUNT(*) FROM {$p}t_form_submission c LEFT JOIN {$p}t_meta_group x ON c.fk_i_group_id = x.pk_i_id WHERE x.pk_i_id IS NULL"),
        $count("SELECT COUNT(*) FROM {$p}t_alerts c LEFT JOIN {$p}t_user x ON c.fk_i_user_id = x.pk_i_id WHERE c.fk_i_user_id IS NOT NULL AND x.pk_i_id IS NULL"),
    );
};

harness_section('migration 0054');

seed_locale($admin);
seed_country($admin);
seed_currency($admin);
$cat   = seed_category($admin);
$user  = seed_user($admin, 'keeper', 'keeper@example.test');
$item  = seed_item($admin, $cat, $user, 'Kept listing');
$member = seed_user($admin, 'member', 'member@example.test');
$admin->query("INSERT INTO {$p}t_meta_group (pk_i_id, s_name, s_slug) VALUES (1, 'Contact', 'contact')");

$seedOrphans = static function () use ($admin, $p, $item, &$member): void {
    $admin->query('SET FOREIGN_KEY_CHECKS = 0');
    $admin->query("INSERT IGNORE INTO {$p}t_item_description VALUES
        ($item, 'en_US', 'kept', 'kept'), (999001, 'en_US', 'no item', 'x'), ($item, 'xx_XX', 'no locale', 'x')");
    $admin->query("INSERT INTO {$p}t_meta_fields (pk_i_id, s_name, s_slug, fk_i_group_id) VALUES (1, 'Kept', 'kept', 1), (2, 'Loose', 'loose', 999002)");
    $admin->query("INSERT INTO {$p}t_form_submission (pk_i_id, fk_i_group_id, s_context_type, dt_created)
        VALUES (1, 1, 'page', NOW()), (2, 999002, 'page', NOW())");
    $admin->query("INSERT INTO {$p}t_form_submission_value (fk_i_submission_id, fk_i_field_id, s_value) VALUES (1, 1, 'a'), (2, 1, 'b')");
    $admin->query("INSERT INTO {$p}t_alerts (pk_i_id, s_email, fk_i_user_id, s_search, e_type) VALUES
        (1, 'member@example.test', $member, 's', 'DAILY'), (2, 'legacy@example.test', 0, 's', 'DAILY'),
        (3, 'guest@example.test', NULL, 's', 'DAILY'), (4, 'gone@example.test', 999003, 's', 'DAILY')");
    $admin->query('SET FOREIGN_KEY_CHECKS = 1');
};

$dropKeys();
// Types that drifted from the parent: a signed id, and a collation the join cannot mix.
$admin->query("ALTER TABLE {$p}t_alerts MODIFY fk_i_user_id INT NULL DEFAULT NULL");
$admin->query("ALTER TABLE {$p}t_item_description MODIFY fk_c_locale_code CHAR(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL");
$seedOrphans();
pin('fixture: one orphan per key, plus the legacy 0 alert', array(1, 1, 1, 1, 2), $orphans());

$migrate('0054_missing_foreign_keys.php');
$migrate('0054_missing_foreign_keys.php');

pin('every key present once, with its rule', $wanted, $fkState());
pin('no orphans left', array(0, 0, 0, 0, 0), $orphans());
pin('the valid description survives', array("$item en_US"), $column("SELECT CONCAT(fk_i_item_id, ' ', fk_c_locale_code) FROM {$p}t_item_description"));
pin('an orphan field is loosened, not deleted', array('1:1', '2:-'), $column("SELECT CONCAT(pk_i_id, ':', IFNULL(fk_i_group_id, '-')) FROM {$p}t_meta_fields ORDER BY pk_i_id"));
pin('an orphan submission is deleted with its values', array(array('1'), array('1')), array(
    $column("SELECT pk_i_id FROM {$p}t_form_submission"),
    $column("SELECT fk_i_submission_id FROM {$p}t_form_submission_value"),
));
pin('legacy 0 alert becomes a guest; a deleted user\'s alert is deleted', array("1:$member", '2:-', '3:-'), $column("SELECT CONCAT(pk_i_id, ':', IFNULL(fk_i_user_id, '-')) FROM {$p}t_alerts ORDER BY pk_i_id"));
pin('column types match the parents', array('int unsigned - YES', 'char(5) utf8mb4_general_ci NO'), array(
    $colType('t_alerts', 'fk_i_user_id'),
    $colType('t_item_description', 'fk_c_locale_code'),
));
pin('foreign_key_checks is restored', '1', (string)$conn->scalar('SELECT @@SESSION.foreign_key_checks'));

$admin->query("DELETE FROM {$p}t_user WHERE pk_i_id = $member");
$admin->query("DELETE FROM {$p}t_meta_group WHERE pk_i_id = 1");
pin('the keys act: alerts and submissions cascade, fields loosen', array(0, 0, 0, array('1:-', '2:-')), array(
    $count("SELECT COUNT(*) FROM {$p}t_alerts WHERE fk_i_user_id IS NOT NULL"),
    $count("SELECT COUNT(*) FROM {$p}t_form_submission"),
    $count("SELECT COUNT(*) FROM {$p}t_form_submission_value"),
    $column("SELECT CONCAT(pk_i_id, ':', IFNULL(fk_i_group_id, '-')) FROM {$p}t_meta_fields ORDER BY pk_i_id"),
));

harness_section('migration 0054: a key already added without checking the rows');

// db:repair adds keys with checks off, so orphans can sit under an existing key.
$before = $column("SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY 1");
$admin->query('SET FOREIGN_KEY_CHECKS = 0');
$admin->query("INSERT INTO {$p}t_item_description VALUES (999004, 'en_US', 'late', 'x')");
$admin->query("INSERT INTO {$p}t_alerts (s_email, fk_i_user_id, s_search, e_type) VALUES ('late@example.test', 999005, 's', 'DAILY')");
$admin->query('SET FOREIGN_KEY_CHECKS = 1');
$migrate('0054_missing_foreign_keys.php');
pin('orphans are cleared', array(0, 0, 0, 0, 0), $orphans());
pin('the existing keys are left as they were', $before, $column("SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY 1"));

harness_section('migration 0054: overlapping runs');

$admin->query("DELETE FROM {$p}t_item_description");
$admin->query("DELETE FROM {$p}t_meta_fields");
$admin->query("DELETE FROM {$p}t_alerts");
$member = seed_user($admin, 'member2', 'member2@example.test');
$admin->query("INSERT INTO {$p}t_meta_group (pk_i_id, s_name, s_slug) VALUES (1, 'Contact', 'contact')");

$child = static function (string $file, string $db) use ($p): string {
    return 'error_reporting(E_ALL & ~E_DEPRECATED); define("ABS_PATH", ' . var_export(ABS_PATH, true) . ');'
        . ' define("DB_TABLE_PREFIX", ' . var_export($p, true) . '); define("DB_NAME", ' . var_export($db, true) . ');'
        . ' require ABS_PATH . "oc-includes/vendor/autoload.php";'
        . ' $m = new mysqli(' . implode(', ', array_map(static fn ($v) => var_export($v, true), array(DB_HOST, DB_USER, DB_PASSWORD, $db, (int)ini_get('mysqli.default_port')))) . ');'
        . ' $m->query("SELECT id FROM osc_barrier WHERE id = 1 LOCK IN SHARE MODE"); $m->query("COMMIT");'
        . ' try { (require ABS_PATH . "oc-includes/osclass/installer/migrations/' . $file . '")->up(new mindstellar\database\Connection($m)); }'
        . ' catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }';
};
$db = (string)$admin->query('SELECT DATABASE()')->fetch_row()[0];
$race = static function (string $file, callable $reset) use ($admin, $child, $db): array {
    $admin->query('CREATE TABLE osc_barrier (id INT PRIMARY KEY) ENGINE=InnoDB');
    $admin->query('INSERT INTO osc_barrier VALUES (1)');
    $exits = array();
    for ($round = 0; $round < 3; $round++) {
        $reset();
        $admin->begin_transaction();
        $admin->query('SELECT id FROM osc_barrier WHERE id = 1 FOR UPDATE');
        $procs = array();
        for ($i = 0; $i < 4; $i++) {
            $procs[] = proc_open(array(PHP_BINARY, '-r', $child($file, $db)), array(2 => array('pipe', 'w')), $pipes[$i]);
        }
        usleep(500000);
        $admin->commit();
        foreach ($procs as $i => $proc) {
            $err = stream_get_contents($pipes[$i][2]);
            $exits[] = proc_close($proc) . ($err !== '' ? ' ' . trim($err) : '');
        }
    }
    $admin->query('DROP TABLE osc_barrier');

    return $exits;
};
$fkRounds = array();
$exits = $race('0054_missing_foreign_keys.php', static function () use ($dropKeys, $seedOrphans, $admin, $p, &$fkRounds, $fkState, $orphans) {
    $fkRounds[] = array($fkState(), $orphans());
    $dropKeys();
    foreach (array('t_item_description', 't_meta_fields', 't_form_submission_value', 't_form_submission', 't_alerts') as $t) {
        $admin->query("DELETE FROM $p$t");
    }
    $seedOrphans();
});
$fkRounds[] = array($fkState(), $orphans());
array_shift($fkRounds);
pin('every overlapping run succeeds', array_fill(0, 12, '0'), $exits);
pin('each round ends with each key once and no orphans', array_fill(0, 3, array($wanted, array(0, 0, 0, 0, 0))), $fkRounds);

harness_section('migration 0055');

$admin->query('SET FOREIGN_KEY_CHECKS = 0');
$admin->query("DELETE FROM {$p}t_alerts");
$admin->query("DELETE FROM {$p}t_item");
$admin->query("DELETE FROM {$p}t_user");
$admin->query('SET FOREIGN_KEY_CHECKS = 1');
$admin->query("ALTER TABLE {$p}t_user DROP INDEX uk_user_username, ADD INDEX idx_s_username (s_username)");

$x100 = str_repeat('x', 100);
$seedUsers = static function () use ($admin, $p, $x100): void {
    $admin->query("DELETE FROM {$p}t_user");
    $rows = array(
        5001 => '', 5002 => '5001', 5003 => '5001_2', 5004 => '',
        5005 => 'Bob', 5006 => 'bob', 5007 => 'BOB',
        5008 => 'alice', 5009 => 'ALICE', 5010 => 'alice_5009',
        5011 => $x100, 5012 => strtoupper($x100), 5013 => 'solo',
    );
    foreach ($rows as $id => $name) {
        $admin->query("INSERT INTO {$p}t_user (pk_i_id, dt_reg_date, s_name, s_username, s_password, s_email)"
            . " VALUES ($id, NOW(), 'n', '" . $admin->real_escape_string($name) . "', '', 'u$id@example.test')");
    }
};
$usernames = static function () use ($column, $p): array {
    return $column("SELECT CONCAT(pk_i_id, '=', s_username) FROM {$p}t_user ORDER BY pk_i_id");
};
$expected = array(
    '5001=5001_3', '5002=5001', '5003=5001_2', '5004=5004',
    '5005=Bob', '5006=bob_5006', '5007=BOB_5007',
    '5008=alice', '5009=ALICE_5009_2', '5010=alice_5009',
    '5011=' . $x100, '5012=' . str_repeat('X', 95) . '_5012', '5013=solo',
);
$userIndexes = static function () use ($column, $p): array {
    return $column("SELECT CONCAT(INDEX_NAME, ':', NON_UNIQUE) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}t_user' AND COLUMN_NAME = 's_username' ORDER BY 1");
};
$dupGroups = static function () use ($count, $p): int {
    return $count("SELECT COUNT(*) FROM (SELECT s_username FROM {$p}t_user GROUP BY s_username HAVING COUNT(*) > 1) d");
};

$seedUsers();
$migrate('0055_user_username_unique.php');
$migrate('0055_user_username_unique.php');

pin('empty and duplicate usernames are renamed', $expected, $usernames());
pin('no duplicates remain', 0, $dupGroups());
pin('uk_user_username replaces idx_s_username', array('uk_user_username:0'), $userIndexes());

harness_section('migration 0055: overlapping runs');

$rounds = array();
$exits = $race('0055_user_username_unique.php', static function () use ($admin, $p, $seedUsers, &$rounds, $usernames, $userIndexes) {
    $rounds[] = array($usernames(), $userIndexes());
    $admin->query("ALTER TABLE {$p}t_user DROP INDEX uk_user_username, ADD INDEX idx_s_username (s_username)");
    $seedUsers();
});
$rounds[] = array($usernames(), $userIndexes());
array_shift($rounds);
pin('every overlapping run succeeds', array_fill(0, 12, '0'), $exits);
pin('each round ends renamed, with only the unique key', array_fill(0, 3, array($expected, array('uk_user_username:0'))), $rounds);

// Back to struct.sql for the model tests that follow.
$admin->query('SET FOREIGN_KEY_CHECKS = 0');
foreach (array('t_item_description', 't_meta_fields', 't_meta_group', 't_form_submission', 't_form_submission_value', 't_alerts', 't_item', 't_user', 't_locale', 't_category', 't_category_description') as $t) {
    $admin->query("DELETE FROM $p$t");
}
$admin->query('SET FOREIGN_KEY_CHECKS = 1');

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
