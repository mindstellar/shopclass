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
 * Pins the target step stage and the install runner: one request per unit, the bar moves only when
 * a unit settles, and there is no overlay.
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

harness_section('install runner');
check('no overlay left in the script', !str_contains($js, 'ins-setup-overlay') && !str_contains($js, 'showOverlay') && !str_contains($js, 'form.inert'));
check('no interval moves the bar', !str_contains($js, 'setInterval'));
check('each unit has an AbortController and a timeout', str_contains($js, 'new AbortController()') && str_contains($js, 'controller.abort()') && str_contains($js, 'signal: controller.signal') && str_contains($js, 'UNIT_TIMEOUT = 180000'));
check('the plan is read from data-install-plan', str_contains($js, "data-install-plan") && !str_contains($js, 'data-shop-queue') && !str_contains($js, 'buildSteps'));
check('the bar is written in one function', substr_count($js, 'scaleX(') === 2 && str_contains($js, 'function settleUnit()'));
check('settleUnit is called only after a unit has an outcome', substr_count($js, 'settleUnit()') === 2 && (bool) preg_match('/unitRequest\(ui\.form, ui\.country, unit, isAccount\)\.then\(.*settleUnit\(\);\s*runUnit\(unitIndex \+ 1\)/s', $js));
check('aria-valuenow is written only with the bar', substr_count($js, "'aria-valuenow'") === 2);
check('the target wrap is never marked busy', !str_contains($js, "ui.wrap") && !preg_match("/getElementById\\('ins-target-wrap'\\)/", $js));
check('no panel or link is built in the script', !str_contains($js, "createElement('a')") && !str_contains($js, "'ins-panel ins-panel-danger'") && !str_contains($js, "text('reload'"));
check('aria-valuetext is written only in settleUnit', substr_count($js, "setAttribute('aria-valuetext'") === 1 && (bool) preg_match("/function settleUnit\\(\\).*?'aria-valuetext'.*?function announceTask/s", $js));
check('the live region is the announcer, not the now line', str_contains($js, "'ins-progress-announcer'") && substr_count($js, 'announceTask(') === 3);
check('the done title is focused on load', str_contains($js, "getElementById('ins-done-title')") && str_contains($js, 'preventScroll: true'));
check('fatal answers stop the run', str_contains($js, 'data.fatal') && str_contains($js, 'function fatalStop'));
check('lost units are still posted', str_contains($js, "'lost[]'") && str_contains($js, 'lost.push(unit.lost)'));
check('error_location is no longer posted', !str_contains($js, "'error_location'"));
check('rows show wait, run, ok and skip', str_contains($js, "'wait'") && str_contains($js, "'run'") && str_contains($js, "'ok'") && str_contains($js, "'skip'"));
check('the slow hint is a timer, not a poll', str_contains($js, 'SLOW_AFTER = 8000') && str_contains($js, "classList.add('is-on')"));
check('the finish pause is 700ms and marks the track finished', str_contains($js, 'FINISH_PAUSE = 700') && str_contains($js, "'is-finished'"));
check('the account failure returns to the form', str_contains($js, 'function showForm') && (bool) preg_match('/isAccount\).*showForm\(\)/s', $js));
check('the runner never reads prefers-reduced-motion', !str_contains($js, 'prefers-reduced-motion'));
check('rail and phone line are swapped by the runner', str_contains($js, 'function setRail') && str_contains($js, 'ins-mstep-label') && str_contains($js, 'ins-mstep-count'));

$node = trim((string) shell_exec('command -v node 2>/dev/null'));
if ($node !== '') {
    exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/install-runner-js.test.mjs') . ' 2>&1', $nodeOut, $nodeCode);
    check('the runner passes its stub-DOM tests: skips, lost units, account stop, fatal stop', $nodeCode === 0);
    if ($nodeCode !== 0) {
        echo implode("\n", $nodeOut), "\n";
    }
}

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
