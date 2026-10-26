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
 * The messaging markup core hands a theme: osc_messaging_link() for the header, and the shared
 * contact form's 'messaging' option (message only, no e-mail fields). Rendered with DB-free stubs.
 *
 * Usage: php tests/messaging-theme-parts.php
 */

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

$GLOBALS['state'] = array();

function osc_esc_html($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function _m($text)
{
    return $text;
}

function osc_messaging_enabled(): bool
{
    return $GLOBALS['state']['enabled'];
}

function osc_is_web_user_logged_in()
{
    return $GLOBALS['state']['user'];
}

function osc_is_shopper(): bool
{
    return $GLOBALS['state']['shopper'];
}

function osc_messaging_unread_count(): int
{
    return $GLOBALS['state']['unread'];
}

function osc_get_osclass_location()
{
    return $GLOBALS['state']['location'];
}

function osc_messaging_inbox_url(): string
{
    return 'https://example.test/?page=messages&a=1';
}

function osc_logged_user_name()
{
    return 'Sam <b>';
}

function osc_logged_user_email()
{
    return 'sam@example.test';
}

function osc_base_url($withIndex = false)
{
    return 'https://example.test/' . ($withIndex ? 'index.php' : '');
}

function osc_messaging_live_url(): string
{
    return '';
}

function osc_assets_url($path)
{
    return 'https://example.test/oc-includes/assets/' . $path;
}

function osc_asset_url_versioned($url)
{
    return $url . '?v=1';
}

function osc_gui_kept($key, $default = '')
{
    return $key === 'message_body' ? 'kept text' : $default;
}

function osc_get_int_preference($key, $default = 0, $min = null)
{
    return 2000;
}

function osc_captcha_enabled()
{
    return true;
}

function osc_show_captcha($context)
{
    echo '<div class="captcha" data-context="' . $context . '"></div>';
}

$source = harness_method_source(ABS_PATH . 'oc-includes/osclass/helpers/hUsers.php', 'osc_messaging_link');
check('osc_messaging_link is in hUsers.php', $source !== '');
eval($source);

$link = static function (array $state, array $args = array()): string {
    $GLOBALS['state'] = $state + array('enabled' => true, 'user' => true, 'shopper' => false, 'unread' => 0, 'location' => 'item');

    return osc_messaging_link($args);
};

harness_section('osc_messaging_link()');
pin('messaging off: nothing', '', $link(array('enabled' => false)));
pin('a guest: nothing', '', $link(array('user' => false)));
pin('a shopper: nothing', '', $link(array('shopper' => true)));
pin(
    'no unread: the link with a hidden badge',
    '<a class="oe-msg-link" href="https://example.test/?page=messages&amp;a=1" aria-label="Messages"'
    . ' data-osc-messages-link data-label="Messages" data-label-unread="Messages, %d unread"'
    . ' data-check-url="https://example.test/index.php?page=ajax&amp;action=messages_check" data-live-url="">'
    . '<span class="oe-msg-label">Messages</span><span class="oe-badge oe-unread" data-osc-unread hidden>0</span></a>'
    . '<script src="https://example.test/oc-includes/assets/osclass/messages.js?v=1" defer></script>',
    $link(array())
);
$three = $link(array('unread' => 3), array('class' => 'sf-icon-btn "x"', 'icon' => '<svg class="i"></svg>'));
check('three unread: the badge shows 3', strpos($three, '<span class="oe-badge oe-unread" data-osc-unread>3</span>') !== false);
check('...and the label says so', strpos($three, 'aria-label="Messages, 3 unread"') !== false);
check('extra classes are added and escaped', strpos($three, 'class="oe-msg-link sf-icon-btn &quot;x&quot;"') !== false);
check('the second link on the page does not load the script again', strpos($three, '<script') === false);
check('away from the messages pages the link is not marked current', strpos($three, 'aria-current') === false);
check('on the messages pages the link is marked current', strpos($link(array('location' => 'messages')), ' aria-current="page">') !== false);
check('the icon is printed as given, before the label', strpos($three, '><svg class="i"></svg><span class="oe-msg-label">') !== false);

harness_section('the contact form');
$form = static function (bool $messaging): string {
    $contactForm = array(
        'hidden'     => array('page' => 'item', 'action' => 'contact_post', 'id' => 5),
        'prefix'     => 'oe-your',
        'hint'       => 'The seller replies to this address.',
        'phone'      => true,
        'subject'    => true,
        'attachment' => true,
        'captcha'    => 'contact_seller',
        'top'        => static function (): void {
            echo '<p class="top-hook"></p>';
        },
        'hooks'      => static function (): void {
            echo '<p class="item-contact-hook"></p>';
        },
        'messaging'  => $messaging,
    );
    ob_start();
    require ABS_PATH . 'oc-includes/osclass/gui/parts/contact-form.php';

    return (string) ob_get_clean();
};
$mail = $form(false);
$msg  = $form(true);
foreach (array('name="yourName"', 'name="yourEmail"', 'name="phoneNumber"', 'name="subject"', 'name="attachment"', 'enctype=', 'top-hook') as $part) {
    check("the e-mail form has {$part}", strpos($mail, $part) !== false);
    check("the messaging form has no {$part}", strpos($msg, $part) === false);
}
check('the e-mail form has no "writing as" line', strpos($mail, 'You are writing as') === false);
check('the messaging form says who is writing, escaped', strpos($msg, '<p class="oe-muted">You are writing as Sam &lt;b&gt;. The seller answers in your messages.</p>') !== false);
foreach (array(
    'the hidden fields'          => '<input type="hidden" name="action" value="contact_post" />',
    'the kept message'           => '>kept text</textarea>',
    'the length cap'             => 'minlength="10" maxlength="2000"',
    'the captcha'                => 'data-context="contact_seller"',
    'the item_contact_form hook' => 'item-contact-hook',
    'the Send button'            => '<button class="oe-btn oe-btn--primary" type="submit">Send message</button>',
) as $what => $needle) {
    check("the messaging form keeps {$what}", strpos($msg, $needle) !== false);
}
pin('the messaging form has the message and captcha fields only', 2, substr_count($msg, 'class="oe-field"'));

exit(harness_result());
