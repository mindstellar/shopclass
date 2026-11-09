<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

/**
 * Lists every margin declaration in an SCSS source with its resolved selector, and every
 * selector that is defined at all. A small brace walker: enough for these theme files, not a parser.
 *
 * @return array{margins: list<array{string,string,string}>, selectors: list<string>}
 */
function scss_scan(string $src): array
{
    $src = (string)preg_replace(array('#/\*.*?\*/#s', '#(?<!:)//[^\n]*#'), '', $src);
    $stack = array();
    $margins = array();
    $selectors = array();
    $buf = '';
    $len = strlen($src);
    $quote = '';
    for ($i = 0; $i < $len; $i++) {
        $c = $src[$i];
        if ($quote !== '') {
            $buf .= $c;
            if ($c === $quote) {
                $quote = '';
            }
            continue;
        }
        if ($c === '"' || $c === "'") {
            $quote = $c;
            $buf .= $c;
        } elseif ($c === '#' && ($src[$i + 1] ?? '') === '{') {
            $end = strpos($src, '}', $i);
            $end = $end === false ? $len - 1 : $end;
            $buf .= substr($src, $i, $end - $i + 1);
            $i = $end;
        } elseif ($c === '{') {
            $sel = trim($buf);
            $parent = $stack === array() ? '' : end($stack);
            if (preg_match('/^@(media|supports|container)\b/', $sel)) {
                // A conditional wrapper adds nothing to the selector, so its children resolve against the parent.
                $stack[] = $parent;
                $buf = '';
                continue;
            }
            $parts = array();
            foreach (array_map('trim', explode(',', $sel)) as $one) {
                $parts[] = str_contains($one, '&') ? str_replace('&', $parent, $one) : trim($parent . ' ' . $one);
            }
            $full = implode(', ', $parts);
            $stack[] = $full;
            if ($sel !== '' && $sel[0] !== '@') {
                $selectors[] = $full;
            }
            $buf = '';
        } elseif ($c === ';' || $c === '}') {
            if ($stack !== array() && preg_match('/^(margin(?:-[a-z]+)*)\s*:\s*(.+)$/s', trim($buf), $m)) {
                $margins[] = array(end($stack), $m[1], trim($m[2]));
            }
            if ($c === '}') {
                array_pop($stack);
            }
            $buf = '';
        } else {
            $buf .= $c;
        }
    }
    return array('margins' => $margins, 'selectors' => $selectors);
}
