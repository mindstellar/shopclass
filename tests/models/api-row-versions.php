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
 * RowVersions on real tables: stable versions, locked reads and rollback.
 * Usage: php tests/models/api-row-versions.php
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\api\http\RowVersions;
use mindstellar\apiaccess\Credential;
use mindstellar\apiaccess\CredentialKind;
use mindstellar\database\Connection;

if (!defined('OSC_CSRF_SECRET')) {
    define('OSC_CSRF_SECRET', 'row-versions-test-secret');
}

$admin    = scratchdb_session('osc_models_api_row_versions');
$p        = DB_TABLE_PREFIX;
seed_locale($admin);
seed_country($admin);
seed_currency($admin);
$user     = seed_user($admin);
$item     = seed_item($admin, seed_category($admin), $user);
$versions = new RowVersions();
$nobody   = Credential::anonymous();
$owner    = new Credential(CredentialKind::KEY, ['account:write'], $user);
$listing  = static fn (): ?string => $versions->version('listings/{id}', ['id' => (string) $item], $nobody);

harness_section('version');
$v1 = $listing();
pin('a listing has a 24-character version, the same when read again', [24, $v1], [strlen((string) $v1), $listing()]);
pin('its admin path has the same one', $v1, $versions->version('admin/listings/{id}', ['id' => (string) $item], $nobody));
$admin->query("UPDATE {$p}t_item_description SET s_title = 'Edited' WHERE fk_i_item_id = $item");
$v2 = $listing();
check('editing its description changes it', $v2 !== $v1);
pin('a missing listing has none', null, $versions->version('listings/{id}', ['id' => '999999'], $nobody));
pin('a path with no stored version is not supported', [false, true], [$versions->supports('admin/settings'), $versions->supports('listings/{id}')]);

$account = $versions->version('account', [], $owner);
$admin->query("UPDATE {$p}t_user SET dt_access_date = NOW() + INTERVAL 1 DAY, s_access_ip = '192.0.2.9' WHERE pk_i_id = $user");
pin('the account\'s is the user\'s, and a new access does not change it', [$account, $account], [$versions->version('admin/users/{id}', ['id' => (string) $user], $nobody), $versions->version('account', [], $owner)]);
$admin->query("UPDATE {$p}t_user SET s_name = 'Renamed' WHERE pk_i_id = $user");
check('a new name does', $versions->version('account', [], $owner) !== $account);
pin('an anonymous credential has no account', null, $versions->version('account', [], $nobody));

harness_section('one query');
pin('a listing\'s version is one query, not one per table (was 4)', 1, harness_query_count(static fn () => $versions->version('listings/{id}', ['id' => (string) $item], $nobody)));
pin('the account\'s too (was 2)', 1, harness_query_count(static fn () => $versions->version('account', [], $owner)));
pin('an owner check still refuses another user\'s listing', null, $versions->version('listings/{id}', ['id' => (string) $item], new Credential(CredentialKind::KEY, [], $user + 1), false, true));
check('an owner check lets the owner through', $versions->version('listings/{id}', ['id' => (string) $item], $owner, false, true) !== null);

harness_section('columns');
foreach (RowVersions::COLUMNS as $table => $columns) {
    $live = array_column($admin->query("SHOW COLUMNS FROM {$p}{$table}")->fetch_all(MYSQLI_ASSOC), 'Field');
    $mine = array_merge($columns, RowVersions::IGNORED[$table] ?? []);
    sort($live);
    sort($mine);
    pin("every column of $table is hashed or deliberately ignored", $live, $mine);
}

