<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Old names kept for plugins and the hooks below; the code is in mindstellar\mail\CoreEmails.
// Edit the class, not these.

/**
 * Email the confirmation link for a newly created search alert.
 *
 * @see \mindstellar\mail\CoreEmails::alertValidation()
 */
function fn_email_alert_validation($alert, $email, $secret)
{
    \mindstellar\mail\CoreEmails::alertValidation($alert, $email, $secret);
}

osc_add_hook('hook_email_alert_validation', 'fn_email_alert_validation');

/**
 * The editable page a digest is written from, in the site's language.
 *
 * @see \mindstellar\mail\CoreEmails::alertTemplate()
 */
function _alert_email_template($internalName)
{
    return \mindstellar\mail\CoreEmails::alertTemplate($internalName);
}

/**
 * Who a digest goes to, and the placeholder values for their copy of it.
 *
 * @see \mindstellar\mail\CoreEmails::alertRecipient()
 */
function _alert_email_recipient($user, $ads, $s_search)
{
    return \mindstellar\mail\CoreEmails::alertRecipient($user, $ads, $s_search);
}

/**
 * Send a finished digest. The plain-text part is the HTML one, as it always was.
 *
 * @see \mindstellar\mail\CoreEmails::alertDeliver()
 */
function _alert_email_deliver($user, $title, $body)
{
    \mindstellar\mail\CoreEmails::alertDeliver($user, $title, $body);
}

/**
 * Email one subscriber the hourly digest of new listings matching their saved search.
 *
 * @see \mindstellar\mail\CoreEmails::alertHourly()
 */
function fn_alert_email_hourly($user, $ads, $s_search, $items, $totalItems)
{
    \mindstellar\mail\CoreEmails::alertHourly($user, $ads, $s_search, $items, $totalItems);
}

osc_add_hook('hook_alert_email_hourly', 'fn_alert_email_hourly');

/**
 * Email one subscriber the daily digest of new listings matching their saved search.
 *
 * @see \mindstellar\mail\CoreEmails::alertDaily()
 */
function fn_alert_email_daily($user, $ads, $s_search, $items, $totalItems)
{
    \mindstellar\mail\CoreEmails::alertDaily($user, $ads, $s_search, $items, $totalItems);
}

osc_add_hook('hook_alert_email_daily', 'fn_alert_email_daily');

/**
 * Email one subscriber the weekly digest of new listings matching their saved search.
 *
 * @see \mindstellar\mail\CoreEmails::alertWeekly()
 */
function fn_alert_email_weekly($user, $ads, $s_search, $items, $totalItems)
{
    \mindstellar\mail\CoreEmails::alertWeekly($user, $ads, $s_search, $items, $totalItems);
}

osc_add_hook('hook_alert_email_weekly', 'fn_alert_email_weekly');

/**
 * Tell a comment author their comment has been approved.
 *
 * @see \mindstellar\mail\CoreEmails::commentValidated()
 */
function fn_email_comment_validated($aComment)
{
    \mindstellar\mail\CoreEmails::commentValidated($aComment);
}

osc_add_hook('hook_email_comment_validated', 'fn_email_comment_validated');

/**
 * Confirm to a guest poster that their listing was published, with its edit link.
 *
 * @see \mindstellar\mail\CoreEmails::newItemNonRegisterUser()
 */
function fn_email_new_item_non_register_user($item)
{
    \mindstellar\mail\CoreEmails::newItemNonRegisterUser($item);
}

osc_add_hook('hook_email_new_item_non_register_user', 'fn_email_new_item_non_register_user');

/**
 * Email a user the link that lets them set a new password.
 *
 * @see \mindstellar\mail\CoreEmails::userForgotPassword()
 */
function fn_email_user_forgot_password($user, $password_url)
{
    \mindstellar\mail\CoreEmails::userForgotPassword($user, $password_url);
}

osc_add_hook('hook_email_user_forgot_password', 'fn_email_user_forgot_password');

/**
 * Send the welcome email that follows a successful registration.
 *
 * @see \mindstellar\mail\CoreEmails::userRegistration()
 */
function fn_email_user_registration($user)
{
    \mindstellar\mail\CoreEmails::userRegistration($user);
}

osc_add_hook('hook_email_user_registration', 'fn_email_user_registration');

/**
 * Email the confirmation link to an address a user is changing to.
 *
 * @see \mindstellar\mail\CoreEmails::newEmail()
 */
function fn_email_new_email($new_email, $validation_url)
{
    \mindstellar\mail\CoreEmails::newEmail($new_email, $validation_url);
}

osc_add_hook('hook_email_new_email', 'fn_email_new_email');

/**
 * Email a new account its activation link.
 *
 * @see \mindstellar\mail\CoreEmails::userValidation()
 */
function fn_email_user_validation($user, $input)
{
    \mindstellar\mail\CoreEmails::userValidation($user, $input);
}

osc_add_hook('hook_email_user_validation', 'fn_email_user_validation');

/**
 * Forward a listing to the address given in the "send to a friend" form.
 *
 * @see \mindstellar\mail\CoreEmails::sendFriend()
 */
function fn_email_send_friend($aItem)
{
    \mindstellar\mail\CoreEmails::sendFriend($aItem);
}

osc_add_hook('hook_email_send_friend', 'fn_email_send_friend');

