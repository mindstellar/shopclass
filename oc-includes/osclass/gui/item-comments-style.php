<?php
if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Defaults for the comments block, scoped under .oe-comments.
 *
 * The block is embedded in a theme's own listing page, so it cannot borrow the
 * core page stylesheet: every rule there is prefixed .oe-page and would not
 * apply. Everything here is :where(), which costs nothing in specificity -- a
 * theme overrides any of it with a single class and no !important.
 */
?>
<style>
:where(.oe-comments) { margin-block-start: 2rem; }
:where(.oe-comments-head) { display: flex; align-items: baseline; gap: .5rem; }
:where(.oe-comments-count) { font-size: .875em; opacity: .7; }
:where(.oe-comments-list) { list-style: none; margin: 1rem 0 0; padding: 0; display: grid; gap: 1rem; }
:where(.oe-comment) { padding-block-end: 1rem; border-block-end: 1px solid currentColor; border-color: color-mix(in srgb, currentColor 15%, transparent); }
:where(.oe-comment-head) { display: flex; flex-wrap: wrap; align-items: baseline; gap: .25rem .75rem; font-size: .9375em; }
:where(.oe-comment-author) { font-weight: 600; }
:where(.oe-comment-date) { opacity: .7; }
:where(.oe-comment-title) { margin: .35rem 0 0; font-size: 1em; }
:where(.oe-comment-body) { margin: .35rem 0 0; }
:where(.oe-comment-actions) { margin-block-start: .35rem; font-size: .875em; }
:where(.oe-comment-delete) { color: inherit; }
:where(.oe-comments-empty) { opacity: .75; }
:where(.oe-comments-gate) { margin-block-start: 1.5rem; }
:where(.oe-comment-form) { margin-block-start: 1.5rem; display: grid; gap: .75rem; max-inline-size: 40rem; }
:where(.oe-comment-form .oe-field) { display: grid; gap: .25rem; }
:where(.oe-comment-form label) { font-weight: 600; font-size: .9375em; }
:where(.oe-comment-form input, .oe-comment-form textarea) { inline-size: 100%; padding: .5rem .625rem; font: inherit; color: inherit; background: transparent; border: 1px solid currentColor; border-color: color-mix(in srgb, currentColor 35%, transparent); border-radius: .25rem; }
:where(.oe-comment-form textarea) { resize: vertical; }
:where(.oe-comment-optional) { font-weight: 400; opacity: .7; }
</style>
