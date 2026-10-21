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
 * One side of a race on a custom field write, for tests/facet-field-writes.php. Prints "ok" or
 * "threw <class>: <message>".
 *
 * Usage:  php tests/lib/facet-field-child.php <database> <mode> <listing id> <user id> <field id> <value>
 * Modes: edit (a listing save carrying the value), replace (Field::replace() on its own),
 * build (FacetIndex::buildStep() over that one listing; the value is unused)
 */

[, $database, $mode, $itemId, $userId, $fieldId, $value] = $argv;
require_once __DIR__ . '/scratchdb.php';
require_once __DIR__ . '/api-admin-kit.php';

$admin = scratchdb_bootstrap($database);
$admin->select_db($database);
api_admin_env();
osc_reset_preferences();
$_SERVER['REMOTE_ADDR'] = '192.0.2.82';
Params::init();

use mindstellar\auth\Actor;
use mindstellar\listing\ListingInput;
use mindstellar\listing\ListingService;

$itemId = (int) $itemId;
$userId = (int) $userId;

try {
    if ($mode === 'replace') {
        $done = Field::getInstance()->replace($itemId, (int) $fieldId, $value);
    } elseif ($mode === 'build') {
        \mindstellar\search\FacetIndex::buildStep($itemId - 1, 1);
        $done = true;
    } else {
        $actor = Actor::admin(1, '192.0.2.82');
        $form  = [
            'id'           => (string) $itemId,
            'ownerId'      => (string) $userId,
            'catId'        => (string) $admin->query('SELECT fk_i_category_id FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id = ' . $itemId)->fetch_row()[0],
            'countryId'    => 'US',
            'country'      => 'United States',
            'region'       => 'Texas',
            'city'         => 'Austin',
            'address'      => '1 Main Street',
            'title'        => ['en_US' => 'Red hatchback'],
            'description'  => ['en_US' => 'A small red car, one owner, full service history.'],
            'price'        => '1500',
            'contactName'  => 'Office',
            'contactEmail' => 'office@example.test',
            'contactPhone' => '5550199',
            'meta'         => [(int) $fieldId => $value],
        ];
        (new ListingService())->update(ListingInput::fromArray($form, $actor, false), $actor, false, false);
        $done = true;
    }
    echo $done ? "ok\n" : "false\n";
} catch (Throwable $e) {
    echo 'threw ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
