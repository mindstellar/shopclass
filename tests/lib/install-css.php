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
 * Small CSS reader for the installer tests: rules with their @media, custom property
 * resolution, a cascade for one element, specificity and WCAG contrast.
 */

/** The installer folder; INSTALL_TEST_ROOT points the tests at another checkout of it. */
function ins_root(): string
{
    $root = getenv('INSTALL_TEST_ROOT');

    return rtrim($root !== false && $root !== '' ? $root : dirname(__DIR__, 2) . '/oc-includes/osclass/installer', '/');
}

/** Split on a separator that sits outside quotes and brackets. */
function ins_split(string $s, string $sep): array
{
    $out = array();
    $buf = '';
    $depth = 0;
    $quote = '';
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $c = $s[$i];
        if ($quote !== '') {
            $quote = $c === $quote ? '' : $quote;
        } elseif ($c === '"' || $c === "'") {
            $quote = $c;
        } elseif ($c === '(' || $c === '[') {
            $depth++;
        } elseif ($c === ')' || $c === ']') {
            $depth--;
        } elseif ($c === $sep && $depth === 0) {
            $out[] = $buf;
            $buf = '';
            continue;
        }
        $buf .= $c;
    }
    $out[] = $buf;

    return $out;
}

/** Index of the brace closing the one at $open, skipping quoted text. */
function ins_close(string $s, int $open): int
{
    $depth = 0;
    $quote = '';
    for ($i = $open, $n = strlen($s); $i < $n; $i++) {
        $c = $s[$i];
        if ($quote !== '') {
            $quote = $c === $quote ? '' : $quote;
        } elseif ($c === '"' || $c === "'") {
            $quote = $c;
        } elseif ($c === '{') {
            $depth++;
        } elseif ($c === '}' && --$depth === 0) {
            return $i;
        }
    }

    return $n - 1;
}

/** @return array<string,string> */
function ins_declarations(string $body): array
{
    $out = array();
    foreach (ins_split($body, ';') as $d) {
        $p = strpos($d, ':');
        if ($p !== false && trim($d) !== '') {
            $out[strtolower(trim(substr($d, 0, $p)))] = trim(substr($d, $p + 1));
        }
    }

    return $out;
}

/**
 * Rules in source order. @font-face comes back as a rule with selector "@font-face".
 *
 * @return list<array{media:string,sel:string,decl:array<string,string>,order:int}>
 */
function ins_parse_css(string $css, string $media = '', int &$order = 0): array
{
    $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
    $rules = array();
    $pos = 0;
    while (($open = strpos($css, '{', $pos)) !== false) {
        $close = ins_close($css, $open);
        $prelude = trim(substr($css, $pos, $open - $pos));
        $body = substr($css, $open + 1, $close - $open - 1);
        $pos = $close + 1;
        if (stripos($prelude, '@media') === 0) {
            $rules = array_merge($rules, ins_parse_css($body, trim(substr($prelude, 6)), $order));
        } elseif ($prelude !== '' && $prelude[0] === '@') {
            $rules[] = array('media' => $media, 'sel' => stripos($prelude, '@font-face') === 0 ? '@font-face' : $prelude, 'decl' => array(), 'order' => $order++);
        } elseif ($prelude !== '') {
            foreach (ins_split($prelude, ',') as $sel) {
                $rules[] = array('media' => $media, 'sel' => trim((string) preg_replace('/\s+/', ' ', $sel)), 'decl' => ins_declarations($body), 'order' => $order++);
            }
        }
    }

    return $rules;
}

/** Custom properties of the :root rule outside any media query, or of the dark attribute rule. */
function ins_root_vars(array $rules, string $mode): array
{
    $sel = $mode === 'dark' ? ':root[data-bs-theme="dark"]' : ':root';
    $vars = array();
    foreach ($rules as $r) {
        if ($r['sel'] === $sel && $r['media'] === '') {
            foreach ($r['decl'] as $k => $v) {
                if (str_starts_with($k, '--')) {
                    $vars[$k] = $v;
                }
            }
        }
    }

    return $vars;
}

