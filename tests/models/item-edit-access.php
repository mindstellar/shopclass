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

use mindstellar\auth\Actor;
use mindstellar\listing\ListingPolicy;
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
    $fields = Field::getInstance()->findByCategoryItem($cat, $itemId);

    return (string)($fields[0]['s_value'] ?? '');
};
$ajaxItemId = static function (int $itemId, $userId, string $secret): int {
    return ListingPolicy::manageable($itemId, new Actor($userId, null, '', $secret)) === null ? 0 : $itemId;
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
pin('a missing listing yields nothing', null, ListingPolicy::manageable(999999, Actor::user($owner)));

harness_section('the deprecated ItemAccess::manageable() forwards to the policy');
pin('the secret holder gets the listing', $guestItem, (int)(ItemAccess::manageable($guestItem, null, false, $secret)['pk_i_id'] ?? 0));
pin('a wrong-case secret gets nothing', array(), ItemAccess::manageable($guestItem, null, false, strtoupper($secret)));
pin('a missing listing yields an empty array', array(), ItemAccess::manageable(999999, $owner, false, ''));

harness_section('the public edit screen');

/** Thrown in place of the exit() a real redirect ends the request with. */
class EditRedirect extends RuntimeException
{
}

/** The real controller, with the view and the redirect recorded instead of taken. */
class TestWebItem extends CWebItem
{
    public function doView($file)
    {
        $GLOBALS['editView'] = $file;
    }

    public function redirectTo($url, $code = null)
    {
        throw new EditRedirect((string) $url);
    }
}
if (!defined('OSC_DEBUG')) {
    define('OSC_DEBUG', false);
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hMessages.php';
if (!function_exists('_m')) {
    function _m($text)
    {
        return $text;
    }
}
if (!function_exists('osc_locate_template')) {
    function osc_locate_template($names, $fallback = '')
    {
        return $fallback;
    }
}

/** Open the edit screen as a guest; the listing id it shows, or 0 when refused. */
$openEdit = static function (string $id, string $secret): int {
    $_GET = $_REQUEST = array('page' => 'item', 'action' => 'item_edit', 'id' => $id, 'secret' => $secret);
    $_POST = array();
    Params::init();
    View::getInstance()->_erase('item');
    $GLOBALS['editView'] = null;
    $web = (new ReflectionClass('TestWebItem'))->newInstanceWithoutConstructor();
    foreach (array('action' => 'item_edit', 'itemManager' => Item::getInstance(), 'userId' => null, 'user' => null) as $name => $value) {
        $prop = new ReflectionProperty('CWebItem', $name);
        $prop->setAccessible(true);
        $prop->setValue($web, $value);
    }
    try {
        $web->doModel();
    } catch (EditRedirect $e) {
        return 0;
    }
    $item = View::getInstance()->_get('item');

    return $GLOBALS['editView'] !== null ? (int) ($item['pk_i_id'] ?? 0) : 0;
};
pin('the secret holder gets the form', $guestItem, $openEdit((string) $guestItem, $secret));
pin('a secret in the wrong case is refused', 0, $openEdit((string) $guestItem, strtoupper($secret)));
pin('a fractional id opens the listing the owner check passed', $guestItem, $openEdit($guestItem . '.9', $secret));

View::getInstance()->_erase('item');

if (!defined('MODELS_RUNNER')) {
    exit(harness_result());
}
