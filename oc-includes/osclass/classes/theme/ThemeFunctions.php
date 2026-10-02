<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\theme;

/**
 * Finds the function names a child theme and its parent would both declare.
 *
 * A child's functions.php is required first and the parent's second, so a name they
 * share is `Cannot redeclare` -- a compile-time fatal, which no try/catch can hold and
 * which leaves the site blank. The convention that avoids it is the parent wrapping each
 * declaration in `if (!function_exists(...))`, and a collision only matters where the
 * parent did not.
 *
 * So this reads both files rather than guessing: a guarded name in the parent is the
 * override working as intended and is not reported. Only an unguarded one is.
 *
 * Reading is lexical -- token_get_all(), never include -- because the point is to answer
 * before the file is allowed to run.
 */
final class ThemeFunctions
{
    /**
     * Top-level function declarations in $path, as name => guarded.
     *
     * "Guarded" means the declaration sits inside an `if` whose condition calls
     * function_exists() with that same name. Methods, closures and arrow functions are
     * not declarations at file scope and are skipped.
     *
     * @param string $path
     *
     * @return array<string,bool> lowercased name => guarded, empty when unreadable
     */
    public static function declaredIn($path)
    {
        if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) {
            return array();
        }

        $source = file_get_contents($path);
        if ($source === false) {
            return array();
        }

        $tokens = @token_get_all($source);
        if (!is_array($tokens)) {
            return array();
        }

        $found = array();
        $depth = 0;
        // Brace depth at which a class/interface/trait/enum body started; a function
        // inside one is a method, not a declaration this cares about.
        $typeDepths = array();
        // Brace depth => the names that block's `if (!function_exists(...))` guards.
        $guards = array();
        // Guard names collected from the condition currently being read.
        $pending = array();
        $inCondition = false;
        $conditionDepth = 0;

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '{') {
                $depth++;
                if ($pending !== array()) {
                    $guards[$depth] = $pending;
                    $pending        = array();
                }
                continue;
            }
            if ($token === '}') {
                unset($guards[$depth]);
                $typeDepths = array_values(array_filter($typeDepths, static function ($d) use ($depth) {
                    return $d !== $depth;
                }));
                $depth--;
                continue;
            }

            if ($inCondition) {
                if ($token === '(') {
                    $conditionDepth++;
                } elseif ($token === ')') {
                    $conditionDepth--;
                    if ($conditionDepth === 0) {
                        $inCondition = false;
                    }
                } elseif (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $pending[] = strtolower(trim($token[1], "'\""));
                }
                continue;
            }

            if (!is_array($token)) {
                continue;
            }

            if (in_array($token[0], array(T_CLASS, T_INTERFACE, T_TRAIT), true)
                || (defined('T_ENUM') && $token[0] === T_ENUM)
            ) {
                // The body opens at the next '{'; record the depth it will sit at.
                $typeDepths[] = $depth + 1;
                continue;
            }

            if ($token[0] === T_STRING && strtolower($token[1]) === 'function_exists') {
                // Collect the names this condition tests, until its parentheses close.
                $inCondition    = true;
                $conditionDepth = 0;
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j] === '(') {
                        $conditionDepth = 1;
                        $i              = $j;
                        break;
                    }
                }
                continue;
            }

            if ($token[0] !== T_FUNCTION) {
                continue;
            }
            if (in_array($depth, $typeDepths, true)) {
                continue; // a method
            }

            // The next meaningful token is the name; anything else is a closure.
            $name = null;
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j])
                    && in_array($tokens[$j][0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true)
                ) {
                    continue;
                }
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $name = strtolower($tokens[$j][1]);
                }
                break;
            }
            if ($name === null) {
                continue;
            }

            $guarded = false;
            foreach ($guards as $names) {
                if (in_array($name, $names, true)) {
                    $guarded = true;
                    break;
                }
            }
            // A name declared twice in one file is already that file's own problem; the
            // guarded reading is the one that matters here.
            $found[$name] = isset($found[$name]) ? ($found[$name] || $guarded) : $guarded;
        }

        return $found;
    }

    /**
     * The function names that would make a fatal if $childPath and $parentPath both ran.
     *
     * Only a name the parent declares **unguarded** is reported: a guarded one is the
     * child overriding it, which is the whole point of a child theme.
     *
     * @param string $childPath  the child theme's functions.php
     * @param string $parentPath the parent theme's functions.php
     *
     * @return array<int,string> sorted, empty when the pair is safe
     */
    public static function collisions($childPath, $parentPath)
    {
        $child  = self::declaredIn($childPath);
        $parent = self::declaredIn($parentPath);

        $clash = array();
        foreach ($child as $name => $childGuarded) {
            if (isset($parent[$name]) && $parent[$name] === false) {
                $clash[] = $name;
            }
        }
        sort($clash);

        return $clash;
    }
}
