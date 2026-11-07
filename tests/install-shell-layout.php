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
check('no .ins-overlay rule is left', array_filter($rules, static fn (array $r) => str_contains($r['sel'], '.ins-overlay')) === array());

harness_section('CSS: progress and done');
$fill = $decl('.ins-fill', false);
$fillProps = array_map('trim', explode(',', $fill['transition'] ?? ''));
check('.ins-fill animates only transform', $fillProps !== array() && array_filter($fillProps, static fn (string $t) => !str_starts_with($t, 'transform ')) === array(), $fill['transition'] ?? '');
$reduced = '';
foreach ($rules as $r) {
    if (str_contains($r['media'], 'prefers-reduced-motion')) {
        $reduced .= $r['sel'] . "\n";
    }
}
foreach (array('.ins-fill', '.ins-task', '.ins-done-seal') as $name) {
    check('reduced-motion block names ' . $name, str_contains($reduced, $name));
}
check('.ins-fill flips its origin in RTL', isset($decl('[dir="rtl"] .ins-fill', false)['transform-origin']));
check('phone: .ins-card height is released', ($decl('.ins-card', true)['height'] ?? '') === 'auto');

harness_section('CSS: RTL, motion, phone, forced colours');
$physical = array();
foreach ($rules as $r) {
    if (str_contains($r['sel'], '[dir="rtl"]')) {
        continue;
    }
    foreach ($r['decl'] as $k => $v) {
        if (preg_match('/^(margin|padding|border)(-[a-z]+)*-(left|right)$/', $k) || in_array($k, array('left', 'right', 'float', 'clear'), true)
            || ($k === 'text-align' && in_array(trim($v), array('left', 'right'), true))) {
            $physical[] = $r['sel'] . ' { ' . $k . ' }';
        }
        if (str_contains($v, 'translateX') && !preg_match('/var\(/', $v)) {
            $physical[] = $r['sel'] . ' { ' . $k . ' }';
        }
    }
}
// translateX with a literal length needs a [dir="rtl"] counterpart.
$flipped = array_filter($rules, static fn (array $r) => str_contains($r['sel'], '[dir="rtl"]') && str_contains(implode(' ', $r['decl']), 'translateX'));
$physical = array_filter($physical, static fn (string $p) => !str_contains($p, 'transform') || $flipped === array());
check('no physical left/right property outside [dir="rtl"] blocks', $physical === array(), implode('; ', array_slice($physical, 0, 3)));
check('the reduced-motion block names the step entrance', str_contains($reduced, '.ins-stage') && str_contains($reduced, ':root'));
check('step entrance is not on the progress or done stage', array_filter($rules, static fn (array $r) => isset($r['decl']['animation']) && str_contains($r['decl']['animation'], 'ins-step-in') && str_contains($r['sel'], ':not(#ins-progress)') && str_contains($r['sel'], ':not(:has(.ins-done-head))')) !== array());
check('the base focus ring is a 2px outline in the focus colour', str_contains($decl(':focus-visible', false)['outline'] ?? '', 'var(--osc-focus)'));
$rtlNeeded = array('.ins-segs span::after', '.ins-stage', '.ins-btn-dir', 'select.ins-input', '.ins-disclosure > summary::before', '.ins-fill', '.ins-task[data-s="ok"] .ins-task-st::before', '.ins-lang select', '.ins-reqs-more > summary::before', '.ins-switch:checked::after');
$rtlMissing = array_filter($rtlNeeded, static fn (string $sel) => $decl('[dir="rtl"] ' . $sel, false) === array());
check('every mirrored part has its [dir="rtl"] rule', $rtlMissing === array(), implode('; ', $rtlMissing));
$rtlOrphans = array();
foreach ($rules as $r) {
    if (!str_starts_with($r['sel'], '[dir="rtl"] ') || $r['media'] !== '') {
        continue;
    }
    $base = substr($r['sel'], strlen('[dir="rtl"] '));
    $ltr = array();
    foreach ($rules as $l) {
        if ($l['sel'] === $base || in_array($base, array_map('trim', explode(',', $l['sel'])), true)) {
            $ltr += $l['decl'];
        }
    }
    // The direction icon only flips in RTL; it has no LTR transform to mirror. A shorthand or a :root token counts as the LTR value.
    $props = $base === '.ins-btn-dir' ? array() : array_filter(array_keys($r['decl']), static fn (string $p) => !isset($ltr[$p])
        && !(str_starts_with($p, 'background-') && isset($ltr['background']))
        && !(str_starts_with($p, '--') && isset($decl(':root', false)[$p])));
    if ($props !== array()) {
        $rtlOrphans[] = $r['sel'] . ' (' . implode(', ', $props) . ')';
    }
}
check('each [dir="rtl"] rule overrides a property its LTR rule sets', $rtlOrphans === array(), implode('; ', $rtlOrphans));
check('back and next button icons mirror in RTL', str_contains($decl('[dir="rtl"] .ins-btn-dir', false)['transform'] ?? '', 'scaleX(-1)'));
check('step entrance flips its side in RTL', isset($decl('[dir="rtl"] .ins-stage', false)['--ins-shift']));
$forced = array_filter($rules, static fn (array $r) => str_contains($r['media'], 'forced-colors: active'));
check('a forced-colors block exists', $forced !== array());
$forcedSel = implode("\n", array_column($forced, 'sel'));
check('text fields keep a focus outline in forced colours', !str_contains($decl('.ins-input:focus', false)['outline'] ?? '', 'none') && array_filter($forced, static fn (array $r) => str_contains($r['sel'], '.ins-input:focus') && str_contains($r['decl']['outline'] ?? '', 'Highlight')) !== array());
check('forced-colors keeps buttons, panels and focus visible', str_contains($forcedSel, '.ins-btn') && str_contains($forcedSel, '.ins-panel') && str_contains($forcedSel, ':focus-visible'));
check('phone: an action bar may wrap instead of widening the page', ($decl('.ins-actions', true)['flex-wrap'] ?? 'wrap') !== 'nowrap' && ($decl('.ins-actions', false)['flex-wrap'] ?? '') === 'wrap');
check('the stage column cannot grow past the card', ($decl('.ins-stage', false)['grid-template-columns'] ?? '') === 'minmax(0, 1fr)');
check('phone: action bar buttons may shrink and wrap their text', ($decl('.ins-actions > .ins-btn', true)['min-width'] ?? '') === '0');
check('phone: the primary button takes the free width', str_starts_with($decl('.ins-actions > .ins-btn-primary', true)['flex'] ?? '', '1 1 '));
check('phone: with Back, Test and Connect the primary takes its own row', str_contains((string) file_get_contents($root . '/install.css'), '.ins-btn-secondary:not([hidden]) + .ins-btn-primary) > .ins-btn-primary'));
check('admin address wraps anywhere', ($decl('.ins-cred-v', false)['overflow-wrap'] ?? '') === 'anywhere');

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
    $want = $label === 'install-target.php' ? 2 : 1;
    check($label . ' has ' . ($want === 1 ? 'exactly one' : 'the form and the progress') . ' ins-stage', $stages === $want, (string) $stages);
    if ($stages !== $want) {
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
    check($label . ': ins-actions is the last child of the stage', $end > 0 && preg_match('~^</(form|div|section)>~', $after) === 1, substr($after, 0, 24));
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
