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
 * Pins the messenger fallback styles in gui/page-style.php: every messenger class
 * has a rule, the old messaging classes keep theirs, and colours stay on tokens.
 *
 * Usage:  php tests/messages-style.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';

$style = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/gui/page-style.php');

$newClasses = array(
    'oe-messenger', 'oe-messenger-list', 'oe-messenger-chat', 'oe-list-head', 'oe-list-filter', 'oe-list-tabs',
    'is-unread', 'is-current', 'oe-thread-photo', 'oe-thread-initial', 'oe-thread-name', 'oe-thread-time',
    'oe-thread-preview', 'oe-thread-flag', 'oe-thread-link', 'oe-chat-head', 'oe-chat-back', 'oe-chat-name',
    'oe-chat-role', 'oe-menu', 'oe-menu-panel', 'oe-menu-item', 'oe-listing-card', 'oe-listing-photo',
    'oe-listing-title', 'oe-listing-price', 'oe-listing-link', 'is-removed', 'oe-day', 'is-first', 'is-last',
    'oe-seen', 'oe-held', 'oe-typing', 'is-typing', 'oe-compose-row', 'oe-compose-send', 'oe-compose-foot',
    'oe-compose-hint', 'oe-compose-counter', 'is-tight', 'oe-stopbar', 'oe-dialog', 'oe-chat-empty',
    'oe-icon-btn', 'oe-sr-only',
);
$oldClasses = array(
    'oe-threads', 'oe-thread', 'oe-thread-title', 'oe-unread', 'oe-messages', 'oe-message', 'is-mine', 'is-held',
    'oe-message-body', 'oe-message-meta', 'oe-compose', 'oe-thread-actions', 'oe-push',
);
foreach (array_merge($newClasses, $oldClasses) as $class) {
    check("page-style.php styles .{$class}", strpos($style, '.' . $class) !== false);
}

check('a phone shows one pane at a time', preg_match('/max-width:899px\)\{[^@]*oe-messenger\.is-chat \.oe-messenger-list\{display:none/', $style) === 1);
check('typing dots stop under reduced motion', preg_match('/prefers-reduced-motion:reduce\)\{[^@]*oe-typing/', $style) === 1);
check('the sr-only rule hides visually', preg_match('/\.oe-sr-only\{[^}]*overflow:hidden;[^}]*clip-path/', $style) === 1);

// Colour comes from tokens: strip the :root token block, then no hex may remain in the messenger rules.
$start = strpos($style, '.oe-sr-only{');
$end   = strpos($style, '.oe-page .oe-ref{');
check('the messenger block is found', $start !== false && $end !== false && $end > $start);
$block = substr($style, (int) $start, (int) $end - (int) $start);
check('the messenger block has no raw hex colour', preg_match('/#[0-9a-fA-F]{3,8}\b/', $block) === 0);
check('the messenger block has no rgb()/rgba() literal', preg_match('/\brgba?\(/', $block) === 0);
// The one allowed form is the avatar hue: hsl(var(--oe-hue) S% L%), with no literal hue.
$blockNoHue = preg_replace('/hsl\(var\(--oe-hue\) \d+% \d+%\)/', '', $block);
check('the messenger block has no hsl()/hwb()/lab() literal', preg_match('/\b(?:hsla?|hwb|lab|lch|oklab|oklch)\(/', (string) $blockNoHue) === 0);
check('the avatar colour uses the tokenised hue form', strpos($block, 'hsl(var(--oe-hue) 45% 34%)') !== false);
$named = 'black|white|red|green|blue|gray|grey|silver|maroon|purple|fuchsia|lime|olive|yellow|navy|teal|aqua|orange|pink|brown|'
    . 'gold|beige|ivory|khaki|coral|salmon|tomato|crimson|indigo|violet|cyan|magenta|tan|wheat|linen|snow|azure|lavender|'
    . 'whitesmoke|gainsboro|lightgray|lightgrey|darkgray|darkgrey|dimgray|dimgrey|darkred|darkgreen|darkblue|lightblue';
check('the messenger block has no named colour', preg_match(
    '/(?:^|[;{\s])(?:color|background(?:-color)?|border(?:-[a-z-]+)?|outline(?:-color)?|fill|stroke|box-shadow|text-decoration-color|caret-color)\s*:[^;}]*?(?<![\w-])(?:' . $named . ')(?![\w-])/i',
    $block
) === 0);

exit(harness_result());
