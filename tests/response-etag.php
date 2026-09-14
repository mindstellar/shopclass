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
 * The validator that lets a repeat request answer 304, and the token bucket it rests on.
 *
 * Public pages are told to revalidate on every use (`max-age=0`) and carried nothing to
 * revalidate against, so every one of those checks returned the whole page.
 *
 * The validator is a plain hash of what was sent, which only works because a render is
 * deterministic. It was not: the CSRF token stamped its issue time to the second, making
 * every render of a page different, and that was the *only* thing that differed. Rounding
 * the stamp onto Csrf::ISSUE_BUCKET is what makes a page stable, and it is also what keeps
 * the validator honest — a page carrying a token turns over once a bucket, so a browser
 * can never revalidate against a body whose token has stopped being accepted.
 *
 * Both halves are pinned here: the hash, and the bucket through Csrf's own behaviour.
 * Which responses get a validator at all — not a personalised one, not a redirect, not a
 * POST — is decided by header state that cannot be stubbed (headers_list() and friends are
 * built-ins) and is verified against a running site instead.
 *
 * Usage:  php tests/response-etag.php
 */

require_once __DIR__ . '/../oc-includes/osclass/helpers/hHttpCache.php';
require_once __DIR__ . '/../oc-includes/osclass/classes/Csrf.php';
require_once __DIR__ . '/lib/harness.php';

$GLOBALS['okCount']    = 0;
$GLOBALS['failCount']  = 0;
$GLOBALS['failLabels'] = array();

harness_section('the validator identifies the bytes that were sent');

$page = '<html><body>hello</body></html>';

pin(
    'the same body yields the same validator',
    osc_response_etag_value($page),
    osc_response_etag_value($page)
);
check('it is quoted, as an ETag must be', osc_response_etag_value($page)[0] === '"');
check(
    'a changed body yields a different one',
    osc_response_etag_value($page) !== osc_response_etag_value('<html><body>hello!</body></html>')
);
check('a one-character change is enough', osc_response_etag_value('a') !== osc_response_etag_value('b'));
check(
    'an empty body is not confused with a rendered one',
    osc_response_etag_value('') !== osc_response_etag_value($page)
);

/* Nothing is masked, so anything that does vary between renders yields a different
   validator and simply no 304 — the behaviour there was before any of this, never a
   stale page. */
check(
    'a per-render difference changes the validator rather than being ignored',
    osc_response_etag_value($page) !== osc_response_etag_value($page . '<!-- 12 ms -->')
);

harness_section('the token stamp is bucketed, which is what makes a render repeatable');

$ref    = new ReflectionClass('mindstellar\\Csrf');
$bucket = $ref->getConstant('ISSUE_BUCKET');
$life   = $ref->getConstant('TOKEN_LIFETIME');
$skew   = $ref->getConstant('CLOCK_SKEW');
$issued = $ref->getMethod('issuedAt');
$issued->setAccessible(true);

check('a bucket is configured', is_int($bucket) && $bucket > 0);
pin('the stamp is a multiple of the bucket', 0, $issued->invoke(null) % $bucket);
check('...and never in the future, so it cannot trip the skew guard', $issued->invoke(null) <= time());
check('...nor further back than one bucket', time() - $issued->invoke(null) < $bucket);

/* Bucketing issues a token up to one bucket "early", so it expires that much sooner. That
   has to stay well short of its own lifetime, or a form rendered late in a bucket would
   arrive close to dead. */
check(
    'a bucketed token keeps most of its life',
    $life - $bucket >= $life / 2,
    sprintf('worst case %ds of %ds', $life - $bucket, $life)
);
check('the bucket is longer than the clock-skew allowance', $bucket > $skew);

/* A body holding a token must not be revalidatable for longer than the token is accepted:
   the body changes when the bucket does, so the bucket bounds it. */
check('a body cannot outlive its token', $bucket <= $life);

exit(harness_result());
