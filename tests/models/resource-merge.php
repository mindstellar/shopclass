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
 * Migration 0071: listing photos move from t_item_resource into t_resource with their ids, and a
 * t_resource row whose id a photo holds is renumbered without its files or stored ids breaking.
 * Every row has files on disk, so each check reads the file a row points at.
 *
 * Usage:  php tests/models/resource-merge.php
 *         php tests/run-models.php resource-merge
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

use mindstellar\database\Connection;
use mindstellar\model\Resource;
use mindstellar\storage\ResourceLocator;

$admin = scratchdb_session('osc_models_resource_merge');
$p     = DB_TABLE_PREFIX;
$conn  = Connection::getInstance();
require_once __DIR__ . '/../lib/stubs.php';
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return ABS_PATH . 'oc-content/plugins/';
    }
}
if (!function_exists('osc_base_path')) {
    function osc_base_path()
    {
        return ABS_PATH;
    }
}
if (!function_exists('osc_base_url')) {
    function osc_base_url()
    {
        return 'https://example.test/';
    }
}
if (!function_exists('osc_cache_get')) {
    function osc_cache_get($key, &$found = null)
    {
        $found = false;

        return false;
    }
    function osc_cache_set($key, $value, $ttl = 0)
    {
        return true;
    }
    function osc_cache_delete($key)
    {
        return true;
    }
}
if (!defined('OSC_CACHE_TTL')) {
    define('OSC_CACHE_TTL', 60);
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hStorage.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hResources.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hItems.php';

$migrate = static fn () => (require ABS_PATH . 'oc-includes/osclass/installer/migrations/0071_item_photos_to_resource.php')->up($conn);
$count   = static fn (string $sql): int => (int) $admin->query($sql)->fetch_row()[0];

// The old table as 7.0 found it, and a site where both tables count from 1.
$admin->query("DELETE FROM {$p}t_resource");
$admin->query("CREATE TABLE {$p}t_item_resource (pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT, fk_i_item_id INT UNSIGNED NOT NULL,"
    . " s_name VARCHAR(60) NULL, s_extension VARCHAR(10) NULL, s_content_type VARCHAR(40) NULL, s_path VARCHAR(250) NULL,"
    . " s_storage VARCHAR(30) NOT NULL DEFAULT 'local', PRIMARY KEY (pk_i_id))");
seed_country($admin);
seed_region($admin);
$category = seed_category($admin);
$owner    = seed_user($admin, 'merge', 'merge@example.test');
$itemA    = seed_item($admin, $category, $owner, 'Listing A');
$itemB    = seed_item($admin, $category, $owner, 'Listing B');
$admin->query("UPDATE {$p}t_item SET dt_pub_date = '2026-05-05 10:00:00', dt_first_pub_date = '2026-01-01 09:00:00' WHERE pk_i_id = $itemA");
$admin->query("UPDATE {$p}t_item SET dt_pub_date = '2026-03-03 10:00:00', dt_first_pub_date = NULL WHERE pk_i_id = $itemB");

$root = 'oc-content/uploads/merge-test/';
$file = static function (string $dir, string $base, string $body) use ($root): void {
    @mkdir(ABS_PATH . $root . $dir, 0777, true);
    file_put_contents(ABS_PATH . $root . $dir . $base . '.jpg', $body);
    file_put_contents(ABS_PATH . $root . $dir . $base . '_thumbnail.jpg', $body . '-thumb');
};
foreach (array(1 => $itemA, 2 => $itemA, 3 => $itemB, 4 => $itemB) as $id => $item) {
    $admin->query("INSERT INTO {$p}t_item_resource VALUES ($id, $item, 'code$id', 'jpg', 'image/jpeg', '{$root}item/', 'local')");
    $file('item/', (string) $id, "photo-$id");
}
// id 2 and 3 clash with listing photos; id 9 does not.
$resources = array(2 => array('user', $owner, 'user/'), 3 => array('setting', 0, 'setting/'), 9 => array('library', 0, 'library/'));
foreach ($resources as $id => [$type, $ownerId, $dir]) {
    $admin->query("INSERT INTO {$p}t_resource (pk_i_id, s_owner_type, i_owner_id, s_name, s_extension, s_content_type, s_path, dt_created)"
        . " VALUES ($id, '$type', $ownerId, 'r$id', 'jpg', 'image/jpeg', '{$root}$dir', NOW())");
    $file($dir, (string) $id, "$type-$id");
}

$migrate();
$migrate();

$row  = static fn (string $type, int $ownerId) => $admin->query("SELECT * FROM {$p}t_resource WHERE s_owner_type = '$type' AND i_owner_id = $ownerId")->fetch_assoc();
$read = static fn (?array $r, string $variant = ''): ?string => $r === null ? null : (@file_get_contents(ResourceLocator::localPath($r, $variant)) ?: null);

harness_section('listing photos');
pin('every photo moved with its id, owner and code', array('1:' . $itemA . ':code1', '2:' . $itemA . ':code2', '3:' . $itemB . ':code3', '4:' . $itemB . ':code4'), array_map(
    static fn (array $r): string => $r[0] . ':' . $r[1] . ':' . $r[2],
    $admin->query("SELECT pk_i_id, i_owner_id, s_name FROM {$p}t_resource WHERE s_owner_type = 'item' ORDER BY pk_i_id")->fetch_all()
));
pin('ItemResource reads them in the old row shape', array(
    'pk_i_id' => '3', 'fk_i_item_id' => (string) $itemB, 's_name' => 'code3', 's_extension' => 'jpg',
    's_content_type' => 'image/jpeg', 's_path' => $root . 'item/', 's_storage' => 'local',
), ItemResource::getInstance()->findByPrimaryKey(3));
pin('each photo still opens its own file', array('photo-1', 'photo-2', 'photo-3', 'photo-4-thumb'), array(
    $read(ItemResource::getInstance()->findByPrimaryKey(1)), $read(ItemResource::getInstance()->findByPrimaryKey(2)),
    $read(ItemResource::getInstance()->findByPrimaryKey(3)), $read(ItemResource::getInstance()->findByPrimaryKey(4), '_thumbnail'),
));
pin('a listing lists only its own photos, in upload order', array('3', '4'), array_column(ItemResource::getInstance()->getAllResourcesFromItem($itemB), 'pk_i_id'));
pin('photo rows date from their listing', 0, $count("SELECT COUNT(*) FROM {$p}t_resource WHERE s_owner_type = 'item' AND dt_created IS NULL"));

harness_section('renumbered rows');
$user    = $row('user', $owner);
$setting = $row('setting', 0);
pin('a clashing row gets a new id past both tables and keeps the old one as its file name', array(true, '2', true, '3'), array(
    (int) $user['pk_i_id'] > 4, $user['s_base_name'], (int) $setting['pk_i_id'] > 4, $setting['s_base_name'],
));
pin('their files and URLs are the ones they had', array('user-2', 'setting-3-thumb', true), array(
    $read($user), $read($setting, '_thumbnail'), str_ends_with(osc_get_resource_url($user), $root . 'user/2.jpg'),
));
pin('a row that did not clash is untouched', array('9', null, 'library-9'), array(
    (string) $row('library', 0)['pk_i_id'], $row('library', 0)['s_base_name'], $read($row('library', 0)),
));
pin('an old id still finds its row for the same owner type, and never a listing photo', array((int) $user['pk_i_id'], (int) $user['pk_i_id'], null, null), array(
    (int) ((new Resource())->findOwned('user', 2)['pk_i_id'] ?? 0),
    (int) ((new Resource())->findOwned('user', (int) $user['pk_i_id'])['pk_i_id'] ?? 0),
    (new Resource())->findOwned('setting', 2),
    (new Resource())->findOwned('library', 1),
));
pin('a settings preference holding the old id still shows the logo', true, str_ends_with(\mindstellar\settings\SettingsImage::url(3), $root . 'setting/3.jpg'));
pin('ItemResource never reaches another owner type', array(false, 0), array(
    ItemResource::getInstance()->findByPrimaryKey((int) $user['pk_i_id']),
    ItemResource::getInstance()->deleteResourcesIds(array((int) $user['pk_i_id'], (int) $setting['pk_i_id'], 9)),
));

harness_section('after the move');
pin('the old table is gone and a second run changed nothing', array(0, 7), array(
    $count("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$p}t_item_resource'"),
    $count("SELECT COUNT(*) FROM {$p}t_resource"),
));
pin('osc_item_cover_urls gives each listing its first image in one query', array(
    $itemA => osc_base_url() . $root . 'item/1_thumbnail.jpg',
    $itemB => osc_base_url() . $root . 'item/3_thumbnail.jpg',
), osc_item_cover_urls(array($itemA, $itemB, 999)));
$next = ItemResource::getInstance()->insertGetId(array('fk_i_item_id' => $itemA));
pin('a new photo takes an id after every existing row', true, $next > (int) max($user['pk_i_id'], $setting['pk_i_id']));

pin('photo rows date from their listing\'s first publication', 0, $count(
    "SELECT COUNT(*) FROM {$p}t_resource r JOIN {$p}t_item i ON i.pk_i_id = r.i_owner_id"
    . " WHERE r.s_owner_type = 'item' AND r.pk_i_id <= 4 AND r.dt_created <> COALESCE(i.dt_first_pub_date, i.dt_pub_date)"
));

harness_section('a job queued before the move');
$updateStorage = new ReflectionMethod(\mindstellar\storage\StorageJobs::class, 'updateStorage');
$updateStorage->setAccessible(true);
$updateStorage->invoke(null, array('pk_i_id' => 2, 's_owner_type' => 'user', 'i_owner_id' => $owner), 's3');
pin('a job holding a renumbered row\'s old id changes that row, not the listing photo that now has the id', array('s3', 'local'), array(
    $row('user', $owner)['s_storage'], ItemResource::getInstance()->findByPrimaryKey(2)['s_storage'],
));
$photoOnly = (new ReflectionClassConstant(\mindstellar\api\http\RowVersions::class, 'PHOTO'))->getValue();
pin('a photo version is never read from a row of another owner type', array(0, 1), array(
    count(\mindstellar\database\RowHashQuery::hashes(array(array('t_resource', 'pk_i_id', $photoOnly)), (int) $user['pk_i_id'], null, false)),
    count(\mindstellar\database\RowHashQuery::hashes(array(array('t_resource', 'pk_i_id', $photoOnly)), 1, null, false)),
));

harness_section('an upgrade that stopped half way');
$itemC = seed_item($admin, $category, $owner, 'Listing C');
$admin->query("CREATE TABLE {$p}t_item_resource (pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT, fk_i_item_id INT UNSIGNED NOT NULL,"
    . " s_name VARCHAR(60) NULL, s_extension VARCHAR(10) NULL, s_content_type VARCHAR(40) NULL, s_path VARCHAR(250) NULL,"
    . " s_storage VARCHAR(30) NOT NULL DEFAULT 'local', PRIMARY KEY (pk_i_id))");
// Photo 4 already moved; a plugin's own 'item' row holds id 30 for another owner; 30 is a PDF before the image 31.
$admin->query("INSERT INTO {$p}t_item_resource VALUES (4, $itemB, 'code4', 'jpg', 'image/jpeg', '{$root}item/', 'local'),"
    . " (30, $itemC, 'code30', 'pdf', 'application/pdf', '{$root}item/', 'local'), (31, $itemC, 'code31', 'jpg', 'image/jpeg', '{$root}item/', 'local'),"
    . " (32, $itemC, 'code32', 'jpg', 'image/jpeg', '{$root}item/', 'local')");
$admin->query("INSERT INTO {$p}t_resource (pk_i_id, s_owner_type, i_owner_id, s_name, s_extension, s_content_type, s_path, dt_created)"
    . " VALUES (30, 'item', 999, 'plugin', 'jpg', 'image/jpeg', '{$root}plugin/', NOW())");
$migrate();
pin('a photo already moved stays as it was', array('4', (string) $itemB, null), array_values(
    $admin->query("SELECT pk_i_id, i_owner_id, s_base_name FROM {$p}t_resource WHERE pk_i_id = 4")->fetch_assoc()
));
pin('another row holding a photo id moves aside, even with the item owner type', array('30', (string) $itemC), array(
    (string) $admin->query("SELECT s_base_name FROM {$p}t_resource WHERE i_owner_id = 999")->fetch_row()[0],
    (string) $admin->query("SELECT i_owner_id FROM {$p}t_resource WHERE pk_i_id = 30")->fetch_row()[0],
));
$snapshot = static fn (): string => md5(json_encode($admin->query("SELECT * FROM {$p}t_resource ORDER BY pk_i_id")->fetch_all()));
$before   = $snapshot();
$admin->query("CREATE TABLE {$p}t_item_resource (pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT, fk_i_item_id INT UNSIGNED NOT NULL,"
    . " s_name VARCHAR(60) NULL, s_extension VARCHAR(10) NULL, s_content_type VARCHAR(40) NULL, s_path VARCHAR(250) NULL,"
    . " s_storage VARCHAR(30) NOT NULL DEFAULT 'local', PRIMARY KEY (pk_i_id))");
$admin->query("INSERT INTO {$p}t_item_resource SELECT pk_i_id, i_owner_id, s_name, s_extension, s_content_type, s_path, s_storage"
    . " FROM {$p}t_resource WHERE s_owner_type = 'item'");
$migrate();
$migrate();
pin('running it again over photos already moved changes no row and drops the old table', array($before, 0), array(
    $snapshot(),
    $count("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$p}t_item_resource'"),
));
pin('the cover is the first image, not the first file', array($itemC => osc_base_url() . $root . 'item/31_thumbnail.jpg'), osc_item_cover_urls(array($itemC)));

$admin->query("DELETE FROM {$p}t_resource");
(new \mindstellar\utility\FileSystem())->remove(ABS_PATH . $root);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
