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
 * Core escapes and strips tags in one place: Escape::text(), Escape::htmlEntities() and
 * Sanitize::stripTags(), not htmlspecialchars(), htmlentities() or strip_tags() directly, and
 * no FILTER_SANITIZE_* outside Sanitize. The error page that runs before the autoloader is allowed.
 *
 * DB-free.  Usage: php tests/escaping-central.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\utility\Escape;
use mindstellar\utility\Sanitize;

const ESCAPING_ALLOWED = array(
    'oc-includes/classes/core/utility/Escape.php',
    'oc-includes/classes/core/utility/Sanitize.php',
    'oc-includes/osclass/helpers/hErrors.php',
    'oc-includes/osclass/gui/page.php',
);

$found = array();
foreach (array('oc-includes', 'oc-admin') as $root) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ABS_PATH . $root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = substr($file->getPathname(), strlen(ABS_PATH));
        if ($file->getExtension() !== 'php' || str_contains($path, '/vendor/') || str_starts_with($path, 'oc-includes/assets/')
            || in_array($path, ESCAPING_ALLOWED, true)) {
            continue;
        }
        $tokens = token_get_all((string) file_get_contents($file->getPathname()));
        $prev   = null;
        foreach ($tokens as $t) {
            if (!is_array($t)) {
                $prev = $t;
                continue;
            }
            $member = is_array($prev) && in_array($prev[0], array(T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION), true);
            if (!in_array($t[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)) {
                $prev = $t;
            }
            $name = strtolower(ltrim($t[1], '\\'));
            $call = !$member && in_array($t[0], array(T_STRING, T_NAME_FULLY_QUALIFIED), true) && in_array($name, array('htmlspecialchars', 'htmlentities', 'strip_tags'), true);
            $text = $t[0] === T_CONSTANT_ENCAPSED_STRING && in_array(trim($name, '\'"'), array('htmlspecialchars', 'htmlentities', 'strip_tags'), true);
            if ($call || $text || ($t[0] === T_STRING && str_starts_with($t[1], 'FILTER_SANITIZE_'))) {
                $found[] = $path . ':' . $t[2] . ' ' . $t[1];
            }
        }
    }
}
pin('no direct escaping or tag stripping outside Escape and Sanitize', array(), $found);

$samples = array('<a href="x">O\'Neil & co</a>', '&amp; &#39; é', "bad \xC3\x28 utf8", '', '0');
foreach ($samples as $s) {
    pin('Escape::text matches htmlspecialchars: ' . bin2hex($s), htmlspecialchars($s), Escape::text($s));
    pin('Escape::text matches with flags: ' . bin2hex($s), htmlspecialchars($s, ENT_QUOTES, 'UTF-8', false), Escape::text($s, ENT_QUOTES, 'UTF-8', false));
    pin('Escape::htmlEntities matches htmlentities: ' . bin2hex($s), htmlentities($s, ENT_COMPAT, 'UTF-8'), Escape::htmlEntities($s, ENT_COMPAT, 'UTF-8'));
    pin('Sanitize::stripTags matches strip_tags: ' . bin2hex($s), strip_tags($s, '<a>'), Sanitize::stripTags($s, '<a>'));
}

exit(harness_result());
