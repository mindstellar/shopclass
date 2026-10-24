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
 * Pins the messaging events: the hook names each owns, the arguments an old-style callback gets
 * (in order), and the body rules of MessageSending.
 *
 * DB-free.  Usage: php tests/messaging-events.php
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\event\Bridged;
use mindstellar\messaging\event\ConversationBlocked;
use mindstellar\messaging\event\ConversationReported;
use mindstellar\messaging\event\ConversationStarted;
use mindstellar\messaging\event\ConversationUnblocked;
use mindstellar\messaging\event\MessageHeld;
use mindstellar\messaging\event\MessageRead;
use mindstellar\messaging\event\MessageSending;
use mindstellar\messaging\event\MessageSent;

/** Arguments an old-style callback receives for the event. */
function me_args(Bridged $event): array
{
    $got = null;
    $event->applyLegacy('x', static function () use (&$got) {
        $got = func_get_args();
    });

    return $got;
}

harness_section('names and old-style arguments');

$cases = [
    [new ConversationStarted(1, 2, 3, 4), ['conversation_started'], [1, 2, 3, 4]],
    [new MessageSent(5, 1, 3, 4), ['message_sent'], [5, 1, 3, 4]],
    [new MessageHeld(5, 1, 3, 4, 'akismet'), ['message_held'], [5, 1, 3, 4, 'akismet']],
    [new ConversationBlocked(1, 4), ['conversation_blocked'], [1, 4]],
    [new ConversationUnblocked(1, 4), ['conversation_unblocked'], [1, 4]],
    [new ConversationReported(1, 3, 'spam'), ['conversation_reported'], [1, 3, 'spam']],
    [new MessageRead(1, 4, 2), ['message_read'], [1, 4, 2]],
    [new MessageSending('hi', 2, 3, 4, 1), ['message_body'], ['hi', 2, 3, 4, 1]],
];
foreach ($cases as [$event, $names, $args]) {
    $class = get_class($event);
    check($class . ' is Bridged', $event instanceof Bridged);
    pin($class . ' names', $names, $class::legacyNames());
    pin($class . ' old arguments', $args, me_args($event));
}
pin('message_body gets null for a new thread', null, me_args(new MessageSending('hi', 2, 3, 4, null))[4]);

harness_section('getters');

$e = new MessageHeld(5, 1, 3, 4, 'akismet');
pin('MessageHeld getters', [5, 1, 3, 4, 'akismet'], [$e->messageId(), $e->conversationId(), $e->senderId(), $e->recipientId(), $e->reason()]);

harness_section('MessageSending body rules');

$s = new MessageSending('hi', 2, 3, 4, 1);
$s->applyLegacy('message_body', static fn ($b) => $b . ' [x]');
pin('a string return changes the body', 'hi [x]', $s->body());

foreach (['empty string' => '', 'blank string' => '  ', 'null' => null, 'array' => ['a'], 'int' => 5] as $label => $ret) {
    $s = new MessageSending('hi', 2, 3, 4, 1);
    $s->applyLegacy('message_body', static fn () => $ret);
    pin('a ' . $label . ' return leaves the body', 'hi', $s->body());
}

$s = new MessageSending('hi', 2, 3, 4, 1);
$s->setBody("  new \n");
pin('setBody trims', 'new', $s->body());

$threw = false;
try {
    $s->setBody('   ');
} catch (\InvalidArgumentException $e) {
    $threw = true;
}
check('setBody("") throws', $threw);
pin('a failed setBody keeps the body', 'new', $s->body());

$s = new MessageSending('hi', 2, 3, 4, 1);
check('fresh event: not refused, not held, not stopped', !$s->isRefused() && !$s->isHeld() && !$s->isPropagationStopped());
$s->hold('maybe');
pin('hold records the reason', [true, 'maybe', false], [$s->isHeld(), $s->holdReason(), $s->isPropagationStopped()]);
$s->refuse('no');
pin('refuse records the reason and stops propagation', [true, 'no', true], [$s->isRefused(), $s->refusal(), $s->isPropagationStopped()]);

exit(harness_result());
