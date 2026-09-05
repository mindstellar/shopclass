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
 * Pins core's comments block: the field names the add_comment action reads, the
 * hooks plugins attach to, and the class vocabulary a theme styles.
 *
 * Comments are enabled by default, so each of these fails silently on a live site:
 *
 *  - a renamed field and the post is rejected, or worse accepted with that value
 *    dropped -- CWebItem reads authorName/authorEmail/title/body by name;
 *  - a lost hidden input (page/action/id) and the form posts to the wrong route;
 *  - `nocsrf` on the form and the token core verifies is never injected;
 *  - a renamed .oe-* class and every theme styling the published name renders
 *    that element unstyled on an install nobody here can see;
 *  - a dropped hook and a plugin's comment field disappears with no error.
 *
 * DB-free and source-level: what is worth pinning is the agreement between the
 * partial, its stylesheet and the helper that renders them.
 *
 * Usage:  php tests/item-comments-view.php
 */

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';

$guiDir  = ABS_PATH . 'oc-includes/osclass/gui/';
$content = $guiDir . 'item-comments-content.php';
$style   = $guiDir . 'item-comments-style.php';

harness_section('the files');
check('the comments partial exists', file_exists($content));
check('its stylesheet exists', file_exists($style));

$src   = file_get_contents($content);
$css   = file_get_contents($style);
$items = file_get_contents(ABS_PATH . 'oc-includes/osclass/helpers/hItems.php');

harness_section('the helper a theme calls');
check('osc_show_item_comments() is defined', strpos($items, 'function osc_show_item_comments(') !== false);
preg_match('/function osc_show_item_comments.*?\n}/s', $items, $fn);
$body = $fn[0] ?? '';
// Comments can be switched off site-wide; rendering the block anyway would show a
// form whose post the controller refuses.
check('it returns early when comments are disabled', strpos($body, '!osc_comments_enabled()') !== false);
check('it requires the content partial', strpos($body, 'item-comments-content.php') !== false);
check('it prints the stylesheet', strpos($body, 'item-comments-style.php') !== false);
// Printed once: the block is one per page today, but a theme calling it twice must
// not emit the stylesheet twice.
check('the stylesheet is printed once per request', strpos($body, 'static $styled') !== false);

harness_section('the field contract CWebItem reads');
foreach (array('authorName', 'authorEmail', 'title', 'body') as $field) {
    check("posts a field named {$field}", strpos($src, 'name="' . $field . '"') !== false);
}
foreach (array('page" value="item', 'action" value="add_comment') as $hidden) {
    check("carries the hidden {$hidden}\"", strpos($src, $hidden) !== false);
}
check('carries the listing id', strpos($src, 'name="id"') !== false);
// The token is injected on shutdown into any form NOT marked nocsrf, and the
// action verifies it. Marking this one would break every post.
preg_match('/<form\b[^>]*>/s', $src, $formTag);
check('the form is not marked nocsrf', !empty($formTag) && strpos($formTag[0], 'nocsrf') === false);

harness_section('the values a rejected post is restored from');
foreach (array('commentAuthorName', 'commentAuthorEmail', 'commentTitle', 'commentBody') as $key) {
    check("repopulates {$key}", strpos($src, $key) !== false);
}

harness_section('hooks');
foreach (array('item_comments_before', 'item_comments_after', 'comment_form') as $hook) {
    check("fires {$hook}", strpos($src, "osc_run_hook('" . $hook . "')") !== false);
}

harness_section('the published class vocabulary');
// Rationale must never reach the browser: an HTML comment renders, a PHP one does
// not, and the two look alike in a template.
check('no HTML comment in the partial', strpos($src, '<!--') === false);

preg_match_all('/class="([^"]*)"/', $src, $cm);
$emitted = array();
foreach ($cm[1] as $attr) {
    foreach (preg_split('/\s+/', trim($attr)) as $token) {
        if (strpos($token, 'oe-') === 0) {
            $emitted[$token] = true;
        }
    }
}
$emitted = array_keys($emitted);
sort($emitted);
check('the partial emits .oe-* classes at all', $emitted !== array());
foreach ($emitted as $class) {
    // .oe-field and .oe-actions are shared with the core page vocabulary and are
    // styled there; the rest are this block's own.
    if (in_array($class, array('oe-field', 'oe-actions'), true)) {
        continue;
    }
    check("the stylesheet covers .{$class}", strpos($css, '.' . $class) !== false);
}

harness_section('the defaults stay overridable');
// Every rule is :where(), which contributes nothing to specificity, so a theme
// restyles the block with a single class and no !important.
// Only the CSS itself: the file is a PHP template, and its header would otherwise
// read as a selector.
preg_match('/<style>(.*?)<\/style>/s', $css, $block);
$rules = preg_match_all('/^([^@\s][^{]*)\{/m', $block[1] ?? '', $rm) ? array_map('trim', $rm[1]) : array();
check('the stylesheet has rules', $rules !== array());
foreach ($rules as $selector) {
    check("zero-specificity selector: {$selector}", strpos($selector, ':where(') === 0);
}

exit(harness_result());
