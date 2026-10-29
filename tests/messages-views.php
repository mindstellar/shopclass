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
 * Pins the messages pages' markup and script: escaping, the forms' targets and
 * the thread script's rules. DB-free and source-level.
 *
 * Usage:  php tests/messages-views.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/messaging/InboxQuery.php';

use mindstellar\messaging\InboxQuery;

$guiDir = ABS_PATH . 'oc-includes/osclass/gui/';
$files  = array(
    'inbox'  => $guiDir . 'account/user-messages-content.php',
    'thread' => $guiDir . 'account/user-conversation-content.php',
    'list'   => $guiDir . 'account/parts/messenger-list.php',
    'chat'   => $guiDir . 'account/parts/messenger-chat.php',
);
$script = ABS_PATH . 'oc-includes/assets/osclass/messages.js';
$style  = (string) file_get_contents($guiDir . 'page-style.php');

harness_section('the partials');
$src = array();
foreach ($files as $name => $file) {
    check("the {$name} partial exists", file_exists($file));
    $src[$name] = (string) file_get_contents($file);
    check("the {$name} partial has no HTML comment", strpos($src[$name], '<!--') === false);
    // A bare variable echoed with no escape: ints are cast, strings go through osc_esc_html().
    preg_match_all('/echo\s+\$\w+(?:\[[^\]]*\])*\s*;/', $src[$name], $bare);
    pin("the {$name} partial echoes no unescaped value", array(), $bare[0]);
}

harness_section('the forms');
$thread = $src['chat'];
$inbox  = $src['list'];
check('the compose form posts send_post', strpos($thread, 'name="action" value="send_post"') !== false
    && strpos($thread, 'class="oe-compose" method="post"') !== false);
check('the compose box is capped by MessageGuard::maxLength()', strpos($thread, 'MessageGuard::maxLength()') !== false);
check('the report form is a POST to report_post', strpos($thread, 'name="action" value="report_post"') !== false);
check('block and unblock are POST forms with the CSRF token', strpos($thread, "\$postForm('block_post'") !== false
    && strpos($thread, "\$postForm('unblock_post'") !== false
    && strpos($thread, 'method="post"') !== false && strpos($thread, 'osc_csrf_token_form()') !== false);
check('a report offers the reasons the policy accepts', strpos($thread, 'MessagePolicy::REPORT_REASONS') !== false);
foreach (array('inbox', 'thread') as $page) {
    check("the {$page} page loads messages.js with defer", (bool) preg_match('/<script src="[^"]*"\s+defer><\/script>/', $src[$page])
        && strpos($src[$page], 'osclass/messages.js') !== false);
}
check('a message body is escaped and keeps its line breaks in CSS', strpos($thread, "osc_esc_html((string) \$convoMsg['s_body'])") !== false
    && (bool) preg_match('/\.oe-message-body\{[^}]*white-space:pre-wrap/', $style));

harness_section('live delivery');
foreach (array('chat' => 'data-osc-messages', 'list' => 'data-osc-inbox') as $name => $marker) {
    check("the {$name} partial prints the live URL", strpos($src[$name], 'data-live-url="<?php echo osc_esc_html(') !== false);
    check("the {$name} partial prints the check URL", strpos($src[$name], 'data-check-url="') !== false);
    check("the {$name} partial has the {$marker} hook", strpos($src[$name], $marker) !== false);
}

harness_section('web push');
require_once __DIR__ . '/lib/push-optin-render.php';
check('the list pane includes the push partial as the bell', (bool) preg_match(
    "/\\\$pushVariant = 'bell';\\s*require __DIR__ \\. '\\/push-optin\\.php';/",
    $src['list']
));
foreach (array('inbox', 'thread') as $name) {
    check("the {$name} page loads push.js with defer only while push is on", (bool) preg_match(
        '#<\\?php if \\(osc_push_enabled\\(\\)\\) \\{ \\?>\\s*<script src="[^"]*osclass/push\\.js[^"]*"\\s+defer></script>#',
        $src[$name]
    ));
    check("the {$name} page loads push.js after messages.js", strpos($src[$name], 'osclass/messages.js') < strpos($src[$name], 'osclass/push.js'));
}
check('the Notify menu item is the push toggle', strpos($thread, 'data-osc-push-toggle') !== false);
check('the push bell sits outside the refreshed inbox list', strpos($inbox, 'push-optin.php') < strpos($inbox, '<div data-osc-inbox'));
$optinSrc = (string) file_get_contents($guiDir . 'account/parts/push-optin.php');
check('the push partial has no HTML comment', strpos($optinSrc, '<!--') === false);
$optin = push_optin_render(true);
check('the push partial prints the public key into data-key', strpos($optin, 'data-key="' . push_test_key() . '"') !== false);
check('the push form starts hidden', (bool) preg_match('/<form class="oe-push[^>]*\shidden\s/', $optin));
check('the push form carries the CSRF fields', strpos($optin, "name='CSRFName' value='test-name'") !== false
    && strpos($optin, "name='CSRFToken' value='test-token'") !== false);
