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
 * Who may edit a listing from the public site: the edit-form ajax call gives stored custom-field
 * values only to the owner or the secret holder, and a guest secret is compared case-sensitively.
 *
 * Usage:  php tests/models/item-edit-access.php          (standalone, own scratch database)
 *         php tests/run-models.php item-edit-access      (as part of the suite)
 */

require_once __DIR__ . '/../lib/scratchdb.php';
require_once __DIR__ . '/../lib/harness.php';

$admin = scratchdb_session('osc_models_item_edit_access');

require_once __DIR__ . '/../lib/action-standins.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/security/ItemAccess.php';

use mindstellar\security\ItemAccess;

seed_locale($admin);
seed_country($admin);
seed_currency($admin);
$cat   = seed_category($admin, 'Edit access');
$owner = seed_user($admin, 'editowner', 'editowner@example.test');

$fieldId = seed_exec(
    $admin,
    'INSERT INTO ' . DB_TABLE_PREFIX . "t_meta_fields (s_name, e_type, b_required, b_searchable, s_slug, i_position, s_meta)
     VALUES ('Private', 'TEXT', 0, 0, 'private', 0, '')",
    '',
    array()
);
seed_exec(
    $admin,
    'INSERT INTO ' . DB_TABLE_PREFIX . 't_meta_categories (fk_i_category_id, fk_i_field_id) VALUES (?, ?)',
    'ii',
    array($cat, $fieldId)
);

// Hidden: not yet validated.
$guestItem = seed_item($admin, $cat, null, 'Hidden guest', 10.0, 0, 1);
$userItem  = seed_item($admin, $cat, $owner, 'Hidden owned', 10.0, 0, 1);
$secret    = (string)$admin->query('SELECT s_secret FROM ' . DB_TABLE_PREFIX . 't_item WHERE pk_i_id = ' . $guestItem)
    ->fetch_row()[0];
foreach (array($guestItem, $userItem) as $id) {
    seed_exec(
        $admin,
        'INSERT INTO ' . DB_TABLE_PREFIX . 't_item_meta (fk_i_item_id, fk_i_field_id, s_value) VALUES (?, ?, ?)',
        'iis',
        array($id, $fieldId, 'secret-value-' . $id)
    );
}

// What the edit-form ajax call renders for the item id it ends up with.
$storedValue = static function (int $itemId) use ($cat): string {
    $fields = Field::newInstance()->findByCategoryItem($cat, $itemId);

    return (string)($fields[0]['s_value'] ?? '');
};
$ajaxItemId = static function (int $itemId, $userId, string $secret): int {
    return ItemAccess::manageable($itemId, $userId, false, $secret) === array() ? 0 : $itemId;
};

harness_section('edit form values for a hidden guest listing');
pin('anonymous, no secret: no stored values', '', $storedValue($ajaxItemId($guestItem, null, '')));
pin('anonymous, wrong secret: no stored values', '', $storedValue($ajaxItemId($guestItem, null, 'nope')));
pin(
    'anonymous, secret in the wrong case: no stored values',
    '',
    $storedValue($ajaxItemId($guestItem, null, strtoupper($secret)))
);
pin(
    'the secret holder gets the stored values',
    'secret-value-' . $guestItem,
    $storedValue($ajaxItemId($guestItem, null, $secret))
);

harness_section('edit form values for a hidden registered listing');
pin('anonymous: no stored values', '', $storedValue($ajaxItemId($userItem, null, '')));
pin('another user: no stored values', '', $storedValue($ajaxItemId($userItem, $owner + 1, '')));
pin('the secret does not open a registered listing', '', $storedValue($ajaxItemId($userItem, null, $secret)));
pin('the owner gets the stored values', 'secret-value-' . $userItem, $storedValue($ajaxItemId($userItem, $owner, '')));
pin('a missing listing yields nothing', array(), ItemAccess::manageable(999999, $owner, false, ''));

harness_section('the controllers use it');
$ajax = file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/CWebAjax.php');
check(
    'the item_edit hook checks access before it passes the id on',
    (bool)preg_match("/getParamInt\\('itemId'\\).*?ItemAccess::manageable\\(.*?\\\$itemId = 0;/s", $ajax)
);

$web = file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/CWebItem.php');
check('no guest secret is matched in SQL', strpos($web, 'i.s_secret = %s') === false);
check('the edit id is read as an integer', strpos($web, "(int)Params::getParam('id')") === false);

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
