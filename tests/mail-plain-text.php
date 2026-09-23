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
 * Pins the plain-text copy every outgoing mail carries.
 *
 * osc_sendMail() used to send HTML only: every builder handed it an alt_body, and it
 * never read one. A mail with no plain part scores worse with spam filters, shows
 * nothing in a plain-text reader, and gives a phone nothing to preview. The copy is
 * now made from the HTML, and it keeps link addresses -- an unsubscribe link that
 * loses its address is no link at all.
 *
 * DB-free: the mailer is swapped for one that records instead of sending, through
 * the same init_send_mail filter a plugin would use.  Usage:  php tests/mail-plain-text.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('OSCLASS_VERSION')) {
    define('OSCLASS_VERSION', '0');
}

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use PHPMailer\PHPMailer\PHPMailer;

/** A mailer that keeps what it would have sent. */
class RecordingMailer extends PHPMailer
{
    public static $last;

    public function send()
    {
        self::$last = array('Body' => $this->Body, 'AltBody' => $this->AltBody, 'Subject' => $this->Subject);

        return true;
    }
}

$GLOBALS['__altOverride'] = null;

function osc_apply_filter($tag, $value, ...$args)
{
    if ($tag === 'init_send_mail') {
        return new RecordingMailer(true);
    }
    if ($tag === 'pre_send_mail' && $GLOBALS['__altOverride'] !== null) {
        $value->AltBody = $GLOBALS['__altOverride'];
    }

    return $value;
}
function osc_mailserver_pop()
{
    return false;
}
function osc_mailserver_auth()
{
    return false;
}
function osc_mailserver_ssl()
{
    return '';
}
function osc_mailserver_username()
{
    return '';
}
function osc_mailserver_password()
{
    return '';
}
function osc_mailserver_host()
{
    return '';
}
function osc_mailserver_port()
{
    return '';
}
function osc_mailserver_mail_from()
{
    return 'site@example.com';
}
function osc_mailserver_name_from()
{
    return 'Example';
}
function osc_get_domain()
{
    return 'example.com';
}
function osc_page_title()
{
    return 'Example';
}

require_once __DIR__ . '/../oc-includes/osclass/utils.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

harness_section('turning a mail body into plain text');

pin(
    'paragraphs become paragraphs',
    "Hi Jo,\n\nYour listing is live.\n\nRegards,",
    _osc_mail_text('<p>Hi Jo,</p><p>Your listing is live.</p><p>Regards,</p>')
);
pin(
    'a link keeps its address',
    'See your listing Blue bike (https://example.com/blue-bike_i42).',
    _osc_mail_text('See your listing <a href="https://example.com/blue-bike_i42">Blue bike</a>.')
);
pin(
    'a link whose text is its address is written once',
    'https://example.com/',
    _osc_mail_text('<a href="https://example.com/">https://example.com/</a>')
);
pin(
    'an address in a link loses its HTML escaping',
    'unsubscribe alert (https://example.com/unsub?id=12&email=jo%40example.com&secret=SEC)',
    _osc_mail_text('<a href="https://example.com/unsub?id=12&amp;email=jo%40example.com&amp;secret=SEC">unsubscribe alert</a>')
);
pin(
    'a link to nowhere keeps only its text',
    'Top',
    _osc_mail_text('<a href="#top">Top</a>')
);
pin(
    'a script link keeps only its text',
    'Click',
    _osc_mail_text('<a href="javascript:alert(1)">Click</a>')
);
pin(
    'a line break is a line break',
    "Line one\nLine two",
    _osc_mail_text('Line one<br>Line two')
);
pin(
    'a list is a list',
    "Your listings:\n\n- Blue bike\n- Red car",
    _osc_mail_text('<p>Your listings:</p><ul><li>Blue bike</li><li>Red car</li></ul>')
);
pin(
    'entities become the characters they stand for',
    'Box & Papers <for real> "quoted"',
    _osc_mail_text('Box &amp; Papers &lt;for real&gt; &quot;quoted&quot;')
);
pin(
    'styles and scripts are dropped, not printed',
    'Hello',
    _osc_mail_text('<style>p { color: red; }</style><script>track();</script><p>Hello</p>')
);
pin(
    'source line breaks are not layout, as in a browser',
    'One sentence written over three lines.',
    _osc_mail_text("<p>One sentence\nwritten over\nthree lines.</p>")
);
pin(
    'upper-case and loosely written tags still count',
    "Hi Jo\n\nThanks",
    _osc_mail_text('<P> Hi Jo </ p> <p> Thanks </p>')
);
pin(
    'text in another script comes through untouched',
    "नमस्ते Jo\n\nधन्यवाद",
    _osc_mail_text('<p>नमस्ते Jo</p><p>धन्यवाद</p>')
);
// One stored Hindi template really reads "</a के बारे में >". A browser takes all of
// that as the closing tag and shows none of it, so the plain copy matches what the
// reader sees -- and still keeps the address.
pin(
    'a malformed closing tag reads the way a browser reads it',
    'your listing Blue bike (https://example.com/i42):',
    _osc_mail_text('your listing <a href="https://example.com/i42">Blue bike</a के बारे में >:')
);
pin('an empty body has no plain copy', '', _osc_mail_text(''));
pin('a body of only markup has no plain copy', '', _osc_mail_text('<p></p><br/>'));