check('and is not stamped twice by the response filter', strpos($optin, 'class="oe-push nocsrf"') !== false);
foreach (array('push_worker', 'push_subscribe', 'push_unsubscribe', 'push_status') as $pushAction) {
    check("the push form names the {$pushAction} URL", strpos($optin, 'https://example.test/sub/index.php?page=ajax&amp;action=' . $pushAction) !== false);
}
check('the push form names the inbox', strpos($optin, 'data-inbox-url="https://example.test/sub/user/messages"') !== false);
check('the site name is escaped into the worker URL', strpos($optin, 'title=Shop%20%26%20Co') !== false);
pin('the push partial prints nothing while push is off', '', trim(push_optin_render(false)));
pin('nor when the site has no push key (OpenSSL without EC)', '', trim(push_optin_render(true, false)));

harness_section('the avatar hue');
pin('the same user always gets the same hue', InboxQuery::hue(41), InboxQuery::hue(41));
check('every hue is from the palette', in_array(InboxQuery::hue(7), InboxQuery::HUES, true) && in_array(InboxQuery::hue(0), InboxQuery::HUES, true));
check('different users spread over the palette', count(array_unique(array_map(InboxQuery::hue(...), range(1, 8)))) === 8);
$lum = static function (array $rgb): float {
    $c = array_map(static fn (float $v): float => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $rgb);

    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
};
$hslToRgb = static function (int $h, float $s, float $l): array {
    $a = $s * min($l, 1 - $l);
    $f = static function (int $n) use ($h, $l, $a): float {
        $k = fmod($n + $h / 30, 12);

        return $l - $a * max(-1, min($k - 3, 9 - $k, 1));
    };

    return array($f(0), $f(8), $f(4));
};
foreach (InboxQuery::HUES as $hue) {
    $ratio = 1.05 / ($lum($hslToRgb($hue, 0.45, 0.34)) + 0.05);
    check("white on hue {$hue} is readable (4.5:1)", $ratio >= 4.5, (string) round($ratio, 2));
}
check('the list prints the hue on the initial', (bool) preg_match('/oe-thread-initial" style="--oe-hue:<\?php echo \(int\)/', $src['list'] ?? ''));
check('the chat head prints the hue on the initial', (bool) preg_match('/oe-thread-initial" style="--oe-hue:<\?php echo \(int\)/', $src['chat'] ?? ''));

check('the search box carries a hidden search icon', (bool) preg_match('/oe-list-filter" hidden>\s*<svg class="oe-list-filter-icon"[^>]*aria-hidden="true"/', $src['list'] ?? ''));

harness_section('the messenger markup');
$all = implode("\n", $src);
foreach (array(
    'oe-messenger', 'oe-messenger-list', 'oe-messenger-chat', 'oe-list-head', 'oe-list-filter', 'oe-list-tabs', 'is-unread',
    'is-current', 'oe-thread-link', 'oe-thread-photo', 'oe-thread-initial', 'oe-thread-name', 'oe-thread-time', 'oe-thread-preview',
    'oe-thread-flag', 'oe-chat-head', 'oe-chat-back', 'oe-chat-name', 'oe-chat-role', 'oe-menu', 'oe-menu-panel', 'oe-menu-item',
    'oe-listing-card', 'oe-listing-photo', 'oe-listing-title', 'oe-listing-price', 'oe-listing-link', 'is-removed', 'oe-day',
    'is-first', 'is-last', 'oe-seen', 'oe-held', 'oe-typing', 'oe-compose-row', 'oe-compose-send', 'oe-compose-foot',
    'oe-compose-hint', 'oe-compose-counter', 'oe-stopbar', 'oe-chat-empty', 'is-chat', 'is-empty',
    'oe-threads', 'oe-thread-title', 'oe-unread', 'oe-messages', 'oe-message', 'oe-message-body', 'oe-message-meta',
    'oe-compose', 'oe-thread-actions',
) as $class) {
    check("the markup uses .{$class}", preg_match('/(?<![\\w-])' . preg_quote($class, '/') . '(?![\\w-])/', $all) === 1);
}
foreach (array('data-osc-inbox', 'data-osc-messages', 'data-server-now', 'data-their-unread', 'data-typing-url', 'data-check-url',
    'data-since-url', 'data-send-url', 'data-last-id', 'data-me=', 'data-conversation', 'id="oe-list-filter"', 'id="oe-compose-body"',
    'name="body"', 'data-filter="all"', 'data-filter="unread"', 'aria-current="page"') as $attr) {
    check("the markup carries {$attr}", strpos($all, $attr) !== false);
}
check('the typing URL prints only while live delivery is on', (bool) preg_match('/<\?php if \(\$liveUrl !== \'\'\) \{ \?>data-typing-url=/', $thread));
check('the typing bubble prints only while live delivery is on', (bool) preg_match('/<\?php if \(\$liveUrl !== \'\'\) \{ \?>\s*<div class="oe-typing" hidden>/', $thread));
check('the chat pane opens with the thread, the empty pane only without one', strpos($src['thread'], 'oe-messenger is-chat') !== false
    && strpos($src['inbox'], 'oe-messenger is-chat') === false && strpos($src['inbox'], 'oe-chat-empty') !== false);
