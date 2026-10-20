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
 * Session::fitFlashMessages() keeps the signed flash cookie small enough for a browser to
 * keep: a short queue is left alone, a long one keeps its first messages plus one
 * "…and N more" line, and a single huge message is cut. Sizes are measured on the real
 * SignedPayload::pack() output.
 *
 * DB-free. Usage:  php tests/flash-cookie-budget.php
 */

require_once __DIR__ . '/lib/harness.php';

define('ABS_PATH', dirname(__DIR__) . '/');
define('OSC_CSRF_SECRET', 'flash-cookie-budget-test-secret');
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

use mindstellar\security\SignedPayload;

$packed = static fn (array $m): int => strlen(SignedPayload::pack('cookie-store:oc_flash', $m, 300));
$msg    = static fn (string $text, string $type = 'error'): array => array('msg' => $text, 'type' => $type);

harness_section('a queue under the budget');

$small = array('pubMessages' => array($msg('Saved', 'ok'), $msg('Two photos were too big')));
pin('is returned unchanged', $small, Session::fitFlashMessages($small));
pin('the default budget is 3800 bytes', 3800, Session::FLASH_BUDGET);

harness_section('60 messages of 200 bytes');

$many = array();
for ($i = 1; $i <= 60; $i++) {
    $many[] = $msg(sprintf('%03d ', $i) . str_repeat('x', 196));
}
$fit  = Session::fitFlashMessages(array('pubMessages' => $many));
$list = $fit['pubMessages'] ?? array();
$kept = count($list) - 1;
check('the packed cookie value fits in 3800 bytes', $packed($fit) <= 3800, (string) $packed($fit));
check('...and uses most of it', $packed($fit) > 3800 - 300, (string) $packed($fit));
check('the first messages are kept, in order', $kept > 0 && array_slice($list, 0, $kept) === array_slice($many, 0, $kept));
pin('the last line counts the rest', '…and ' . (60 - $kept) . ' more', end($list)['msg'] ?? null);
pin('...in the type of the first one left out', 'error', end($list)['type'] ?? null);

harness_section('one 10 KB message');

$big  = $msg('<b>Bulk failed</b><br />' . str_repeat('Listing could not be saved. ', 400));
$fit  = Session::fitFlashMessages(array('adminMessages' => array($big)));
$text = $fit['adminMessages'][0]['msg'] ?? '';
check('is cut to fit', $packed($fit) <= 3800 && $packed($fit) > 3000, (string) $packed($fit));
check('...ends with an ellipsis', str_ends_with($text, '…'));
check('...and keeps its start, as plain text', str_starts_with($text, 'Bulk failed Listing could not'), substr($text, 0, 40));
pin('...and stays one message', 1, count($fit['adminMessages'] ?? array()));
$tag = Session::fitFlashMessages(array('adminMessages' => array($msg('<a title="a>b" href="#">' . str_repeat('Tom &amp; Jerry ', 400) . '</a>'))));
$tag = (string) ($tag['adminMessages'][0]['msg'] ?? '');
check('a cut message carries no markup', !str_contains($tag, '<') && !str_contains($tag, '>') && !str_contains($tag, '"'), substr($tag, 0, 60));
check('...and its entities stay escaped', str_starts_with($tag, 'Tom &amp; Jerry Tom'), substr($tag, 0, 60));
$wide = Session::fitFlashMessages(array('pubMessages' => array($msg(str_repeat('é', 3000)))));
check('a message JSON escapes is measured escaped', $packed($wide) <= 3800, (string) $packed($wide));

harness_section('two buckets');

$two = Session::fitFlashMessages(array(
    'pubMessages'   => array_slice($many, 0, 5),
    'adminMessages' => array_map(static fn ($m) => $msg($m['msg'], 'info'), array_slice($many, 0, 40)),
));
pin('keep their order', array('pubMessages', 'adminMessages'), array_keys($two));
pin('the first bucket is whole', array_slice($many, 0, 5), $two['pubMessages'] ?? null);
$second = $two['adminMessages'] ?? array();
pin('the second ends with the rest counted', '…and ' . (40 - (count($second) - 1)) . ' more', end($second)['msg'] ?? null);
pin('...in its own type', 'info', end($second)['type'] ?? null);
check('the whole fits', $packed($two) <= 3800, (string) $packed($two));

harness_section('the line goes where the first message was left out');

$split = Session::fitFlashMessages(array(
    'pubMessages'   => array_slice($many, 0, 10),
    'adminMessages' => array($msg(str_repeat('z', 900), 'warning')),
));
pin('a whole first bucket is kept', array_slice($many, 0, 10), $split['pubMessages'] ?? null);
pin('...and the line is the second bucket\'s only entry, in its type', array(array('msg' => '…and 1 more', 'type' => 'warning')), $split['adminMessages'] ?? null);
$gap = Session::fitFlashMessages(array('pubMessages' => array($msg('first'), $msg(str_repeat('w', 3000)), $msg('third'), $msg('fourth'))));
pin('nothing after the first message that does not fit is kept', array($msg('first'), $msg('…and 3 more')), $gap['pubMessages'] ?? null);

exit(harness_result());

/* file end: ./tests/flash-cookie-budget.php */
