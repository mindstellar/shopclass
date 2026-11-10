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
 * Static markup rules for the stock admin theme templates: no HTML comments, no icons in
 * headings, intro or lead lines, the shared body on every screen, no widget-box or col-title
 * markup, no margins on gap-spaced classes. Component rules are in tests/admin-component-rules.php.
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
$permalink = ABS_PATH . 'oc-includes/classes/admin/form/PermalinkSettingsScreen.php';
$unbalanced = array();
$outside = array();
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
}
check('body opens and closes balance in every template', $unbalanced === array(), implode(', ', $unbalanced));
check('no section sits outside the body of a screen', $outside === array(), implode(', ', $outside));
foreach (array('plugins/view.php', 'appearance/view.php') as $wrapper) {
    $src = (string)file_get_contents($theme . '/' . $wrapper);
    check($wrapper . ' stays a block-flow wrapper', strpos($src, 'osc_admin_body_open') === false && strpos($src, '<div class="theme-files">') !== false);
}

/** Source with comments removed. */
$codeOf = static function (string $path): string {
    $code = '';
    foreach (token_get_all((string)file_get_contents($path)) as $token) {
        if (!is_array($token) || !in_array($token[0], array(T_COMMENT, T_DOC_COMMENT), true)) {
            $code .= is_array($token) ? $token[1] : $token;
        }
    }
    return $code;
};

// Screens that may skip the body, each with the reason.
$noBody = array(
    'appearance/customise.php' => 'own full-screen shell',
    'plugins/view.php'         => 'plugin output stays in block flow',
    'appearance/view.php'      => 'theme output stays in block flow',
);
$noHeading = array(
    'appearance/customise.php' => 'own full-screen shell, no page heading',
);

$screens = array();
$noBodyHits = array();
$noHeadingHits = array();
foreach ($php as $path) {
    $rel = substr($path, strlen($theme) + 1);
    $code = $codeOf($path);
    if (strpos($code, 'parts/header.php') !== false) {
        $screens[] = $rel;
        if (strpos($code, 'osc_admin_body_open(') === false && !isset($noBody[$rel])) {
            $noBodyHits[] = $rel;
        }
    }
    if (strpos($code, 'osc_admin_page(') !== false && strpos($code, "'heading'") === false && !isset($noHeading[$rel])) {
        $noHeadingHits[] = $rel;
    }
}
check('the rule sees the screens', count($screens) >= 70, (string)count($screens));
check('every screen that includes the header opens the shared body', $noBodyHits === array(), implode(', ', $noBodyHits));
check('every osc_admin_page screen declares a heading', $noHeadingHits === array(), implode(', ', $noHeadingHits));
foreach ($noBody as $rel => $why) {
    $code = $codeOf($theme . '/' . $rel);
    check('body exception still lacks the body: ' . $rel . ' (' . $why . ')', strpos($code, 'parts/header.php') !== false && strpos($code, 'osc_admin_body_open(') === false);
}
foreach ($noHeading as $rel => $why) {
    $code = $codeOf($theme . '/' . $rel);
    check('heading exception still lacks one: ' . $rel . ' (' . $why . ')', strpos($code, "'heading'") === false);
}
// The body-less probe: a header include with no body is caught.
$probe = "<?php require 'parts/header.php'; echo 1;";
check('body rule probe', strpos($probe, 'parts/header.php') !== false && strpos($probe, 'osc_admin_body_open(') === false);

// No hand-built widget box or column heading in core templates.
$widget = array();
$colTitle = array();
foreach ($php as $path) {
    $rel = substr($path, strlen($theme) + 1);
    $code = $codeOf($path);
    if (preg_match('/(?<![\w-])widget-box(?![\w-])/', $code)) {
        $widget[] = $rel;
    }
    if (preg_match('/<h[1-6]\b[^>]*(?<![\w-])col-title(?![\w-])/', $code)) {
        $colTitle[] = $rel;
    }
}
check('no widget-box markup in core templates', $widget === array(), implode(', ', $widget));
check('no col-title heading in core templates', $colTitle === array(), implode(', ', $colTitle));
check('widget-box probe', (bool)preg_match('/(?<![\w-])widget-box(?![\w-])/', '<div class="widget-box">') && !preg_match('/(?<![\w-])widget-box(?![\w-])/', 'id="widget-box-stats"'));
check('col-title probe', (bool)preg_match('/<h[1-6]\b[^>]*(?<![\w-])col-title(?![\w-])/', '<h3 class="col-title">') && !preg_match('/<h[1-6]\b[^>]*(?<![\w-])col-title(?![\w-])/', "'class' => 'col-title'"));