check('the filter and the tabs start hidden', (bool) preg_match('/class="oe-list-filter" hidden/', $inbox) && (bool) preg_match('/class="oe-list-tabs"[\\s\\S]*?\\s+hidden>/', $inbox));
check('a preview, a name and a title are escaped', strpos($inbox, "osc_esc_html(\$rowPreview)") !== false
    && strpos($inbox, "osc_esc_html((string) \$listRow['other_name'])") !== false && strpos($inbox, "osc_esc_html((string) \$listRow['title'])") !== false);
check('"You: " prefixes my own last message', strpos($inbox, "\$listRow['preview_mine'] ? _m('You') . ': '") !== false);
$seenMsgs = static fn (array $rows): array => array_map(
    static fn (array $r): array => array('fk_i_sender_id' => $r[0], 's_state' => $r[1]),
    $rows
);
pin('Seen goes under my last delivered message', 1, InboxQuery::seenAt($seenMsgs(array(array(2, 'sent'), array(1, 'sent'), array(1, 'held'))), 1, 0));
pin('Seen needs their unread count at 0', -1, InboxQuery::seenAt($seenMsgs(array(array(2, 'sent'), array(1, 'sent'))), 1, 1));
pin('no Seen when they wrote after me', -1, InboxQuery::seenAt($seenMsgs(array(array(1, 'sent'), array(2, 'sent'))), 1, 0));
pin('no Seen when all mine are held', -1, InboxQuery::seenAt($seenMsgs(array(array(1, 'held'))), 1, 0));
check('the chat partial takes Seen from the view, not its own loop', strpos($thread, "_get('seen_at')") !== false && strpos($thread, 'foreach ($convoMsgs as $i => $m)') === false);
check('the account hooks stay in both pages', substr_count($src['inbox'] . $src['thread'], "osc_run_hook('account_page_before'") === 2
    && substr_count($src['inbox'] . $src['thread'], "osc_run_hook('account_page_after'") === 2);
check('both pages still include the account nav', substr_count($src['inbox'] . $src['thread'], "/nav.php") === 2);

harness_section('the stylesheet');
foreach (array(
    'oe-threads', 'oe-thread', 'oe-thread-title', 'oe-thread-last', 'oe-unread', 'oe-messages', 'oe-message',
    'is-mine', 'is-held', 'oe-message-body', 'oe-message-meta', 'oe-compose', 'oe-thread-actions',
) as $class) {
    check("page-style.php styles .{$class}", preg_match('/\\.' . preg_quote($class, '/') . '(?![\\w-])/', $style) === 1);
}

harness_section('messages.js');
$js = (string) file_get_contents($script);
check('the script exists', $js !== '');
check('the script uses no jQuery', strpos($js, 'jQuery') === false && strpos($js, '$(') === false);
check('the script uses fetch', strpos($js, 'fetch(') !== false);
check('the script sends the form with its CSRF field', strpos($js, 'new FormData(form)') !== false);
check("the script drops the form's page and action so the AJAX action runs", strpos($js, "fields.delete('page')") !== false
    && strpos($js, "fields.delete('action')") !== false && strpos($js, 'body: fields') !== false);
check('the script sets message text with textContent', strpos($js, 'body.textContent = row.body') !== false
    && strpos($js, 'innerHTML') === false);
check('every label in the script comes from the markup, with no English fallback', preg_match("/\\|\\|\\s*'[A-Z][a-z]/", $js) === 0);
pin('and the markup carries those labels', array(), array_values(array_filter(
    array('data-seen-label=', 'data-error-text=', 'data-dialog-close=', 'data-dialog-cancel=', 'data-held-label=', 'data-typing-label='),
    static fn (string $attr): bool => strpos($all, $attr) === false
)));
check('the script keeps appendMessage exported', strpos($js, 'window.oscMessages = {appendMessage: appendMessage}') !== false);

exit(harness_result());
