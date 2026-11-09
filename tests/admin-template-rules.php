<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Static rules for the stock admin theme templates: no HTML comments in the markup, and no
 * icons in headings, intro or lead lines. Icons stay on buttons, status marks, callouts and dialogs.
 *
 * Usage:  php tests/admin-template-rules.php
 */

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

require_once __DIR__ . '/lib/harness.php';

$theme = ABS_PATH . 'oc-admin/themes/modern';

// Icons allowed inside a matched element: [file suffix => substring of the element that marks it].
$allow = array(
    array('main/index.php', 'dash-stat-icon'),
    array('main/index.php', 'dash-system-row'),
    array('settings/locations/form.php', 'bi-exclamation-triangle-fill'),
);

// Any i or span with a bi or bi-* class token, either quote style, escaped or not (PHP string literals).
$iconRe = '/<(?:i|span)\b[^>]*\bclass=\\\\?["\'][^"\']*(?<![\w-])bi(?:-[\w-]+)?(?![\w-])/';

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($theme, FilesystemIterator::SKIP_DOTS));
$php = array();
foreach ($files as $f) {
    $path = $f->getPathname();
    if (substr($path, -4) === '.php' && strpos($path, '/scss/') === false) {
        $php[] = $path;
    }
}
sort($php);

$stripScripts = static fn (string $src): string => (string)preg_replace(
    array('#<(script|style)\b.*?</\1>#si', '#/\*.*?\*/#s'),
    '',
    $src
);

$allowed = static function (string $rel, string $chunk) use ($allow): bool {
    foreach ($allow as [$suffix, $needle]) {
        if (substr($rel, -strlen($suffix)) === $suffix && strpos($chunk, $needle) !== false) {
            return true;
        }
    }
    return false;
};

$comments = array();
$iconHits = array();

foreach ($php as $path) {
    $rel = substr($path, strlen($theme) + 1);
    $src = $stripScripts((string)file_get_contents($path));

    if (strpos($src, '<!--') !== false) {
        $comments[] = $rel;
    }

    $chunks = array();
    if (preg_match_all('#<(h[1-6]|legend|summary)\b[^>]*>(.*?)</\1>#si', $src, $m)) {
        $chunks = array_merge($chunks, $m[0]);
    }
    $leadClasses = 'form-intro|osc-section-intro|panel-subtitle|settings-lead';
    if (preg_match_all('#<(p|div|span)\b[^>]*class=[\'"][^\'"]*\b(?:' . $leadClasses . ')\b[^\'"]*[\'"][^>]*>(.*?)</\1>#si', $src, $m)) {
        $chunks = array_merge($chunks, $m[0]);
    }
    // A lead paragraph right after the page body opens.
    if (preg_match_all('#osc_admin_body_open\([^)]*\);\s*\?>\s*<p\b[^>]*class="[^"]*text-muted[^"]*"[^>]*>(.*?)</p>#si', $src, $m)) {
        $chunks = array_merge($chunks, $m[0]);
    }
    // Title or intro passed to the section helpers.
    if (preg_match_all('#osc_admin_(?:form_)?section\((.*?)\);#s', $src, $m)) {
        foreach ($m[1] as $call) {
            if (preg_match('#\'(?:title|intro)\'\s*=>[^\n]*#', $call, $titled) && preg_match($iconRe, $titled[0])) {
                $chunks[] = $titled[0];
            }
        }
    }

    foreach ($chunks as $chunk) {
        if (preg_match($iconRe, $chunk) && !$allowed($rel, $chunk)) {
            $iconHits[] = $rel . ': ' . trim((string)preg_replace('/\s+/', ' ', substr($chunk, 0, 90)));
        }
    }
}

check('no HTML comments in admin templates', $comments === array(), implode(', ', $comments));
check('no icons in headings, intro or lead lines', $iconHits === array(), "\n" . implode("\n", array_unique($iconHits)));

// Every block of a screen sits inside the one body.
$bodyScreens = array(
    'appearance/add.php', 'appearance/dependencies.php', 'appearance/index.php',
    'languages/add.php', 'plugins/add.php',
    'settings/api.php', 'settings/keywordBlock.php', 'settings/sitemap.php', 'settings/spamNbots.php',
    'tools/backup.php', 'tools/cleanup.php', 'tools/logs.php', 'tools/maintenance.php',
    'tools/system-info.php', 'tools/upgrade.php',
);
$permalink = ABS_PATH . 'oc-includes/classes/admin/form/PermalinkSettingsScreen.php';
$unbalanced = array();
$outside = array();
$missing = array();
foreach (array_merge($php, array($permalink)) as $path) {
    $rel = $path === $permalink ? basename($path) : substr($path, strlen($theme) + 1);
    $code = '';
    foreach (token_get_all((string)file_get_contents($path)) as $token) {
        if (!is_array($token) || !in_array($token[0], array(T_COMMENT, T_DOC_COMMENT), true)) {
            $code .= is_array($token) ? $token[1] : $token;
        }
    }
    preg_match_all('/\bosc_admin_body_(open|close)\(|\bosc_admin_section\(|<section\b/', $code, $m, PREG_SET_ORDER);
    $hasBody = false;
    $depth = 0;
    $balanced = true;
    $stray = false;
    foreach ($m as $hit) {
        if (($hit[1] ?? '') === 'open') {
            $hasBody = true;
            $depth++;
        } elseif (($hit[1] ?? '') === 'close') {
            $hasBody = true;
            $balanced = $balanced && --$depth >= 0;
        } elseif ($depth === 0) {
            $stray = true;
        }
    }
    if ($hasBody && ($depth !== 0 || !$balanced)) {
        $unbalanced[] = $rel;
    }
    // A partial's sections land in the body of the screen that includes it.
    if ($hasBody && $stray && strpos($code, 'parts/header.php') !== false) {
        $outside[] = $rel;
    }
    if (in_array($rel, $bodyScreens, true) && !$hasBody) {
        $missing[] = $rel;
    }
}
check('body opens and closes balance in every template', $unbalanced === array(), implode(', ', $unbalanced));
check('no section sits outside the body of a screen', $outside === array(), implode(', ', $outside));
check('converted screens open the shared body', $missing === array(), implode(', ', $missing));
foreach (array('plugins/view.php', 'appearance/view.php') as $wrapper) {
    $src = (string)file_get_contents($theme . '/' . $wrapper);
    check($wrapper . ' stays a block-flow wrapper', strpos($src, 'osc_admin_body_open') === false && strpos($src, '<div class="theme-files">') !== false);
}

// The fixed spots stay fixed.
$iframe = (string)file_get_contents($theme . '/fields/iframe.php');
check('field editor note has no icon', !preg_match('#cf-editor-note[^>]*>\s*<i\s#', $iframe));
$index = (string)file_get_contents($theme . '/fields/index.php');
check('add dialog title has no icon', !preg_match('#osc-dialog-title"><i[^>]*></i>\s*<span class="osc-dialog-title-text">#', $index));
$cur = (string)file_get_contents($theme . '/settings/currency_form.php');
check('currency Cancel is not red', strpos($cur, "'variant' => 'red'") === false);

exit(harness_result());
