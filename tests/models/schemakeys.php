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
 * Migrations 0051-0053: duplicate rows are removed before a primary key is added, an index
 * already present on the same columns under another name is not duplicated, and each
 * migration is a no-op on a second run. The schema is put back to struct.sql at the end.
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\database\Connection;

$admin = scratchdb_session('osc_models_schemakeys');
$conn  = Connection::getInstance();
$p     = DB_TABLE_PREFIX;

$migrate = static function (string $file) use ($conn) {
    (require ABS_PATH . 'oc-includes/osclass/installer/migrations/' . $file)->up($conn);
};
// Index name => columns, for one table.
$indexes = static function (string $table) use ($admin, $p): array {
    $res = $admin->query(
        "SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS"
        . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$p$table' GROUP BY INDEX_NAME ORDER BY INDEX_NAME"
    );
    $out = array();
    while ($row = $res->fetch_row()) {
        $out[$row[0]] = $row[1];
    }

    return $out;
};
$count = static function (string $sql) use ($admin): int {
    return (int)$admin->query($sql)->fetch_row()[0];
};

harness_section('migration 0051');

// t_cron is gone from struct.sql (0070); 0051 met it without a key.
$admin->query("CREATE TABLE {$p}t_cron (e_type enum('INSTANT','HOURLY','DAILY','WEEKLY','CUSTOM') NOT NULL,"
    . " d_last_exec DATETIME NOT NULL DEFAULT '1000-01-01 00:00:00', d_next_exec DATETIME NOT NULL DEFAULT '1000-01-01 00:00:00')");
