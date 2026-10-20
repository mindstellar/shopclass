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
 * A listing's photos, one deleted photo and an account's avatar are removed from disk only once the outermost
 * transaction commits, and stay when it rolls back.
 *
 * Usage:  php tests/models/delete-files-commit.php        (standalone, own scratch database)
 *         php tests/run-models.php delete-files-commit    (as part of the suite)
 */

require_once __DIR__ . '/../lib/harness.php';
require_once __DIR__ . '/../lib/api-admin-kit.php';

if (api_admin_isolated(__FILE__)) {
    return;
}

$dfRoot = sys_get_temp_dir() . '/osc-delete-files-' . getmypid() . '/';
@mkdir($dfRoot . 'photos/', 0777, true);
@mkdir($dfRoot . 'avatars/', 0777, true);
register_shutdown_function(static function () use ($dfRoot): void {
    foreach (array('photos/', 'avatars/') as $dir) {
        array_map('unlink', glob($dfRoot . $dir . '*') ?: array());
        @rmdir($dfRoot . $dir);
    }
    @rmdir($dfRoot);
});
// Photo paths are read relative to the site root, avatar paths through osc_base_path().
chdir($dfRoot);
if (!function_exists('osc_base_path')) {
    function osc_base_path()
    {
        return $GLOBALS['dfRoot'];
    }
}

$admin = api_admin_boot('osc_models_delete_files_commit');

use mindstellar\auth\Actor;
use mindstellar\database\Db;
use mindstellar\listing\ListingService;
use mindstellar\listing\PhotoService;
use mindstellar\user\AccountService;

$p      = DB_TABLE_PREFIX;
$locale = seed_locale($admin);
seed_currency($admin);
seed_country($admin, 'US', 'United States');
$cars   = seed_category($admin, 'Cars', null, $locale);
scratchdb_forget_cache();
osc_reset_preferences();
$_SERVER['REMOTE_ADDR'] = '192.0.2.90';
Params::init();

/** A listing with one photo whose four files exist on disk. */
$listingWithPhoto = static function (?int $userId) use ($admin, $cars, $p, $dfRoot): array {
    $item  = seed_item($admin, $cars, $userId, 'Car');
    $photo = seed_photo($admin, $item, array('s_name' => 'x', 's_path' => 'photos/'));
    foreach (array('', '_original', '_thumbnail', '_preview') as $variant) {
        file_put_contents($dfRoot . 'photos/' . $photo . $variant . '.jpg', 'jpg');
    }

    return array($item, $dfRoot . 'photos/' . $photo . '.jpg');
};
/** An avatar row and its file for $userId. */
$avatar = static function (int $userId) use ($admin, $p, $dfRoot): string {
    $id = seed_exec($admin, "INSERT INTO {$p}t_resource (s_owner_type, i_owner_id, s_name, s_extension, s_content_type, s_path, dt_created) VALUES (?, ?, 'a', 'jpg', 'image/jpeg', 'avatars/', NOW())", 'si', array(\mindstellar\model\Resource::OWNER_USER, $userId));
    file_put_contents($dfRoot . 'avatars/' . $id . '.jpg', 'jpg');

    return $dfRoot . 'avatars/' . $id . '.jpg';
};
$rows   = static fn (string $table, string $where): int => (int) $admin->query("SELECT COUNT(*) FROM {$p}$table WHERE $where")->fetch_row()[0];
$secret = static fn (int $item): string => (string) $admin->query("SELECT s_secret FROM {$p}t_item WHERE pk_i_id = $item")->fetch_row()[0];

$throwIn = null;
foreach (array('after_delete_item', 'after_delete_user') as $hook) {
    osc_add_hook($hook, static function () use (&$throwIn, $hook): void {
        if ($throwIn === $hook) {
            throw new RuntimeException('hook failed');
        }
    });
}
$attempt = static function (callable $fn): string {
    try {
        $fn();
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }

    return 'done';
};

