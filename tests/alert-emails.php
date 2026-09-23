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
 * Pins the two search-alert digests: what is sent, and which filters fire on the
 * way. The hourly and weekly builders were the same 86 lines twice over, so this
 * records both before the shared half is pulled out.
 *
 * The eight alert_email_* filters are a published contract -- a plugin rewrites a
 * digest through them -- so the names, their order, and what each is handed are
 * asserted, not just the finished mail.
 *
 * DB-free: Page and User are stand-ins, and osc_sendMail() records instead of
 * sending.  Usage:  php tests/alert-emails.php
 */

define('ABS_PATH', __DIR__ . '/../');
define('WEB_PATH', 'http://example.com/');

$GLOBALS['__filters'] = array();
$GLOBALS['__sent']    = array();

function osc_apply_filter($name, $content = '', ...$args)
{
    $GLOBALS['__filters'][] = array('name' => $name, 'in' => $content, 'args' => count($args));

    return $content;
}
function osc_run_hook($name, ...$args)
{
}
function __($text, $domain = 'core')
{
    return $text;
}
function osc_language()
{
    return 'en_US';
}
function osc_mailBeauty($text, $words)
{
    return str_replace($words[0], $words[1], $text);
}
function osc_mailserver_mail_from()
{
    return 'site@example.com';
}
function osc_add_hook($hook, $callback, $priority = 10, $args = 1)
{
}
function osc_sendMail($params)
{
    $GLOBALS['__sent'][] = $params;

    return true;
}
function osc_user_unsubscribe_alert_url($id = '', $email = '', $secret = '')
{
    return WEB_PATH . 'unsub?id=' . $id . '&email=' . urlencode($email) . '&secret=' . $secret;
}

class Page
{
    public static function newInstance()
    {
        return new self();
    }

    public function findByInternalName($name)
    {
        return array('locale' => array('en_US' => array(
            's_title' => ucfirst($name) . ' for {USER_NAME}',
            's_text'  => 'Hello {USER_NAME} ({USER_EMAIL}){ADS}{UNSUB_LINK}',
        )));
    }
}

class User
{
    public static function newInstance()
    {
        return new self();
    }

    public function findByPrimaryKey($id)
    {
        return array('pk_i_id' => $id, 's_name' => 'Jo', 's_email' => 'jo@example.com');
    }
}

require_once __DIR__ . '/lib/stubs.php';
require_once __DIR__ . '/../oc-includes/osclass/emails.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

$SEARCH = array('pk_i_id' => 12, 's_secret' => 'SEC');

/** Run one digest and hand back what it filtered and what it sent. */
function digest(callable $fn, array $user)
{
    global $SEARCH;
    $GLOBALS['__filters'] = array();
    $GLOBALS['__sent']    = array();
    $fn($user, '<ul>ads</ul>', $SEARCH, 3, 9);

    return array('filters' => $GLOBALS['__filters'], 'sent' => $GLOBALS['__sent']);
}

foreach (array('hourly', 'weekly') as $period) {
    harness_section('the ' . $period . ' digest, to a registered user');

    $out = digest('fn_alert_email_' . $period, array('fk_i_user_id' => 5, 's_email' => 'old@example.com'));

    pin(
        'the filters fire, in order',
        array(
            'alert_email_' . $period . '_title',
            'email_title',
            'alert_email_' . $period . '_description',
            'email_description',
            'alert_email_' . $period . '_title_after',
            'alert_email_' . $period . '_description_after',
        ),
        array_column($out['filters'], 'name')
    );
    pin('each alert filter is handed the same five extras', array(5, 0, 5, 0, 5, 5),
        array_column($out['filters'], 'args'));
    pin('one mail is sent', 1, count($out['sent']));
    pin('it goes to the account address, not the one on the alert',
        'jo@example.com', $out['sent'][0]['to']);
    pin('addressed by the account name', 'Jo', $out['sent'][0]['to_name']);
    pin('from the site address', 'site@example.com', $out['sent'][0]['from']);
    pin(
        'the subject has the placeholders filled in',
        'Alert_email_' . $period . ' for Jo',
        $out['sent'][0]['subject']
    );
    pin(
        'the body carries the listings and an unsubscribe link',
        'Hello Jo (jo@example.com)<ul>ads</ul>'
        . '<a href="http://example.com/unsub?id=12&email=jo%40example.com&secret=SEC">unsubscribe alert</a>',
        $out['sent'][0]['body']
    );
    pin('the plain-text part is the same', $out['sent'][0]['body'], $out['sent'][0]['alt_body']);

    harness_section('the ' . $period . ' digest, to someone with no account');

    $out = digest('fn_alert_email_' . $period, array('fk_i_user_id' => 0, 's_email' => 'nobody@example.com'));
    pin('it goes to the address on the alert', 'nobody@example.com', $out['sent'][0]['to']);
    pin('and the address stands in for a name', 'nobody@example.com', $out['sent'][0]['to_name']);
    pin(
        'the unsubscribe link carries that address',
        true,
        strpos($out['sent'][0]['body'], 'email=nobody%40example.com') !== false
    );
}

exit(harness_result());
