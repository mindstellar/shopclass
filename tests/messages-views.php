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
