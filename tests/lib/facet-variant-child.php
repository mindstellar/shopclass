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
 * One side of a race on a listing's option rows, for tests/facet-variant-writes.php. Saves the
 * rows Red/M and Green/S and prints "ok" or "threw <class>: <message>".
 *
 * Usage:  php tests/lib/facet-variant-child.php <database> <mode> <listing id>
 * Modes: edit (an admin edit through ListingService), save (VariantStore::saveSet() alone)
 */

[, $database, $mode, $itemId] = $argv;
require_once __DIR__ . '/scratchdb.php';
require_once __DIR__ . '/api-admin-kit.php';

$admin = scratchdb_bootstrap($database);
$admin->select_db($database);
api_admin_env();
osc_reset_preferences();
$_SERVER['REMOTE_ADDR'] = '192.0.2.83';
Params::init();

use mindstellar\auth\Actor;
use mindstellar\listing\ListingInput;
use mindstellar\listing\ListingService;
use mindstellar\listing\VariantStore;

$itemId = (int) $itemId;

try {
    switch ($mode) {
        case 'edit':
            $actor = Actor::admin(1, '192.0.2.83');
            $form  = [
                'id'           => (string) $itemId,
                'ownerId'      => '0',
                'catId'        => (string) $admin->query('SELECT fk_i_category_id FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id = ' . $itemId)->fetch_row()[0],
                'countryId'    => 'US',
                'country'      => 'United States',
                'region'       => 'Texas',
                'city'         => 'Austin',
                'title'        => ['en_US' => 'Cotton tee'],
                'description'  => ['en_US' => 'A plain cotton tee in two colours and sizes.'],
                'price'        => '15',
                'currency'     => 'USD',
                'contactName'  => 'Office',
                'contactEmail' => 'office@example.test',
                'contactPhone' => '5550199',
                'product'      => ['sell' => '1', 'hasOptions' => '1', 'optionNames' => ['Colour', 'Size'], 'variants' => [
                    ['options' => ['Red', 'M'], 'stock' => '3'],
                    ['options' => ['Green', 'S'], 'stock' => '2'],
                ]],
            ];
            (new ListingService())->update(ListingInput::fromArray($form, $actor, false), $actor, false, false);
            break;
        case 'save':
            VariantStore::saveSet($itemId, [
                ['options' => ['Colour' => 'Red', 'Size' => 'M'], 'fields' => ['i_stock' => 3]],
                ['options' => ['Colour' => 'Green', 'Size' => 'S'], 'fields' => ['i_stock' => 2]],
            ]);
            break;
        default:
            throw new InvalidArgumentException('mode ' . $mode);
    }
    echo "ok\n";
} catch (Throwable $e) {
    echo 'threw ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
