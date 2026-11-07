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
 * Pins the installer button ink. Resolves the colours of every button variant in every
 * state, light and dark, and checks ink differs from fill, text is readable, hover is a real
 * change, and the global link rules never win over the button rules.
 *
 * DB-free.  Usage: php tests/install-button-ink.php
 */

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/install-css.php';

$rules = ins_parse_css((string) file_get_contents(ins_root() . '/install.css'));

harness_section('dark tokens');
$light = ins_root_vars($rules, 'light');
$dark = ins_root_vars($rules, 'dark');
check('light and dark define the same tokens', $light !== array() && array_keys($light) === array_keys(array_merge($light, $dark)) && count(array_filter(array_keys($dark), static fn ($k) => str_starts_with($k, '--osc-'))) > 10);
check('dark tokens are written once, not again under a prefers-color-scheme query', array_filter($rules, static fn (array $r) => str_contains($r['media'], 'prefers-color-scheme') && str_starts_with($r['sel'], ':root')) === array());
$layout = (string) file_get_contents(ins_root() . '/gui/install.php');
check('head script sets data-bs-theme from the system setting', str_contains($layout, "matchMedia('(prefers-color-scheme: dark)')") && str_contains($layout, "setAttribute('data-bs-theme', window.insTheme.resolve())"));

$variants = array(
    'primary'   => array('ins-btn-primary', 4.5),
    'secondary' => array('ins-btn-secondary', 4.5),
    'ghost'     => array('ins-btn-ghost', 3.0),
    'copied'    => array('is-copied', 3.0),
);
// Each state is checked on a link (a) and, where it can apply, on a button element.
$states = array(
    'rest'          => array('button', array()),
    'link'          => array('a', array(':link')),
    'visited'       => array('a', array(':visited')),
    'hover'         => array('a', array(':link', ':hover')),
    'hover (button)' => array('button', array(':hover')),
    'active'        => array('a', array(':link', ':hover', ':active')),
    'focus-visible' => array('a', array(':link', ':focus-visible')),
    'disabled'      => array('button', array(':disabled')),
    'disabled (link)' => array('a', array(':link', '[aria-disabled="true"]')),
);

$contexts = array('' => '', ' (phone)' => 'max-width: 760px');
foreach ($contexts as $where => $media) {
foreach (array('light' => $light, 'dark' => $dark) as $mode => $root) {
    $bench = ins_rgba((string) ins_resolve_var('var(--osc-bench)', $root)) ?? array(255, 255, 255, 1.0);
    foreach ($variants as $name => [$class, $min]) {
        harness_section($mode . ' ' . $name . $where);
        $fills = array();
        foreach ($states as $label => [$tag, $pseudo]) {
            $has = array_merge(array($tag, '.ins-btn', '.' . $class), $pseudo);
            $c = ins_cascade($rules, $has, $media);
            $vars = array_merge($root, array_filter($c, static fn ($k) => str_starts_with($k, '--'), ARRAY_FILTER_USE_KEY));
            $bg = isset($c['background']) ? ins_resolve_var($c['background'], $vars) : null;
            $ink = isset($c['color']) ? ins_resolve_var($c['color'], $vars) : null;
            $fillRgba = $bg !== null ? ins_rgba($bg) : null;
            $inkRgba = $ink !== null ? ins_rgba($ink) : null;
            $tag = $name . ' ' . $label;
            check($tag . ': background and colour resolve', $fillRgba !== null && $inkRgba !== null, (string) $bg . ' / ' . (string) $ink);
            if ($fillRgba === null || $inkRgba === null) {
                continue;
            }
            $fill = ins_over($fillRgba, $bench);
            $text = ins_over($inkRgba, $fill);
            $fills[$label] = $fill;
            check($tag . ': ink differs from fill', $fill !== $text && ins_contrast($fill, $text) > 1.3);
            if (!str_starts_with($label, 'disabled')) {
                check($tag . ': contrast at least ' . $min, ins_contrast($fill, $text) >= $min, sprintf('%.2f', ins_contrast($fill, $text)));
            }
            if ($label === 'focus-visible') {
                check($tag . ': outline is visible', ins_outline_visible($c['outline'] ?? '', $vars), (string) ($c['outline'] ?? 'none'));
            }
        }
        if ($name === 'primary' || $name === 'secondary') {
            check($name . ': hover fill differs from rest fill', isset($fills['hover'], $fills['link']) && $fills['hover'] !== $fills['link']);
            check($name . ': button hover fill differs from rest fill', isset($fills['hover (button)'], $fills['rest']) && $fills['hover (button)'] !== $fills['rest']);
        }
    }
}
}

harness_section('state rules');
foreach (array(':link', ':visited') as $state) {
    $c = ins_cascade($rules, array('a', '.ins-btn', '.ins-btn-primary', $state));
    $own = false;
    foreach ($rules as $r) {
        $own = $own || ($r['media'] === '' && ($r['sel'] === '.ins-btn' . $state) && isset($r['decl']['color']));
    }
    check('.ins-btn' . $state . ' sets its own colour', $own);
}
foreach (array(':hover', ':active', ':disabled') as $state) {
    $bg = false;
    $fg = false;
    foreach ($rules as $r) {
        if ($r['media'] === '' && in_array('.ins-btn' . $state, array_map('trim', explode(',', $r['sel'])), true)) {
            $bg = $bg || isset($r['decl']['background']) || isset($r['decl']['background-color']);
            $fg = $fg || isset($r['decl']['color']);
        }
    }
    check('.ins-btn' . $state . ' sets background and colour', $bg && $fg);
}

harness_section('link rules never win over the button');
foreach ($contexts as $where => $media) {
foreach ($states as $label => [$tag, $pseudo]) {
    if ($tag !== 'a') {
        continue;
    }
    $has = array('a', '.ins-btn', '.ins-btn-primary', ...$pseudo);
    $win = null;
    foreach ($rules as $r) {
        $t = ins_in_context($r, $media) ? ins_tokens($r['sel']) : null;
        if ($t !== null && $t !== array() && isset($r['decl']['color']) && array_diff($t, $has) === array()) {
            $score = array(ins_specificity($t), $r['order']);
            if ($win === null || $score > $win[0]) {
                $win = array($score, $r['sel']);
            }
        }
    }
    check($label . $where . ': the winning colour rule is a .ins-btn rule', $win !== null && str_contains($win[1], '.ins-btn'), (string) ($win[1] ?? 'none'));
}
}

harness_section('no !important on button or link colours');
$important = array();
foreach ($rules as $r) {
    if (preg_match('/\.ins-btn|(^|[\s>+~])a(?![\w-])/', $r['sel'])) {
        foreach (array('color', 'background', 'background-color', 'outline') as $k) {
            if (isset($r['decl'][$k]) && stripos($r['decl'][$k], '!important') !== false) {
                $important[] = $r['sel'] . ' { ' . $k . ' }';
            }
        }
    }
}
check('no button or link colour rule uses !important', $important === array(), implode(', ', $important));

exit(harness_result());
