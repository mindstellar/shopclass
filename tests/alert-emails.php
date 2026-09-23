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
 * Pins the three search-alert digests: what is sent, and which filters fire on the
 * way. The hourly, daily and weekly builders were the same 86 lines three times
 * over, and had already drifted -- only daily guarded a missing user column.
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
    // Which row a filter was handed matters as much as that it fired: the title and
    // description filters see the alert, the _after ones see the account behind it.
    $GLOBALS['__filters'][] = array(
        'name' => $name,
        'in'   => $content,
        'args' => count($args),
        'who'  => isset($args[0]['s_email']) ? $args[0]['s_email'] : null,
    );

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
function osc_item_url_ns($id, $locale = '')
{
    return WEB_PATH . 'index.php?page=item&id=' . $id;
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

require_once __DIR__ . '/../oc-includes/osclass/helpers/hSanitize.php'; // the real osc_esc_html(), ahead of any stand-in
require_once __DIR__ . '/lib/stubs.php';
require_once __DIR__ . '/../oc-includes/osclass/emails.php';
require_once __DIR__ . '/../oc-includes/osclass/alerts.php';
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

// What osc_runAlert() hands a digest. It has already looked the account up, so a
// registered subscriber arrives as their user row -- which has no fk_i_user_id --
// and someone with no account arrives with their address standing in as a name.
$REGISTERED = array('pk_i_id' => 23, 's_name' => 'Michael Reed', 's_email' => 'michael@example.com');
$NO_ACCOUNT = array('s_name' => 'nobody@example.com', 's_email' => 'nobody@example.com');

foreach (array('hourly', 'daily', 'weekly') as $period) {
    $fn = 'fn_alert_email_' . $period;

    harness_section('the ' . $period . ' digest, to a registered user');

    $out = digest($fn, $REGISTERED);
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
    pin('it goes to their address', 'michael@example.com', $out['sent'][0]['to']);
    pin('addressed by their name, not their address', 'Michael Reed', $out['sent'][0]['to_name']);
    pin('from the site address', 'site@example.com', $out['sent'][0]['from']);
    pin(
        'the subject greets them by name',
        'Alert_email_' . $period . ' for Michael Reed',
        $out['sent'][0]['subject']
    );
    pin(
        'the body greets them by name and carries the listings and an unsubscribe link',
        'Hello Michael Reed (michael@example.com)<ul>ads</ul>'
        . '<a href="http://example.com/unsub?id=12&email=michael%40example.com&secret=SEC">unsubscribe alert</a>',
        $out['sent'][0]['body']
    );
    pin('the plain-text part is the same', $out['sent'][0]['body'], $out['sent'][0]['alt_body']);

    harness_section('the ' . $period . ' digest, to someone with no account');

    $out = digest($fn, $NO_ACCOUNT);
    pin('it goes to the address on the alert', 'nobody@example.com', $out['sent'][0]['to']);
    pin('and the address stands in for a name', 'nobody@example.com', $out['sent'][0]['to_name']);
    pin(
        'the unsubscribe link carries that address',
        true,
        strpos($out['sent'][0]['body'], 'email=nobody%40example.com') !== false
    );

    harness_section('the ' . $period . ' digest, when a plugin fires the hook with an alert row');

    // The hook is public, so an alert row can arrive instead of a user row. The digest
    // still finds the account behind it, and only the _after filters see that account.
    $out = digest($fn, array('fk_i_user_id' => 5, 's_email' => 'old@example.com'));
    pin('it goes to the account address, not the one on the alert', 'jo@example.com', $out['sent'][0]['to']);
    pin('addressed by the account name', 'Jo', $out['sent'][0]['to_name']);
    pin(
        'the first two filters see the alert row, the _after pair see the account',
        array('old@example.com', null, 'old@example.com', null, 'jo@example.com', 'jo@example.com'),
        array_column($out['filters'], 'who')
    );

    harness_section('the ' . $period . ' digest, from an alert row with no user column at all');

    $out = digest($fn, array('s_email' => 'bare@example.com'));
    pin('it still goes to the address on the alert', 'bare@example.com', $out['sent'][0]['to']);
    pin('and still uses it as the name', 'bare@example.com', $out['sent'][0]['to_name']);

    harness_section('the ' . $period . ' digest, to a name with markup in it');

    $out = digest($fn, array('pk_i_id' => 23, 's_name' => 'Jo <b> & Co', 's_email' => 'jo@example.com'));
    pin(
        'the subject is plain text, so the name goes in as written',
        'Alert_email_' . $period . ' for Jo <b> & Co',
        $out['sent'][0]['subject']
    );
    pin(
        'the body is HTML, so the name is escaped there',
        'Hello Jo &lt;b&gt; &amp; Co (jo@example.com)',
        substr($out['sent'][0]['body'], 0, strlen('Hello Jo &lt;b&gt; &amp; Co (jo@example.com)'))
    );
}

harness_section('the listings block every digest carries');

pin(
    'each listing is a link to it, one per line',
    '<a href="http://example.com/index.php?page=item&id=7">Blue bike</a><br/>'
    . '<a href="http://example.com/index.php?page=item&id=8">Red car</a><br/>',
    _alert_email_ads(array(
        array('pk_i_id' => 7, 's_title' => 'Blue bike'),
        array('pk_i_id' => 8, 's_title' => 'Red car'),
    ))
);
pin(
    'a title is escaped, so an ampersand is valid HTML and markup stays text',
    '<a href="http://example.com/index.php?page=item&id=9">Box &amp; Papers &lt;img src=x&gt;</a><br/>',
    _alert_email_ads(array(array('pk_i_id' => 9, 's_title' => 'Box & Papers <img src=x>')))
);
pin('no listings, no block', '', _alert_email_ads(array()));

exit(harness_result());