/**
 * Placeholder lists for osc_mailBeauty(): one for the subject and one for the body. Text a
 * visitor typed is escaped in the body, with its line breaks kept, and has its tags removed in
 * the subject.
 *
 * @see \mindstellar\mail\CoreEmails::words()
 */
function _osc_mail_words(array $values, array $visitor): array
{
    return \mindstellar\mail\CoreEmails::words($values, $visitor);
}

/**
 * Deliver a listing contact-form enquiry to the seller.
 *
 * @see \mindstellar\mail\CoreEmails::itemInquiry()
 */
function fn_email_item_inquiry($aItem)
{
    \mindstellar\mail\CoreEmails::itemInquiry($aItem);
}

osc_add_hook('hook_email_item_inquiry', 'fn_email_item_inquiry');

/**
 * Notify every administrator that a comment was posted on a listing.
 *
 * @see \mindstellar\mail\CoreEmails::newCommentAdmin()
 */
function fn_email_new_comment_admin($aItem)
{
    \mindstellar\mail\CoreEmails::newCommentAdmin($aItem);
}

osc_add_hook('hook_email_new_comment_admin', 'fn_email_new_comment_admin');

/**
 * Email a registered poster the activation link for their new listing.
 *
 * @see \mindstellar\mail\CoreEmails::itemValidation()
 */
function fn_email_item_validation($item)
{
    \mindstellar\mail\CoreEmails::itemValidation($item);
}

osc_add_hook('hook_email_item_validation', 'fn_email_item_validation');

/**
 * Notify every administrator that a listing was posted.
 *
 * @see \mindstellar\mail\CoreEmails::adminNewItem()
 */
function fn_email_admin_new_item($item)
{
    \mindstellar\mail\CoreEmails::adminNewItem($item);
}

osc_add_hook('hook_email_admin_new_item', 'fn_email_admin_new_item');

/**
 * Email a guest poster the activation link for their new listing.
 *
 * @see \mindstellar\mail\CoreEmails::itemValidationNonRegisterUser()
 */
function fn_email_item_validation_non_register_user($item)
{
    \mindstellar\mail\CoreEmails::itemValidationNonRegisterUser($item);
}

osc_add_hook('hook_email_item_validation_non_register_user', 'fn_email_item_validation_non_register_user');

/**
 * Notify every administrator that a user account was created.
 *
 * @see \mindstellar\mail\CoreEmails::adminNewUser()
 */
function fn_email_admin_new_user($user)
{
    \mindstellar\mail\CoreEmails::adminNewUser($user);
}

osc_add_hook('hook_email_admin_new_user', 'fn_email_admin_new_user');

/**
 * Deliver a message sent from a user's public profile contact form.
 *
 * @see \mindstellar\mail\CoreEmails::contactUser()
 */
function fn_email_contact_user($id, $yourEmail, $yourName, $phoneNumber, $message)
{
    \mindstellar\mail\CoreEmails::contactUser($id, $yourEmail, $yourName, $phoneNumber, $message);
}

osc_add_hook('hook_email_contact_user', 'fn_email_contact_user');

/**
 * Notify a listing's owner that a comment was posted on it.
 *
 * @see \mindstellar\mail\CoreEmails::newCommentUser()
 */
function fn_email_new_comment_user($aItem)
{
    \mindstellar\mail\CoreEmails::newCommentUser($aItem);
}

osc_add_hook('hook_email_new_comment_user', 'fn_email_new_comment_user');

/**
 * Email a newly created administrator their account credentials.
 *
 * @see \mindstellar\mail\CoreEmails::newAdmin()
 */
function fn_email_new_admin($data)
{
    \mindstellar\mail\CoreEmails::newAdmin($data);
}

osc_add_hook('hook_email_new_admin', 'fn_email_new_admin');

/**
 * Warn a listing's contact that the listing is about to expire.
 *
 * @see \mindstellar\mail\CoreEmails::warnExpiration()
 */
function fn_email_warn_expiration($aItem)
{
    \mindstellar\mail\CoreEmails::warnExpiration($aItem);
}

osc_add_hook('hook_email_warn_expiration', 'fn_email_warn_expiration');
osc_add_hook('billing_order_placed', array(\mindstellar\billing\Receipts::class, 'afterPlaced'));
osc_add_hook('billing_order_shipped', array(\mindstellar\billing\Receipts::class, 'afterShipped'));
osc_add_hook('billing_order_delivered', array(\mindstellar\billing\Receipts::class, 'afterDelivered'));
osc_add_hook('billing_order_cancelled', array(\mindstellar\billing\Receipts::class, 'afterCancelled'));
osc_add_hook('billing_order_refunded', array(\mindstellar\billing\Receipts::class, 'afterRefunded'));

/**
 * Report the outcome of an automatic core upgrade to every administrator.
 *
 * @see \mindstellar\mail\CoreEmails::autoUpgrade()
 */
function fn_email_auto_upgrade($result)
{
    \mindstellar\mail\CoreEmails::autoUpgrade($result);
}

osc_add_hook('after_auto_upgrade', 'fn_email_auto_upgrade', 10);

/**
 * The address outgoing mail is sent from, falling back to the site contact address.
 *
 * @see \mindstellar\mail\CoreEmails::fromAddress()
 */
function _osc_from_email_aux()
{
    return \mindstellar\mail\CoreEmails::fromAddress();
}

/* file end: ./oc-includes/osclass/emails.php */
