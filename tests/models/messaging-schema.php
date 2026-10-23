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
 * Migration 0085: t_conversation and t_message exist with their indexes and cascades after a fresh
 * struct.sql import, the migration changes nothing on a re-run and rebuilds both tables when
 * they are gone, and the messaging switch is off.
 * Usage: php tests/models/messaging-schema.php
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\database\Connection;

$admin = scratchdb_session('osc_models_messaging_schema');
$conn  = Connection::getInstance();
$p     = DB_TABLE_PREFIX;

$migrate = static function () use ($conn) {
    (require ABS_PATH . 'oc-includes/osclass/installer/migrations/0085_messaging.php')->up($conn);
};
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
$cascades = static function () use ($admin, $p): array {
    $res = $admin->query(
        "SELECT TABLE_NAME, REFERENCED_TABLE_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS"
        . " WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ('{$p}t_conversation', '{$p}t_message')"
        . " ORDER BY TABLE_NAME, REFERENCED_TABLE_NAME"
    );
    $out = array();
    while ($row = $res->fetch_row()) {
        $out[] = implode(' ', $row);
    }

    return $out;
};
$create = static function (string $table) use ($admin, $p): string {
    return (string) $admin->query("SHOW CREATE TABLE {$p}$table")->fetch_row()[1];
};
$switch = static function () use ($admin, $p): ?string {
    $row = $admin->query("SELECT s_value FROM {$p}t_preference WHERE s_name = 'enabled_messaging'")->fetch_row();

    return $row === null ? null : (string) $row[0];
};

$conversationIndexes = array(
    'PRIMARY'       => 'pk_i_id',
    'idx_buyer'     => 'fk_i_buyer_id,dt_last_message',
    'idx_item_buyer' => 'fk_i_item_id,fk_i_buyer_id,fk_i_seller_id',
    'idx_seller'    => 'fk_i_seller_id,dt_last_message',
    'idx_state'     => 's_state',
);
$messageIndexes = array(
    'PRIMARY'          => 'pk_i_id',
    'idx_conversation' => 'fk_i_conversation_id,pk_i_id',
    'idx_state'        => 's_state',
);
ksort($conversationIndexes);
ksort($messageIndexes);
// MySQL names an unnamed foreign key's index after the column; only the explicit ones are pinned.
$named = static function (array $map, array $names): array {
    $out = array_intersect_key($map, array_flip($names));
    ksort($out);

    return $out;
};
$cascadeMap = array(
    "{$p}t_conversation {$p}t_user CASCADE",
    "{$p}t_conversation {$p}t_user CASCADE",
    "{$p}t_message {$p}t_conversation CASCADE",
);

harness_section('fresh struct.sql import');
pin('t_conversation indexes', $conversationIndexes, $named($indexes('t_conversation'), array_keys($conversationIndexes)));
pin('t_message indexes', $messageIndexes, $named($indexes('t_message'), array_keys($messageIndexes)));
pin('both account keys and the thread key cascade', $cascadeMap, $cascades());

harness_section('migration 0085 re-run');
$migrate();
pin('t_conversation indexes unchanged', $conversationIndexes, $named($indexes('t_conversation'), array_keys($conversationIndexes)));
pin('t_message indexes unchanged', $messageIndexes, $named($indexes('t_message'), array_keys($messageIndexes)));
pin('cascades unchanged', $cascadeMap, $cascades());
pin('messaging switch is off', '0', $switch());

harness_section('migration 0085 on an upgraded site');
$fresh = array($create('t_conversation'), $create('t_message'));
$admin->query("DELETE FROM {$p}t_preference WHERE s_name = 'enabled_messaging'");
$admin->query("DROP TABLE {$p}t_message");
$admin->query("DROP TABLE {$p}t_conversation");
$migrate();
pin('t_conversation indexes rebuilt', $conversationIndexes, $named($indexes('t_conversation'), array_keys($conversationIndexes)));
pin('t_message indexes rebuilt', $messageIndexes, $named($indexes('t_message'), array_keys($messageIndexes)));
pin('cascades rebuilt', $cascadeMap, $cascades());
pin('the migration builds the same tables as struct.sql', $fresh, array($create('t_conversation'), $create('t_message')));
pin('messaging switch is added off', '0', $switch());
$admin->query("UPDATE {$p}t_preference SET s_value = '1' WHERE s_name = 'enabled_messaging'");
$migrate();
pin('a re-run keeps a switch the admin turned on', '1', $switch());

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