/** Replace var(--x) with its value; null when something does not resolve. */
function ins_resolve_var(string $value, array $vars, int $guard = 0): ?string
{
    while (preg_match('/var\(\s*(--[\w-]+)\s*(?:,[^)]*)?\)/', $value, $m)) {
        if ($guard++ > 20 || !isset($vars[$m[1]])) {
            return null;
        }
        $value = str_replace($m[0], $vars[$m[1]], $value);
    }

    return $value;
}

/** @return array{0:float|int,1:float|int,2:float|int,3:float}|null  r, g, b (0-255) and alpha (0-1) */
function ins_rgba(string $c): ?array
{
    $c = strtolower(trim($c));
    if ($c === 'transparent') {
        return array(0, 0, 0, 0.0);
    }
    if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $c, $m)) {
        $h = strlen($m[1]) === 3 ? preg_replace('/(.)/', '$1$1', $m[1]) : $m[1];

        return array(hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2)), 1.0);
    }
    if (preg_match('/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+)\s*)?\)$/', $c, $m)) {
        return array((int) $m[1], (int) $m[2], (int) $m[3], isset($m[4]) && $m[4] !== '' ? (float) $m[4] : 1.0);
    }

    return null;
}

/** Colour over a backdrop, as [r,g,b] with no alpha. */
function ins_over(array $fg, array $bg): array
{
    $a = $fg[3];

    return array(
        $fg[0] * $a + $bg[0] * (1 - $a),
        $fg[1] * $a + $bg[1] * (1 - $a),
        $fg[2] * $a + $bg[2] * (1 - $a),
        1.0,
    );
}

function ins_luminance(array $c): float
{
    $lin = static fn (float $v): float => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;

    return 0.2126 * $lin($c[0]) + 0.7152 * $lin($c[1]) + 0.0722 * $lin($c[2]);
}

function ins_contrast(array $a, array $b): float
{
    $x = ins_luminance($a);
    $y = ins_luminance($b);

    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}

/**
 * Tokens of a compound selector (tag, .class, :pseudo, [attr]); null for anything with a
 * combinator or pseudo-element.
 */
function ins_tokens(string $sel): ?array
{
    if (preg_match('/[\s>+~]|::/', $sel) || !preg_match_all('/^[a-z]+|\.[\w-]+|:[\w-]+(?:\([^)]*\))?|\[[^\]]+\]/i', $sel, $m) || implode('', $m[0]) !== $sel) {
        return null;
    }

    return $m[0];
}

/** Specificity as a single comparable number (ids, classes, tags). */
function ins_specificity(array $tokens): int
{
    $s = 0;
    foreach ($tokens as $t) {
        $s += ctype_alpha($t[0]) ? 1 : 100;
    }

    return $s;
}

/** True when a rule applies in the context: no query, or a query containing $media. */
function ins_in_context(array $r, string $media): bool
{
    return $r['media'] === '' || ($media !== '' && str_contains($r['media'], $media));
}

/** True for an outline with a non-zero width and a visible colour. */
function ins_outline_visible(string $value, array $vars): bool
{
    $v = ins_resolve_var($value, $vars);
    if ($v === null || preg_match('/^(none|0|0px)$/i', trim($v)) || !preg_match('/(?<![\w.-])([\d.]+)(px|rem|em)\b/', $v, $w) || (float) $w[1] <= 0) {
        return false;
    }
    foreach (preg_split('/\s+(?![^(]*\))/', trim($v)) ?: array() as $part) {
        $rgba = ins_rgba($part);
        if ($rgba !== null) {
            return $rgba[3] > 0;
        }
    }

    return false;
}

/**
 * Declarations the cascade gives one element: tokens it has (tag, classes, states, attributes).
 * Rules outside media queries count, plus those whose query contains $media when it is given.
 *
 * @param list<string> $has
 * @return array<string,string>
 */
function ins_cascade(array $rules, array $has, string $media = ''): array
{
    $hits = array();
    foreach ($rules as $r) {
        $t = ins_in_context($r, $media) ? ins_tokens($r['sel']) : null;
        if ($t !== null && $t !== array() && array_diff($t, $has) === array()) {
            $hits[] = array(ins_specificity($t), $r['order'], $r['decl']);
        }
    }
    usort($hits, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    $out = array();
    foreach ($hits as $h) {
        foreach ($h[2] as $k => $v) {
            $out[$k === 'background-color' ? 'background' : $k] = $v;
        }
    }

    return $out;
}
