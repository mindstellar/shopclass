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
 * ItemAccess, the owner check for deleting a listing's photo from the public site, and the
 * two places that use it (CWebItem deleteResources, CWebAjax delete_image).
 *
 * The old check only refused a signed-in stranger or a guest listing with a wrong secret. A
 * signed-out visitor on a registered user's listing matched neither branch, so the photo
 * code alone was enough to delete it.
 *
 * DB-free.  Usage: php tests/item-access.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/../oc-includes/osclass/classes/security/ItemAccess.php';

use mindstellar\security\ItemAccess;

$registered = array('pk_i_id' => '10', 'fk_i_user_id' => '7', 's_secret' => 'regsecret');
$guest      = array('pk_i_id' => '11', 'fk_i_user_id' => null, 's_secret' => 'guestsecret');
$photo      = array('pk_i_id' => '50', 'fk_i_item_id' => '10', 's_name' => 'abc123');
$guestPhoto = array('pk_i_id' => '51', 'fk_i_item_id' => '11', 's_name' => 'def456');

harness_section('who may delete a photo');
check('the owner, signed in', ItemAccess::canManage($registered, 7, false, ''));
check('the owner, id as a string', ItemAccess::canManage($registered, '7', false, ''));
check('another signed-in user is refused', !ItemAccess::canManage($registered, 8, false, ''));
check('a signed-out visitor on a registered listing is refused', !ItemAccess::canManage($registered, null, false, ''));
check(
    'a signed-out visitor on a registered listing is refused even with its secret',
    !ItemAccess::canManage($registered, null, false, 'regsecret')
);
check('an admin', ItemAccess::canManage($registered, null, true, ''));
check('a guest listing with the right secret', ItemAccess::canManage($guest, null, false, 'guestsecret'));
check('a guest listing with a wrong secret is refused', !ItemAccess::canManage($guest, null, false, 'nope'));
check('a guest listing with no secret is refused', !ItemAccess::canManage($guest, null, false, ''));
check(
    'a guest listing with an empty stored secret and none sent is refused',
    !ItemAccess::canManage(array('fk_i_user_id' => null, 's_secret' => ''), null, false, '')
);
check('a signed-in stranger on a guest listing needs the secret', !ItemAccess::canManage($guest, 8, false, ''));

harness_section('the photo belongs to the item');
check('its own photo with the right code', ItemAccess::isPhotoOf($photo, $registered, 'abc123'));
check('a photo of another item is refused', !ItemAccess::isPhotoOf($guestPhoto, $registered, 'def456'));
check('a wrong code is refused', !ItemAccess::isPhotoOf($photo, $registered, 'zzz'));
check('an empty code is refused', !ItemAccess::isPhotoOf($photo, $registered, ''));
check('a missing photo is refused', !ItemAccess::isPhotoOf(false, $registered, 'abc123'));
check('an empty row is refused', !ItemAccess::isPhotoOf(array(), $registered, 'abc123'));

harness_section('both delete paths use it');
$root = __DIR__ . '/../oc-includes/osclass/classes/controller/';
$web  = file_get_contents($root . 'CWebItem.php');
$ajax = file_get_contents($root . 'CWebAjax.php');
preg_match("/case 'deleteResources':(.*?)case 'mark':/s", $web, $w);
preg_match("/case 'delete_image':(.*?)case 'alerts':/s", $ajax, $a);
foreach (array('CWebItem deleteResources' => $w[1] ?? '', 'CWebAjax delete_image' => $a[1] ?? '') as $name => $body) {
    $manage = strpos($body, 'ItemAccess::canManage(');
    $photoOf = strpos($body, 'ItemAccess::isPhotoOf(');
    $delete = strpos($body, 'osc_deleteResource(');
    check("$name was parsed", $body !== '');
    check("$name checks the owner before deleting", $manage !== false && $delete !== false && $manage < $delete);
    check("$name checks the photo is the item's before deleting", $photoOf !== false && $photoOf < $delete);
    check("$name no longer compares the secret with !=", strpos($body, '$secret !=') === false);
}

exit(harness_result());
