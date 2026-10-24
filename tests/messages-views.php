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

$guiDir = ABS_PATH . 'oc-includes/osclass/gui/';
$files  = array(
    'inbox'  => $guiDir . 'account/user-messages-content.php',
    'thread' => $guiDir . 'account/user-conversation-content.php',
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
$thread = $src['thread'];
check('the compose form posts send_post', strpos($thread, 'name="action" value="send_post"') !== false
    && strpos($thread, 'class="oe-compose" method="post"') !== false);
check('the compose box is capped by MessageGuard::maxLength()', strpos($thread, 'MessageGuard::maxLength()') !== false);
check('the report form is a POST to report_post', strpos($thread, 'name="action" value="report_post"') !== false);
check('block and unblock are POST row actions', strpos($thread, "'action' => 'block_post'") !== false
    && strpos($thread, "'action' => 'unblock_post'") !== false
    && substr_count($thread, "'method' => 'post'") + substr_count($thread, "'method'  => 'post'") >= 2);
check('a report offers the reasons the policy accepts', strpos($thread, 'MessagePolicy::REPORT_REASONS') !== false);
check('the thread script is loaded with defer', (bool) preg_match('/<script src="[^"]*"\s+defer><\/script>/', $thread)
    && strpos($thread, "osclass/messages.js") !== false);
check('a message body is escaped and keeps its line breaks in CSS', strpos($thread, "osc_esc_html((string) \$convoMsg['s_body'])") !== false
    && strpos($style, '.oe-message-body{overflow-wrap:anywhere;white-space:pre-wrap;}') !== false);

harness_section('live delivery');
foreach (array('thread' => 'data-osc-messages', 'inbox' => 'data-osc-inbox') as $name => $marker) {
    check("the {$name} partial prints the live URL", strpos($src[$name], 'data-live-url="<?php echo osc_esc_html(osc_messaging_live_url()); ?>"') !== false);
    check("the {$name} partial prints the check URL", strpos($src[$name], 'data-check-url="') !== false);
    check("the {$name} partial has the {$marker} hook", strpos($src[$name], $marker) !== false);
}
check('the inbox loads messages.js with defer', (bool) preg_match('/<script src="[^"]*"\s+defer><\/script>/', $src['inbox'])
    && strpos($src['inbox'], 'osclass/messages.js') !== false);

harness_section('web push');
require_once __DIR__ . '/lib/push-optin-render.php';
foreach ($files as $name => $file) {
    check("the {$name} page includes the push partial", strpos($src[$name], "require __DIR__ . '/parts/push-optin.php';") !== false);
    check("the {$name} page loads push.js with defer only while push is on", (bool) preg_match(
        '#<\?php if \(osc_push_enabled\(\)\) \{ \?>\s*<script src="[^"]*osclass/push\.js[^"]*"\s+defer></script>#',
        $src[$name]
    ));
    check("the {$name} page loads push.js after messages.js", strpos($src[$name], 'osclass/messages.js') < strpos($src[$name], 'osclass/push.js'));
}
check('the compose box comes before the push partial in the thread', strpos($thread, 'class="oe-compose"') < strpos($thread, 'parts/push-optin.php'));
check('the push partial sits outside the refreshed inbox list', strpos($src['inbox'], 'parts/push-optin.php') < strpos($src['inbox'], '<div data-osc-inbox'));
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

harness_section('the stylesheet');
foreach (array(
    'oe-threads', 'oe-thread', 'oe-thread-title', 'oe-thread-last', 'oe-unread', 'oe-messages', 'oe-message',
    'is-mine', 'is-held', 'oe-message-body', 'oe-message-meta', 'oe-compose', 'oe-thread-actions',
) as $class) {
    check("page-style.php styles .{$class}", strpos($style, '.' . $class) !== false);
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
check('the script keeps appendMessage exported', strpos($js, 'window.oscMessages = {appendMessage: appendMessage}') !== false);

exit(harness_result());