// t_plugin_category is gone from struct.sql (0067); 0051 met it without a key.
$admin->query("CREATE TABLE {$p}t_plugin_category (s_plugin_name VARCHAR(40) NOT NULL, fk_i_category_id INT UNSIGNED NOT NULL)");
// WEEKLY has no row at all, as after a lost dedupe race.
$admin->query("INSERT INTO {$p}t_cron VALUES
    ('HOURLY', '2026-01-01 00:00:00', '2026-01-01 01:00:00'),
    ('HOURLY', '2026-03-01 00:00:00', '2026-03-01 01:00:00'),
    ('HOURLY', '2026-03-01 00:00:00', '2026-03-01 00:30:00'),
    ('HOURLY', '2026-02-01 00:00:00', '2026-02-01 01:00:00'),
    ('DAILY', '2026-01-01 00:00:00', '2026-01-02 00:00:00'),
    ('DAILY', '2026-01-01 00:00:00', '2026-01-02 00:00:00'),
    ('DAILY', '2025-12-01 00:00:00', '2025-12-02 00:00:00'),
    ('CUSTOM', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
$admin->query('SET FOREIGN_KEY_CHECKS = 0');
$admin->query("INSERT INTO {$p}t_plugin_category VALUES ('demo', 5), ('demo', 5), ('demo', 5), ('demo', 6), ('other', 5)");
$admin->query('SET FOREIGN_KEY_CHECKS = 1');

$migrate('0051_cron_and_plugin_category_keys.php');
$migrate('0051_cron_and_plugin_category_keys.php');

$cronRows = static function () use ($admin, $p): array {
    $out = array();
    $res = $admin->query("SELECT e_type, d_last_exec, d_next_exec FROM {$p}t_cron ORDER BY e_type, d_last_exec, d_next_exec");
    while ($row = $res->fetch_row()) {
        $out[] = implode(' ', $row);
    }

    return $out;
};
pin('one cron row per type, the newest kept, WEEKLY restored', array(
    'HOURLY 2026-03-01 00:00:00 2026-03-01 01:00:00',
    'DAILY 2026-01-01 00:00:00 2026-01-02 00:00:00',
    'WEEKLY 1000-01-01 00:00:00 1000-01-01 00:00:00',
    'CUSTOM 2026-01-01 00:00:00 2026-01-01 00:00:00',
), $cronRows());
pin('t_cron primary key', 'e_type', $indexes('t_cron')['PRIMARY'] ?? null);
pin('one plugin/category row per pair', 3, $count("SELECT COUNT(*) FROM {$p}t_plugin_category"));
pin('t_plugin_category primary key', 's_plugin_name,fk_i_category_id', $indexes('t_plugin_category')['PRIMARY'] ?? null);

harness_section('migration 0051: concurrent dedupe');

// Several upgrade requests dedupe at once. Each child waits on a row the parent holds,
// so all of them start together when it lets go.
$admin->query("ALTER TABLE {$p}t_cron DROP PRIMARY KEY");
$admin->query('CREATE TABLE osc_barrier (id INT PRIMARY KEY) ENGINE=InnoDB');
$admin->query('INSERT INTO osc_barrier VALUES (1)');
$child = 'error_reporting(E_ALL & ~E_DEPRECATED); define("ABS_PATH", ' . var_export(ABS_PATH, true) . '); define("DB_TABLE_PREFIX", ' . var_export($p, true) . ');'
    . ' require ABS_PATH . "oc-includes/vendor/autoload.php";'
    . ' $m = new mysqli(' . implode(', ', array_map(static fn ($v) => var_export($v, true), array(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, (int)ini_get('mysqli.default_port')))) . ');'
    . ' $m->query("SELECT id FROM osc_barrier WHERE id = 1 LOCK IN SHARE MODE"); $m->query("COMMIT");'
    . ' (require ABS_PATH . "oc-includes/osclass/installer/migrations/0051_cron_and_plugin_category_keys.php")'
    . '->dedupeCron(new mindstellar\database\Connection($m), DB_TABLE_PREFIX . "t_cron");';
$lost = array();
for ($round = 0; $round < 5; $round++) {
    $admin->query("DELETE FROM {$p}t_cron");
    for ($i = 0; $i < 20; $i++) {
        $admin->query("INSERT INTO {$p}t_cron VALUES ('HOURLY', '2026-01-01 00:00:00', '2026-01-01 01:00:00'),"
            . " ('DAILY', '2026-01-0" . ($i % 5 + 1) . " 00:00:00', '2026-01-02 00:00:00')");
    }
    $admin->begin_transaction();
    $admin->query('SELECT id FROM osc_barrier WHERE id = 1 FOR UPDATE');
    $procs = array();
    for ($i = 0; $i < 4; $i++) {
        $procs[] = proc_open(array(PHP_BINARY, '-r', $child), array(), $pipes);
    }
    usleep(500000);
    $admin->commit();
    foreach ($procs as $proc) {
        proc_close($proc);
    }
    $lost[] = implode(',', $cronRows());
}
$admin->query('DROP TABLE osc_barrier');
pin('every concurrent round leaves exactly one row per type', array_fill(0, 5, implode(',', array(
    'HOURLY 2026-01-01 00:00:00 2026-01-01 01:00:00',
    'DAILY 2026-01-05 00:00:00 2026-01-02 00:00:00',
))), $lost);
$migrate('0051_cron_and_plugin_category_keys.php');
pin('t_cron primary key restored', 'e_type', $indexes('t_cron')['PRIMARY'] ?? null);

harness_section('migration 0052');

// Same columns under other names, as hand-added on a live site; and a prefix index that
// must not count as the full-column one.
$admin->query("ALTER TABLE {$p}t_log DROP INDEX idx_date, ADD INDEX idx_oc_t_log_dt_date (dt_date)");
$admin->query("ALTER TABLE {$p}t_user DROP INDEX idx_reg_date, ADD INDEX site_reg_date (dt_reg_date)");
$admin->query("ALTER TABLE {$p}t_item DROP INDEX idx_expiration, ADD INDEX site_expiration (dt_expiration)");
$admin->query("ALTER TABLE {$p}t_alerts DROP INDEX idx_email, ADD INDEX s_email_prefix (s_email(10))");
$admin->query("ALTER TABLE {$p}t_latest_searches DROP INDEX idx_date");

$migrate('0052_operational_indexes.php');
$migrate('0052_operational_indexes.php');

$log = $indexes('t_log');
pin('t_log keeps its own dt_date index and gains no copy', array('idx_oc_t_log_dt_date' => 'dt_date'), $log);
pin('t_user: no idx_reg_date beside a same-column index', false, isset($indexes('t_user')['idx_reg_date']));
$item = $indexes('t_item');
pin('t_item: no idx_expiration beside a same-column index', array(false, 'dt_expiration'), array(isset($item['idx_expiration']), $item['site_expiration'] ?? null));
pin('t_alerts: a prefix index does not stand in for idx_email', 's_email', $indexes('t_alerts')['idx_email'] ?? null);
pin('t_latest_searches: a missing idx_date is added', 'd_date', $indexes('t_latest_searches')['idx_date'] ?? null);

harness_section('migration 0053');

$live = 'fk_i_category_id,b_enabled,b_active,b_spam,dt_pub_date,dt_expiration,b_premium';
$admin->query("ALTER TABLE {$p}t_item DROP INDEX idx_category_live");
$admin->query("ALTER TABLE {$p}t_item ADD INDEX live_by_category (" . str_replace(',', ', ', $live) . ')');
$migrate('0053_item_category_live_index.php');
$migrate('0053_item_category_live_index.php');
pin('an index on the same columns under another name is not copied', array('live_by_category'), array_keys(array_filter($indexes('t_item'), static fn ($c) => $c === $live)));

$admin->query("ALTER TABLE {$p}t_item DROP INDEX live_by_category");
$migrate('0053_item_category_live_index.php');
$migrate('0053_item_category_live_index.php');
pin('a missing idx_category_live is added once', array('idx_category_live'), array_keys(array_filter($indexes('t_item'), static fn ($c) => $c === $live)));

harness_section('migration 0070');

$admin->query("DELETE FROM {$p}t_cron");
$admin->query("INSERT INTO {$p}t_cron VALUES ('HOURLY', '2026-01-01 00:00:00', '2026-01-01 01:00:00')");
$admin->query("CREATE TABLE {$p}t_alerts_sent (d_date DATE NOT NULL, i_num_alerts_sent INT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (d_date))");
$admin->query("INSERT INTO {$p}t_alerts_sent VALUES ('2026-01-01', 7)");
$admin->query("CREATE TABLE {$p}t_user_email_tmp (fk_i_user_id INT UNSIGNED NOT NULL, s_new_email VARCHAR(100) NOT NULL, dt_date DATETIME NOT NULL, PRIMARY KEY (fk_i_user_id))");
$admin->query("INSERT INTO {$p}t_user_email_tmp VALUES (5, 'new@example.test', NOW()), (6, 'old@example.test', NOW() - INTERVAL 8 DAY)");
$admin->query("CREATE TABLE {$p}t_item_upload_tmp (pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT, s_token VARCHAR(64) NOT NULL DEFAULT '',"
    . " s_uuid VARCHAR(191) NOT NULL DEFAULT '', s_file VARCHAR(191) NOT NULL DEFAULT '', dt_date DATETIME NOT NULL, PRIMARY KEY (pk_i_id))");
$admin->query("INSERT INTO {$p}t_item_upload_tmp (s_token, s_uuid, s_file, dt_date) VALUES ('tok', 'u1', 'a.jpg', NOW()), ('tok', 'u2', 'b.jpg', NOW() - INTERVAL 3 HOUR)");
$admin->query("INSERT INTO {$p}t_key_value (s_group, s_key, s_value, dt_created) VALUES ('alerts_sent', '2026-01-01', '9', NOW())");
$migrate('0070_small_tables_to_key_value.php');
$migrate('0070_small_tables_to_key_value.php');
$kv = static function (string $group) use ($admin, $p): array {
    $out = array();
    foreach ($admin->query("SELECT s_key, s_value FROM {$p}t_key_value WHERE s_group = '$group' ORDER BY s_key")->fetch_all() as $row) {
        $out[$row[0]] = $row[1];
    }

    return $out;
};
$expiresIn = static fn (string $group, string $key): int => (int) $count(
    "SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), dt_expires) FROM {$p}t_key_value WHERE s_group = '$group' AND s_key = '$key'"
);
pin('a moved e-mail change keeps its 7 days and a moved upload its 2 hours', array(true, true), array(
    abs($expiresIn('email_change', '5') - 7 * 24 * 60) <= 2,
    abs($expiresIn('upload.' . sha1('tok'), 'a.jpg') - 120) <= 2,
));
pin('cron times, alert counts, live e-mail changes and live uploads move to the key-value store; a key already there is kept', array(
    array('HOURLY' => '{"last":"2026-01-01 00:00:00","next":"2026-01-01 01:00:00"}'),
    array('2026-01-01' => '9'),
    array('5' => 'new@example.test'),
    array('a.jpg' => 'u1'),
), array($kv('cron'), $kv('alerts_sent'), $kv('email_change'), $kv('upload.' . sha1('tok'))));
pin('the four tables are dropped', 0, $count(
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()"
    . " AND table_name IN ('{$p}t_cron', '{$p}t_alerts_sent', '{$p}t_user_email_tmp', '{$p}t_item_upload_tmp')"
));
$admin->query("DELETE FROM {$p}t_key_value WHERE s_group IN ('cron', 'alerts_sent', 'email_change', 'upload." . sha1('tok') . "')");

// Back to struct.sql for the model tests that follow.
$admin->query("ALTER TABLE {$p}t_log DROP INDEX idx_oc_t_log_dt_date, ADD INDEX idx_date (dt_date)");
$admin->query("ALTER TABLE {$p}t_user DROP INDEX site_reg_date, ADD INDEX idx_reg_date (dt_reg_date)");
$admin->query("ALTER TABLE {$p}t_item DROP INDEX site_expiration, ADD INDEX idx_expiration (dt_expiration)");
$admin->query("ALTER TABLE {$p}t_alerts DROP INDEX s_email_prefix");
$admin->query("DROP TABLE {$p}t_plugin_category");

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