// Old margin rules that nothing renders stay out of the SCSS; classes plugins print stay defined.
require_once __DIR__ . '/lib/scss_margins.php';
$scssDir = $theme . '/scss';
$margins = array();
$defined = array();
$scssFiles = glob($scssDir . '/*.scss') ?: array();
foreach ($scssFiles as $file) {
    $scan = scss_scan((string)file_get_contents($file));
    foreach ($scan['margins'] as [$sel, $prop, $val]) {
        $margins[] = array(basename($file), $sel, $prop, $val);
    }
    $defined = array_merge($defined, $scan['selectors']);
}
$definedText = implode("\n", $defined);

// Selectors (exact, one comma part) whose rule must carry no margin; a zero reset is fine.
$noMargin = array(
    '.billing-detail-grid > :is(.osc-panel, .osc-section, .osc-toolbar) + :is(.osc-panel, .osc-section, .osc-toolbar)',
    '.loc-head', '.loc-toolbar', '.loc-tablebar', '.loc-tabs', '.loc-section', '.loc-section + .loc-section',
    '.dash-stats',
    '.sysinfo-aside', '.sysinfo-details', '.sysinfo-details + .sysinfo-details',
    '.sysinfo-help-group + .sysinfo-help-group', '.backup-callout', '.whatsnew', '.upgrade-running',
    '.upgrade-tool .osc-verdict',
    '.cat-intro', '.categories-app .osc-toolbar', '.cat-foot', '.cat-tabs', '.field-groups', '.col-head',
    '.col-hint', '.form-card', '.palette-group + .palette-group', '.help-box + .osc-catcards-bar',
    '.osc-editor-main .osc-editor-section', '.osc-editor-group',
);
$marginHits = array();
foreach ($margins as [$file, $sel, $prop, $val]) {
    if (preg_match('/^0(?:\s+0)*$/', $val)) {
        continue;
    }
    foreach (array_map('trim', explode(',', $sel)) as $one) {
        if (in_array($one, $noMargin, true)) {
            $marginHits[] = $file . ': ' . $one . ' { ' . $prop . ': ' . $val . ' }';
        }
    }
}
check('no old margin rule on a gap-spaced screen class', $marginHits === array(), "\n" . implode("\n", array_unique($marginHits)));
$sample = scss_scan('.loc-head { margin-block-end: 1rem; .x { margin: 0 } &:hover { margin: 0 0 1px } }');
check('margin scan probe', $sample['margins'][0] === array('.loc-head', 'margin-block-end', '1rem') && $sample['margins'][2][0] === '.loc-head:hover');
$sample = scss_scan('@media (min-width: 1px) { .loc-head { margin-top: 1rem; } } .loc-toolbar { @supports (display: grid) { margin: 1px; } }');
check('margin scan sees through at-rules', $sample['margins'] === array(array('.loc-head', 'margin-top', '1rem'), array('.loc-toolbar', 'margin', '1px')));

$keep = array(
    '.render-title', '.separate-top', '.separate-top-medium', '.input-separate-top', '.form-row', '.form-intro',
    '.settings-actions', '.settings-action', '.page-head', '.widget-box', '.osc-panel', '.osc-toolbar',
    '.has-pagination', '#bulk-actions', '.osc-field',
);
$missingClass = array();
foreach ($keep as $sel) {
    if (!preg_match('/' . preg_quote($sel, '/') . '(?![\w-])/', $definedText)) {
        $missingClass[] = $sel;
    }
}
check('classes plugins print are still styled', $missingClass === array(), implode(', ', $missingClass));
check('the stack reset stays', strpos((string)file_get_contents($scssDir . '/_structure.scss'), '$osc-stack-reset') !== false);

// The fixed spots stay fixed.
$iframe = (string)file_get_contents($theme . '/fields/iframe.php');
check('field editor note has no icon', !preg_match('#cf-editor-note[^>]*>\s*<i\s#', $iframe));
$index = (string)file_get_contents($theme . '/fields/index.php');
check('add dialog title has no icon', !preg_match('#osc-dialog-title"><i[^>]*></i>\s*<span class="osc-dialog-title-text">#', $index));
$cur = (string)file_get_contents($theme . '/settings/currency_form.php');
check('currency Cancel is not red', strpos($cur, "'variant' => 'red'") === false);

exit(harness_result());