harness_section('the after-commit queue');
$ran = array();
Db::afterCommit(static function () use (&$ran): void {
    $ran[] = 'now';
});
pin('with no transaction open it runs at once', array('now'), $ran);
$ran = array();
Db::transaction(static function () use (&$ran): void {
    Db::afterCommit(static function () use (&$ran): void {
        $ran[] = 'outer';
    });
    try {
        Db::transaction(static function () use (&$ran): void {
            Db::afterCommit(static function () use (&$ran): void {
                $ran[] = 'rolled back';
            });
            throw new RuntimeException('inner');
        });
    } catch (RuntimeException $e) {
    }
    Db::transaction(static function () use (&$ran): void {
        Db::afterCommit(static function () use (&$ran): void {
            $ran[] = 'inner';
        });
    });
    pin('nothing runs before the outermost commit', array(), $ran);
});
pin('the outer commit runs what committed, not what a savepoint rolled back', array('outer', 'inner'), $ran);
$ran = array();
$attempt(static fn () => Db::transaction(static function () use (&$ran): void {
    Db::afterCommit(static function () use (&$ran): void {
        $ran[] = 'dropped';
    });
    throw new RuntimeException('outer');
}));
pin('a rollback drops the queue', array(), $ran);

harness_section('deleting a listing');
[$item, $file] = $listingWithPhoto(null);
$throwIn = 'after_delete_item';
pin('a throwing after_delete_item fails the delete', 'hook failed', $attempt(static fn () => (new ListingService())->delete($item, $secret($item), Actor::guest('192.0.2.90'))));
pin('the listing, its photo row and its files survive the rollback', array(1, 1, true), array($rows('t_item', "pk_i_id = $item"), $rows('t_resource', "s_owner_type = 'item' AND i_owner_id = $item"), is_file($file)));
$throwIn = null;
pin('a normal delete goes through', 'done', $attempt(static fn () => (new ListingService())->delete($item, $secret($item), Actor::guest('192.0.2.90'))));
pin('the listing and its files are gone after the commit', array(0, false), array($rows('t_item', "pk_i_id = $item"), is_file($file)));

harness_section('deleting one photo');
[$item, $file] = $listingWithPhoto(null);
$photoId       = (int) $admin->query("SELECT pk_i_id FROM {$p}t_resource WHERE s_owner_type = 'item' AND i_owner_id = $item")->fetch_row()[0];
$admin->query("CREATE TRIGGER {$p}df_block BEFORE DELETE ON {$p}t_resource FOR EACH ROW BEGIN IF OLD.s_owner_type = 'item' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'blocked'; END IF; END");
pin('a failed row delete reports false', false, (new PhotoService())->delete($photoId, $item, Actor::admin(1)));
pin('the row and its file survive', array(1, true), array($rows('t_resource', "pk_i_id = $photoId"), is_file($file)));
$admin->query("DROP TRIGGER {$p}df_block");
pin('a normal delete goes through', true, (new PhotoService())->delete($photoId, $item, Actor::admin(1)));
pin('the row and its file are gone', array(0, false), array($rows('t_resource', "pk_i_id = $photoId"), is_file($file)));

harness_section('deleting an account');
$ann            = seed_user($admin, 'ann', 'ann@example.test');
[$annItem, $annPhoto] = $listingWithPhoto($ann);
$annAvatar      = $avatar($ann);
$throwIn        = 'after_delete_user';
pin('a throwing after_delete_user fails the delete', 'hook failed', $attempt(static fn () => (new AccountService())->delete($ann, Actor::admin(1))));
pin('the user, the listing, the photo and the avatar survive the rollback', array(1, 1, true, true), array($rows('t_user', "pk_i_id = $ann"), $rows('t_item', "pk_i_id = $annItem"), is_file($annPhoto), is_file($annAvatar)));
$throwIn = null;
pin('a normal delete goes through', 'done', $attempt(static fn () => (new AccountService())->delete($ann, Actor::admin(1))));
pin('the user, the photo and the avatar are gone after the commit', array(0, false, false), array($rows('t_user', "pk_i_id = $ann"), is_file($annPhoto), is_file($annAvatar)));

exit(harness_result());
