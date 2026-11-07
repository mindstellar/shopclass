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
 * Pins the installer shell: a fixed-height card, a scrolling stage body above one action bar
 * on every step, a sticky bar on phones, no webfont, no colour literals outside the token
 * blocks, and every id install.js looks up exists in a step partial.
 *
 * DB-free.  Usage: php tests/install-shell-layout.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/install-css.php';

$root = ins_root();
$rules = ins_parse_css((string) file_get_contents($root . '/install.css'));

/** Declarations of the rules with exactly this selector, inside ($phone true) or outside the phone query. */
$decl = static function (string $sel, bool $phone) use ($rules): array {
    $out = array();
    foreach ($rules as $r) {
        if ($r['sel'] === $sel && preg_match('/max-width:\s*760px/', $r['media']) === ($phone ? 1 : 0) && ($phone || $r['media'] === '')) {
            $out = array_merge($out, $r['decl']);
        }
    }

    return $out;
};

harness_section('CSS: fixed shell');
$card = $decl('.ins-card', false);
check('.ins-card has a fixed height outside the phone query', isset($card['height']) && $card['height'] !== 'auto' && !str_starts_with($card['height'], 'min-'));
$vars = ins_root_vars($rules, 'light');
$h = isset($card['height']) ? ins_resolve_var($card['height'], $vars) : null;
check('that height resolves to a length', $h !== null && preg_match('/^[\d.]+(rem|px|vh|dvh|em)$/', $h) === 1, (string) $h);
check('.ins-card does not use min-height', !isset($card['min-height']));
check('.ins-stage-body scrolls (overflow-y:auto)', ($decl('.ins-stage-body', false)['overflow-y'] ?? '') === 'auto');
check('.ins-actions has a min-height', isset($decl('.ins-actions', false)['min-height']));
check('phone: .ins-actions is sticky', ($decl('.ins-actions', true)['position'] ?? '') === 'sticky');
check('phone: .ins-overlay sits above the sticky .ins-actions', (int) ($decl('.ins-overlay', false)['z-index'] ?? 0) > (int) ($decl('.ins-actions', true)['z-index'] ?? 0));
check('phone: .ins-card height is released', ($decl('.ins-card', true)['height'] ?? '') === 'auto');

harness_section('CSS: hosting and tokens');
check('no @font-face', array_filter($rules, static fn (array $r) => $r['sel'] === '@font-face') === array());
$hex = array();
foreach ($rules as $r) {
    if (str_starts_with($r['sel'], ':root')) {
        continue;
    }
    foreach ($r['decl'] as $k => $v) {
        if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $v)) {
            $hex[] = $r['sel'] . ' { ' . $k . ' }';
        }
    }
}
check('no hex colour outside the :root token blocks', $hex === array(), implode('; ', array_slice($hex, 0, 3)));

harness_section('Markup: one stage, action bar last');
$gui = $root . '/gui';
$files = array('install.php (step 1)' => $gui . '/install.php', 'install-database.php' => $gui . '/install-database.php', 'install-site-type.php' => $gui . '/install-site-type.php', 'install-target.php' => $gui . '/install-target.php', 'install-finish.php' => $gui . '/install-finish.php');
foreach ($files as $label => $file) {
    $src = (string) file_get_contents($file);
    $stages = preg_match_all('/class="ins-stage"/', $src, $m, PREG_OFFSET_CAPTURE);
    check($label . ' has exactly one ins-stage', $stages === 1, (string) $stages);
    if ($stages !== 1) {
        continue;
    }
    $from = $m[0][0][1];
    $bar = strrpos($src, 'class="ins-actions"');
    check($label . ' puts ins-actions after the stage body', $bar !== false && $bar > $from && $bar > (int) strpos($src, 'ins-stage-body', $from));
    if ($bar === false) {
        continue;
    }
    // Walk the divs after the bar's opening tag to its close, then expect the stage to close next.
    $rest = substr($src, (int) strpos($src, '>', $bar) + 1);
    $depth = 1;
    $end = 0;
    preg_match_all('~<div\b|</div>~', $rest, $tags, PREG_OFFSET_CAPTURE);
    foreach ($tags[0] as [$tag, $at]) {
        $depth += $tag === '</div>' ? -1 : 1;
        if ($depth === 0) {
            $end = $at + 6;
            break;
        }
    }
    $after = ltrim((string) preg_replace('~^(\s|<\?php\s*\}\s*\?>)*~', '', substr($rest, $end)));
    check($label . ': ins-actions is the last child of the stage', $end > 0 && preg_match('~^</(form|div)>~', $after) === 1, substr($after, 0, 24));
}

