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
 * ItemAccess: who may see a hidden listing on its public page, and who may delete a
 * listing's photo from the public site, with the controllers that use it.
 *
 * The item page hid listings that were not validated or were disabled, but showed spam
 * listings to everyone. A spam listing is now hidden like a disabled one, and the contact and
 * send-to-friend pages refuse every hidden listing the same way.
 *
 * The old check only refused a signed-in stranger or a guest listing with a wrong secret. A
 * signed-out visitor on a registered user's listing matched neither branch, so the photo
 * code alone was enough to delete it.
 *
 * DB-free.  Usage: php tests/item-access.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/../oc-includes/vendor/autoload.php';

use mindstellar\auth\Actor;
use mindstellar\listing\ListingPolicy;
use mindstellar\security\ItemAccess;

$registered = array('pk_i_id' => '10', 'fk_i_user_id' => '7', 's_secret' => 'regsecret');
$guest      = array('pk_i_id' => '11', 'fk_i_user_id' => null, 's_secret' => 'guestsecret');
$photo      = array('pk_i_id' => '50', 'fk_i_item_id' => '10', 's_name' => 'abc123');
$guestPhoto = array('pk_i_id' => '51', 'fk_i_item_id' => '11', 's_name' => 'def456');

$live  = array('fk_i_user_id' => '7', 'b_active' => 1, 'b_enabled' => 1, 'b_spam' => 0);
$spam  = array_merge($live, array('b_spam' => 1));
$off   = array_merge($live, array('b_enabled' => 0));
$unval = array_merge($live, array('b_active' => 0));

harness_section('who may see a listing');
check('a live listing is public', ItemAccess::canView($live, null, false));
check('a spam listing is hidden', ItemAccess::isHidden($spam));
check('a spam listing: the public gets nothing', !ItemAccess::canView($spam, null, false));
check('a spam listing: another user gets nothing', !ItemAccess::canView($spam, 8, false));
check('a spam listing: the owner can view it', ItemAccess::canView($spam, 7, false));
check('a spam listing: an admin can view it', ItemAccess::canView($spam, null, true));
check('a disabled listing: the public gets nothing', !ItemAccess::canView($off, null, false));
check('a disabled listing: the owner can view it', ItemAccess::canView($off, 7, false));
check('an unvalidated listing: the public gets nothing', !ItemAccess::canView($unval, null, false));
check('an unvalidated listing: the owner can view it', ItemAccess::canView($unval, '7', false));
check(
    'a guest listing that is spam: nobody signed out sees it',
    !ItemAccess::canView(array_merge($spam, array('fk_i_user_id' => null)), null, false)
);

harness_section('the item page uses it');
$webSrc = file_get_contents(__DIR__ . '/../oc-includes/osclass/classes/controller/CWebItem.php');
preg_match("/\n            default:(.*?)osc_run_hook\('show_item'/s", $webSrc, $v);
$view  = $v[1] ?? '';
$gate  = strpos($view, 'ListingPolicy::canView(');
$after = $gate === false ? '' : substr($view, $gate, 200);
check('the view was parsed', $view !== '');
check('it gates on canView', $gate !== false);
check('a refused visitor gets the 404', strpos($after, '$this->do404()') !== false);
check('the gate runs before the view is counted', $gate !== false && $gate < (int)strpos($view, 'ItemStats'));

harness_section('contact and send-to-friend use it');
preg_match('/private function notFoundIfHidden.*?\n    }/s', $webSrc, $h);
$helper = $h[0] ?? '';
check('the helper checks canView', strpos($helper, 'ListingPolicy::canView(') !== false);
check('the helper sends the 404', strpos($helper, '$this->do404()') !== false);
foreach (array('send_friend', 'send_friend_post', 'contact', 'contact_post') as $action) {
    preg_match("/case '$action':(.*?)\n            case '/s", $webSrc, $c);
    $body = $c[1] ?? '';
    $find = strpos($body, 'findByPrimaryKey(');
    $gate = strpos($body, '$this->notFoundIfHidden($item)');
    $view = strpos($body, "_exportVariableToView('item'");
    check("$action was parsed", $body !== '');
    check("$action 404s a hidden listing before using it", $find !== false && $gate !== false && $gate > $find && $gate < $view);
}

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

harness_section('ListingPolicy, which ItemAccess now answers through');
$expired = array_merge($live, array('b_premium' => 0, 'dt_expiration' => '2020-01-01 00:00:00'));
check('an expired listing is not hidden: its page still shows it', !ListingPolicy::isHidden($expired) && ListingPolicy::canView($expired, Actor::guest()));
check('the secret alone opens a guest listing', ListingPolicy::canManage($guest, Actor::guest('', 'guestsecret')));
check('the secret is held for any listing, as delete links carry it', ListingPolicy::holdsSecret($registered, Actor::guest('', 'regsecret')));
check('but it does not manage a registered listing', !ListingPolicy::canManage($registered, Actor::guest('', 'regsecret')));
check('admin rights with no admin signed in still count', ListingPolicy::canView($spam, Actor::admin(0)));

harness_section('both delete paths use it');
$root = __DIR__ . '/../oc-includes/osclass/classes/controller/';
$web  = file_get_contents($root . 'CWebItem.php');
$ajax = file_get_contents($root . 'CWebAjax.php');
preg_match("/case 'deleteResources':(.*?)case 'mark':/s", $web, $w);
preg_match("/case 'delete_image':(.*?)case 'alerts':/s", $ajax, $a);
foreach (array('CWebItem deleteResources' => $w[1] ?? '', 'CWebAjax delete_image' => $a[1] ?? '') as $name => $body) {
    $manage = strpos($body, 'ListingPolicy::canManage(');
    $photoOf = strpos($body, 'ListingPolicy::isPhotoOf(');
    $delete = strpos($body, 'PhotoService())->delete(');
    check("$name was parsed", $body !== '');
    check("$name checks the owner before deleting", $manage !== false && $delete !== false && $manage < $delete);
    check("$name checks the photo is the item's before deleting", $photoOf !== false && $photoOf < $delete);
    check("$name no longer compares the secret with !=", strpos($body, '$secret !=') === false);
}

exit(harness_result());
