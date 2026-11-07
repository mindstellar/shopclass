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
 * Pins the target step stage and the busy overlay: the form stays in place while the overlay
 * covers it, so the fixed-height shell never collapses.
 *
 * DB-free.  Usage: php tests/install-target-stage.php
 */

require_once __DIR__ . '/lib/harness.php';

$dir  = dirname(__DIR__) . '/oc-includes/osclass/installer/';
$php  = (string) file_get_contents($dir . 'gui/install-target.php');
$js   = (string) file_get_contents($dir . 'install.js');
$form = (string) preg_replace('/^.*?(<form id="ins-target-form".*<\/form>).*$/s', '$1', $php);

harness_section('markup');
check('the form is the stage', str_contains($php, 'id="ins-target-form" class="ins-stage"'));
check('h1 sits in the stage body', (bool) preg_match('/ins-stage-body">\s*<h1 /', $php));
check('noscript sits in the stage body', strpos($form, '<noscript>') !== false);
check('the actions bar is the last child of the form', (bool) preg_match('/<div class="ins-actions">.*?<\/div>\s*<\/form>/s', $php));
check('the overlay sits beside the form inside the wrap', (bool) preg_match('/<\/form>\s*<div id="ins-setup-overlay"/', $php));

harness_section('overlay script');
check('the form is never hidden', !str_contains($js, 'form.hidden'));
check('the form is made inert while busy', str_contains($js, 'form.inert = true') && str_contains($js, 'form.inert = false'));

harness_section('theme and fade');
check('theme toggle uses window.insTheme', str_contains($js, 'window.insTheme') && str_contains($js, 'function initThemeToggle'));
check('install.js never touches localStorage', !str_contains($js, 'localStorage'));
check('scroll fade toggles is-scrollable', str_contains($js, "'is-scrollable'") && str_contains($js, 'function initScrollFade'));

harness_section('field errors, busy state, generator');
check('one setFieldError helper', str_contains($js, 'function setFieldError(input, message)') && !str_contains($js, 'function setInvalid'));
check('setFieldError manages aria-describedby and the -error element', str_contains($js, "'-error'") && str_contains($js, "'aria-describedby'") && str_contains($js, "'aria-invalid'"));
check('the DB test flags the server field through it', (bool) preg_match('/data\.field.*setFieldError\(/s', $js));
check('the site details checks use it', str_contains($js, 'siteName') && str_contains($js, 'setFieldError(input, message)'));
check('inputs clear their own error', str_contains($js, 'function clearErrorOnInput'));
check('busy helper sets aria-busy, is-loading and keeps width', str_contains($js, "'aria-busy'") && str_contains($js, "'is-loading'") && str_contains($js, 'minWidth'));
check('database submit goes busy and blocks a second submit', str_contains($js, "getElementById('ins-connect-btn')") && str_contains($js, "text('creatingTables'"));
check('generator uses crypto.getRandomValues', str_contains($js, 'crypto.getRandomValues') && str_contains($js, 'data-generate-target') && !str_contains($js, 'Math.random'));
check('first invalid field is focused on load', str_contains($js, 'function focusFirstInvalid'));
check('locale select still reloads with install_locale', str_contains($js, "'?install_locale='"));

exit(harness_result());