harness_section('JS and PHP agree on ids');
$js = (string) file_get_contents($root . '/install.js');
$ids = array();
if (preg_match_all('/getElementById\(\s*\'([^\']+)\'\s*\)/', $js, $m)) {
    $ids = $m[1];
}
if (preg_match_all('/(?:querySelector(?:All)?|closest|matches)\(\s*\'[^\']*#([\w-]+)/', $js, $m)) {
    $ids = array_merge($ids, $m[1]);
}
$ids = array_unique($ids);
$php = '';
foreach (glob($gui . '/*.php') ?: array() as $f) {
    $php .= file_get_contents($f);
}
check('install.js looks up some ids', count($ids) > 10, (string) count($ids));
$helperIds = array();
if (preg_match_all("/'id'\s*=>\s*'([^']+)'/", $php, $m)) {
    $helperIds = array_unique($m[1]);
}
$fromHelper = static function (string $id) use ($helperIds): bool {
    foreach ($helperIds as $y) {
        if (in_array($id, array($y, $y . '-error', $y . '-help', 'ins-gen-' . $y), true)) {
            return true;
        }
    }

    return false;
};
foreach ($ids as $id) {
    check('#' . $id . ' exists in a gui partial', str_contains($php, 'id="' . $id . '"') || $fromHelper($id));
}

harness_section('Field markup lives in fields.php');
foreach (glob($gui . '/*.php') ?: array() as $f) {
    if (basename($f) === 'fields.php') {
        continue;
    }
    $src = (string) file_get_contents($f);
    check(basename($f) . ' has no pasted field block', !str_contains($src, 'class="ins-field'));
    check(basename($f) . ' has no pasted reveal icons', !str_contains($src, 'ins-icon-hide'));
}

harness_section('Theme toggle icons');
$layout = (string) file_get_contents($gui . '/install.php');
$toggle = preg_match('/<button[^>]*id="ins-theme-toggle".*?<\/button>/s', $layout, $m) ? $m[0] : '';
$used = preg_match_all('/class="[^"]*\b(ins-icon-[\w-]+)/', $toggle, $m) ? $m[1] : array();
check('logo is read from logo.svg, not pasted in the layout', str_contains($layout, "readfile(__DIR__ . '/../logo.svg')") && !str_contains($layout, '<mask'));
check('logo.svg draws in currentColor', str_contains((string) @file_get_contents($root . '/logo.svg'), 'currentColor'));
check('toggle has a sun and a moon icon', in_array('ins-icon-sun', $used, true) && in_array('ins-icon-moon', $used, true));
$toggleSels = '';
foreach ($rules as $r) {
    if (str_starts_with($r['sel'], '#ins-theme-toggle')) {
        $toggleSels .= $r['sel'] . "\n";
    }
}
foreach ($used as $cls) {
    check('#ins-theme-toggle .' . $cls . ' has a CSS rule', str_contains($toggleSels, '.' . $cls));
}
if (preg_match_all('/#ins-theme-toggle[^\n]*?\.(ins-[\w-]+)/', $toggleSels, $m)) {
    foreach (array_unique($m[1]) as $cls) {
        check('.' . $cls . ' styled on the toggle exists in its markup', in_array($cls, $used, true));
    }
}
check('moon hidden when pressed, sun hidden when not', str_contains($toggleSels, '[aria-pressed="true"] .ins-icon-moon') && str_contains($toggleSels, '#ins-theme-toggle .ins-icon-sun'));

exit(harness_result());
