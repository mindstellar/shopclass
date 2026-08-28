<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * osc_response_etag_value() — the validator that lets a repeat request answer 304.
 *
 * Public pages are told to revalidate on every use (`max-age=0`) and carried nothing to
 * revalidate against, so every one of those checks returned the whole page. This is what
 * turns them into 304s.
 *
 * Two renders of the same page are byte-identical apart from the CSRF pair, which carries
 * a per-second timestamp, so the hash is taken with that pair masked. Everything pinned
 * here follows from that one decision, and both directions of it are dangerous:
 *
 *  - mask too little and the validator changes every second, matching nothing;
 *  - mask too much and an edited page keeps its validator, and a visitor is served
 *    content that is genuinely out of date.
 *
 * The window is pinned for a third reason: masking asserts two bodies are interchangeable,
 * which would let a browser revalidate against one indefinitely — while the CSRF token
 * inside it stops being accepted after Csrf::TOKEN_LIFETIME and that visitor's next form
 * submit fails.
 *
 * Only the pure part is covered here. Which responses get a validator at all — not a
 * personalised one, not a redirect, not a POST — is decided by header state that cannot be
 * stubbed (headers_list() and friends are built-ins), and is verified against a running
 * site instead.  Usage:  php tests/response-etag.php
 */

require_once __DIR__ . '/../oc-includes/osclass/helpers/hHttpCache.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

/** A page carrying the CSRF pair exactly as Csrf::tokenForm() emits it. */
function page(string $token = 'AAAA', string $body = 'hello'): string
{
    return "<html><body>$body"
        . "<form><input type='hidden' name='CSRFName' value='n$token' />\n"
        . "        <input type='hidden' name='CSRFToken' value='v$token' /></form></body></html>";
}

harness_section('the token may change; the validator may not');

$tag = osc_response_etag_value(page('AAAA'), '/a-listing');

check('a validator is produced, quoted', $tag !== '' && $tag[0] === '"' && substr($tag, -1) === '"');
pin(
    'the same page with a freshly issued token keeps its validator',
    $tag,
    osc_response_etag_value(page('ZZZZ'), '/a-listing')
);

harness_section('a changed page must not keep its validator');

check(
    'changed body text moves it',
    $tag !== osc_response_etag_value(page('AAAA', 'hello, and something new'), '/a-listing')
);
check(
    'a change beside the masked attribute moves it',
    $tag !== osc_response_etag_value(
        str_replace("name='CSRFName'", "name='CSRFName' data-x='1'", page('AAAA')),
        '/a-listing'
    )
);
check(
    'a change to an unrelated hidden field moves it',
    $tag !== osc_response_etag_value(
        str_replace('<form>', "<form><input type='hidden' name='other' value='1' />", page('AAAA')),
        '/a-listing'
    )
);
check(
    'an empty body is not confused with a rendered one',
    osc_response_etag_value('', '/a-listing') !== $tag
);

harness_section('the window turns the body over on its own');

/* Same body, same URL, adjacent windows: the validator has to move, or a browser could
   revalidate against one body until the token inside it expired. */
$narrow = osc_response_etag_value(page('AAAA'), '/a-listing', 60);
check('a window of its own yields its own validator', $narrow !== $tag);

check(
    'the floor is enforced, so a zero window cannot pin the validator forever',
    osc_response_etag_value(page('AAAA'), '/a-listing', 0)
    === osc_response_etag_value(page('AAAA'), '/a-listing', 60)
);

/* Phase comes from the URL, so a site's pages do not all turn over on the same second. */
check(
    'two URLs with identical bodies sit in different phases',
    osc_response_etag_value(page('AAAA'), '/listing-a')
    !== osc_response_etag_value(page('AAAA'), '/listing-b')
);
pin(
    'the same URL is stable across calls',
    osc_response_etag_value(page('AAAA'), '/listing-a'),
    osc_response_etag_value(page('AAAA'), '/listing-a')
);

exit(harness_result());
