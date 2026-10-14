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
 * Pins that the {PLACEHOLDER} tokens in a mail are expanded in one pass.
 *
 * osc_mailBeauty() used to run str_ireplace() twice: the caller's pairs, then the
 * site-wide tokens. A value the caller passed in -- a contact message, a name, a
 * listing title, all visitor text -- was then searched again, so a visitor who
 * typed {WEB_LINK} or {IP_ADDRESS} had the site write a link or the sender's
 * address into the mail for them. A value could also expand a later caller key.
 * Only the template's own tokens may be expanded; what replaces them is final.
 *
 * DB-free.  Usage:  php tests/mail-placeholder-injection.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/../oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

function osc_base_url()
{
    return 'https://example.com/';
}

function osc_page_title()
{
    return 'Example';
}

function osc_date_format()
{
    return 'Y-m-d';
}

function osc_time_format()
{
    return 'H:i';
}

require_once __DIR__ . '/../oc-includes/osclass/utils.php';

harness_section('the template is still filled in');
pin(
    'caller and site tokens, any case',
    'Hi Jo, from Example (https://example.com/) on <a href="https://example.com/">Example</a>',
    osc_mailBeauty('Hi {user_name}, from {WEB_TITLE} ({Web_Url}) on {WEB_LINK}', array(array('{USER_NAME}'), array('Jo')))
);
pin(
    'a caller key wins over a site token of the same name',
    'Shop',
    osc_mailBeauty('{WEB_TITLE}', array(array('{WEB_TITLE}'), array('Shop')))
);
pin(
    'the first of two equal caller keys wins',
    'a',
    osc_mailBeauty('{X}', array(array('{X}', '{x}'), array('a', 'b')))
);
pin('an unknown token is left alone', '{NOPE} Jo', osc_mailBeauty('{NOPE} {N}', array(array('{N}'), array('Jo'))));
pin('no caller pairs at all', 'Example', osc_mailBeauty('{WEB_TITLE}', array(array(), array())));

harness_section('a caller value is not expanded again');
pin(
    'a site token in a contact message stays as typed',
    'Message: click {WEB_LINK} from {IP_ADDRESS}',
    osc_mailBeauty('Message: {CONTACT_MESSAGE}', array(array('{CONTACT_MESSAGE}'), array('click {WEB_LINK} from {IP_ADDRESS}')))
);
pin(
    'a later caller key in an earlier value stays as typed',
    'Name: {SECRET} / s3cr3t',
    osc_mailBeauty('Name: {USER_NAME} / {SECRET}', array(array('{USER_NAME}', '{SECRET}'), array('{SECRET}', 's3cr3t')))
);
pin(
    'nor a lower-case one',
    'Name: {web_url}',
    osc_mailBeauty('Name: {USER_NAME}', array(array('{USER_NAME}'), array('{web_url}')))
);
pin(
    'regex and backreference characters in a value are plain text',
    'Hi $1 \\1 /x/',
    osc_mailBeauty('Hi {N}', array(array('{N}'), array('$1 \\1 /x/')))
);

exit(harness_result());