harness_section('what a real template becomes');

pin(
    'the listing enquiry a seller receives',
    "Hi Sam!\n\nJo (jo@example.com, 555-0100) left you a message about your listing"
    . " Blue bike (https://example.com/blue-bike_i42):\n\nIs it still for sale?\n\nRegards,\n\n"
    . 'Example (https://example.com/)',
    _osc_mail_text(
        '<p>Hi Sam!</p><p>Jo (jo@example.com, 555-0100) left you a message about your listing'
        . ' <a href="https://example.com/blue-bike_i42">Blue bike</a>:</p><p>Is it still for sale?</p>'
        . '<p>Regards,</p><p><a href="https://example.com/">Example</a></p>'
    )
);

harness_section('what osc_sendMail() sends');

$html = '<p>Hi Jo,</p><p>See <a href="https://example.com/i42">Blue bike</a>.</p>';
$base = array('to' => 'jo@example.com', 'subject' => 'Hi', 'body' => $html);

RecordingMailer::$last = null;
pin('it sends', true, osc_sendMail($base + array('alt_body' => $html)));
pin('the HTML body is untouched', $html, RecordingMailer::$last['Body']);
pin(
    'the plain copy is made from it when a builder hands back the HTML',
    "Hi Jo,\n\nSee Blue bike (https://example.com/i42).",
    RecordingMailer::$last['AltBody']
);

RecordingMailer::$last = null;
osc_sendMail($base);
pin(
    'and when a builder hands over no plain copy at all',
    "Hi Jo,\n\nSee Blue bike (https://example.com/i42).",
    RecordingMailer::$last['AltBody']
);

RecordingMailer::$last = null;
$own = "Hi Jo,\n\n    A plain copy written by hand, indented on purpose.";
osc_sendMail($base + array('alt_body' => $own));
pin('a plain copy written by hand is sent as written', $own, RecordingMailer::$last['AltBody']);

RecordingMailer::$last = null;
osc_sendMail($base + array('alt_body' => '<p>Different HTML</p>'));
pin(
    'a plain copy that is itself HTML is converted, not sent as markup',
    'Different HTML',
    RecordingMailer::$last['AltBody']
);

RecordingMailer::$last            = null;
$GLOBALS['__altOverride']         = 'Set by a plugin';
osc_sendMail($base);
$GLOBALS['__altOverride']         = null;
pin('a plugin can still replace it in pre_send_mail', 'Set by a plugin', RecordingMailer::$last['AltBody']);

exit(harness_result());