harness_section('every member change');
$admin->query('SET FOREIGN_KEY_CHECKS = 0');
$admin->query("INSERT INTO {$p}t_item_meta (fk_i_item_id, fk_i_field_id, s_value, s_multi) VALUES ($item, 1, 'red', '')");
$admin->query("INSERT INTO {$p}t_user_description (fk_i_user_id, fk_c_locale_code, s_info) VALUES ($user, 'en_US', 'About me')");
$sweep = static function (string $table, string $where, string $join, callable $read) use ($admin, $p): array {
    $missed = [];
    $types  = [];
    foreach ($admin->query("SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}{$table}'") as $c) {
        $types[$c['COLUMN_NAME']] = $c;
    }
    foreach (RowVersions::COLUMNS[$table] as $col) {
        if ($col === $join) {
            continue;
        }
        $t      = $types[$col];
        $before = $read();
        $old    = $admin->query("SELECT $col FROM {$p}{$table} WHERE $where")->fetch_row()[0];
        $set    = match (true) {
            $t['DATA_TYPE'] === 'enum'                                     => "'" . current(array_diff(str_getcsv(substr($t['COLUMN_TYPE'], 5, -1), ',', "'"), [(string) $old])) . "'",
            in_array($t['DATA_TYPE'], ['datetime', 'date', 'timestamp'], true) => "COALESCE($col, NOW()) + INTERVAL 1 DAY",
            in_array($t['DATA_TYPE'], ['char', 'varchar', 'text', 'mediumtext', 'longtext', 'tinytext'], true)
                => "IF($col IS NULL OR $col = '', 'x', IF(CHAR_LENGTH($col) < {$t['CHARACTER_MAXIMUM_LENGTH']}, CONCAT($col, 'x'), CONCAT(IF(LEFT($col, 1) = 'y', 'z', 'y'), SUBSTRING($col, 2))))",
            default                                                        => "COALESCE($col, 0) + 1",
        };
        $admin->query("UPDATE {$p}{$table} SET $col = $set WHERE $where");
        if ($read() === $before) {
            $missed[] = $col;
        }
        $restore = $admin->prepare("UPDATE {$p}{$table} SET $col = ? WHERE $where");
        $restore->bind_param('s', $old);
        $restore->execute();
        if ($read() !== $before) {
            $missed[] = $col . ' (not restored)';
        }
    }

    return $missed;
};
$listingVersion = static fn (): ?string => $versions->version('listings/{id}', ['id' => (string) $item], $nobody);
$accountVersion = static fn (): ?string => $versions->version('account', [], $owner);
pin('every t_item column changes a listing\'s version', [], $sweep('t_item', "pk_i_id = $item", 'pk_i_id', $listingVersion));
pin('every t_item_description column does', [], $sweep('t_item_description', "fk_i_item_id = $item", 'fk_i_item_id', $listingVersion));
pin('every t_item_location column does', [], $sweep('t_item_location', "fk_i_item_id = $item", 'fk_i_item_id', $listingVersion));
pin('every t_item_meta column does', [], $sweep('t_item_meta', "fk_i_item_id = $item", 'fk_i_item_id', $listingVersion));
pin('every hashed t_user column changes the account\'s version', [], $sweep('t_user', "pk_i_id = $user", 'pk_i_id', $accountVersion));
pin('every t_user_description column does', [], $sweep('t_user_description', "fk_i_user_id = $user", 'fk_i_user_id', $accountVersion));
$before = $listingVersion();
$admin->query("INSERT INTO {$p}t_item_meta (fk_i_item_id, fk_i_field_id, s_value, s_multi) VALUES ($item, 2, 'blue', '')");
$added = $listingVersion();
$admin->query("DELETE FROM {$p}t_item_meta WHERE fk_i_item_id = $item AND fk_i_field_id = 2");
pin('adding a child row changes it, removing it changes it back', [true, $before], [$added !== $before, $listingVersion()]);
$admin->query('SET FOREIGN_KEY_CHECKS = 1');
$v2 = $listingVersion();

harness_section('lock');
$blocked = static function () use ($admin, $p, $item): bool {
    $admin->query('SET SESSION innodb_lock_wait_timeout = 1');
    try {
        return $admin->query("UPDATE {$p}t_item SET b_premium = 1 - b_premium WHERE pk_i_id = $item") === false && $admin->errno === 1205;
    } catch (mysqli_sql_exception $e) {
        return $e->getCode() === 1205;
    }
};
$inside = $versions->atomically(static function () use ($versions, $item, $nobody, $blocked): array {
    return [$versions->version('listings/{id}', ['id' => (string) $item], $nobody, true), $blocked()];
});
pin('a locked read holds the row against another writer until commit', [$v2, true], $inside);
check('after which the row can be written again', !$blocked());

try {
    $versions->atomically(static function () use ($item, $p): void {
        Connection::getInstance()->execute("UPDATE {$p}t_item_description SET s_title = 'Lost' WHERE fk_i_item_id = ?", [$item]);

        throw new RuntimeException('handler failed');
    });
} catch (RuntimeException $e) {
}
pin('a throw rolls the transaction back', 'Edited', $admin->query("SELECT s_title FROM {$p}t_item_description WHERE fk_i_item_id = $item")->fetch_row()[0]);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
