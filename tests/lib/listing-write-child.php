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
 * One side of a race on a listing write, for tests/listing-write-race.php. Prints "ok" or
 * "threw <class>: <message>".
 *
 * Usage:  php tests/lib/listing-write-child.php <database> <mode> <listing id> <user id> <uploads dir>
 * Modes: delete, edit, activate, enable, spam, premium, photo, post, publish
 */

[, $database, $mode, $itemId, $userId, $uploads] = $argv;
define('UPLOADS_PATH', rtrim($uploads, '/') . '/');
require_once __DIR__ . '/scratchdb.php';
require_once __DIR__ . '/api-admin-kit.php';

$admin = scratchdb_bootstrap($database);
$admin->select_db($database);
api_admin_env();
osc_reset_preferences();
if (!function_exists('osc_base_path')) {
    function osc_base_path()
    {
        return UPLOADS_PATH;
    }
}
$_SERVER['REMOTE_ADDR'] = '192.0.2.81';
Params::init();

use mindstellar\auth\Actor;
use mindstellar\database\Db;
use mindstellar\listing\ListingInput;
use mindstellar\listing\ListingService;
use mindstellar\listing\PhotoService;

$itemId  = (int) $itemId;
$userId  = (int) $userId;
$service = new ListingService();
$form    = static fn (array $extra = []): array => $extra + [
    'catId'        => (string) $admin->query('SELECT fk_i_category_id FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id = ' . max(1, $itemId))->fetch_row()[0],
    'countryId'    => 'US',
    'country'      => 'United States',
    'region'       => 'Texas',
    'city'         => 'Austin',
    'address'      => '1 Main Street',
    'title'        => ['en_US' => 'Red hatchback'],
    'description'  => ['en_US' => 'A small red car, one owner, full service history.'],
    'price'        => '1500',
    'currency'     => 'USD',
    'contactName'  => 'Office',
    'contactEmail' => 'office@example.test',
    'contactPhone' => '5550199',
];

try {
    $actor = Actor::admin(1, '192.0.2.81');
    switch ($mode) {
        case 'delete':
            $secret = (string) $admin->query('SELECT s_secret FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id = ' . $itemId)->fetch_row()[0];
            $done   = $service->delete($itemId, $secret, $actor);
            break;
        case 'edit':
            $service->update(ListingInput::fromArray($form(['id' => (string) $itemId, 'ownerId' => (string) $userId, 'product' => ['sell' => '1', 'stock' => '7']]), $actor, false), $actor, false, false);
            $done = true;
            break;
        case 'activate':
            $done = Db::transaction(static fn () => $service->activate($itemId));
            break;
        case 'enable':
            $done = Db::transaction(static fn () => $service->enable($itemId));
            break;
        case 'spam':
            $done = Db::transaction(static fn () => $service->spam($itemId, true));
            break;
        case 'premium':
            $done = Db::transaction(static fn () => $service->premium($itemId, true, 5));
            break;
        case 'photo':
            $jpeg = $uploads . '/photo-' . getmypid() . '.jpg';
            $img  = imagecreatetruecolor(64, 48);
            imagefilledrectangle($img, 0, 0, 63, 47, imagecolorallocate($img, 200, 30, 30));
            imagejpeg($img, $jpeg, 80);
            $files = ['name' => [basename($jpeg)], 'type' => ['image/*'], 'tmp_name' => [$jpeg], 'error' => [UPLOAD_ERR_OK], 'size' => [(int) filesize($jpeg)]];
            $done  = (new PhotoService())->add($itemId, $files, Actor::user($userId)) !== [];
            break;
        case 'publish':
            $done = $service->publish($itemId, Actor::user($userId));
            break;
        case 'post':
            $done = $service->create(ListingInput::fromArray($form(['ownerId' => (string) $userId, 'catId' => (string) $itemId]), Actor::user($userId), true), Actor::user($userId))->id() > 0;
            break;
        default:
            throw new InvalidArgumentException('mode ' . $mode);
    }
    echo $done ? "ok\n" : "false\n";
} catch (Throwable $e) {
    echo 'threw ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
