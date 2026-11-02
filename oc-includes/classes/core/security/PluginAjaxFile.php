<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\security;

/**
 * Resolve the `ajaxfile` of the `custom` ajax action to a file that is safe to run.
 *
 * That action ends in `require_once`, so whatever it resolves to is executed as
 * PHP. It guarded only against the literal strings '../' and '..\', which keeps
 * the include inside the plugins directory but says nothing about *what* is
 * included. The plugins tree is writable (that is how plugins install) and holds
 * plenty of non-PHP files -- README.md, composer.lock, .mo catalogues, whatever a
 * plugin unpacks or an upload handler drops there. Any one of those, included,
 * runs as code, so a plugin that stores caller-supplied content under its own
 * folder turned a path parameter into arbitrary execution.
 *
 * Two rules close that, and neither narrows what a working plugin can ask for:
 *
 *   extension   the target must end in .php. Every file reachable this way is
 *               one a plugin meant to execute; nothing else in the tree is.
 *   containment no '..' segment, and the file's realpath() must sit inside the
 *               realpath() of the plugin folder it names (the first path segment).
 *               A plugin or theme folder may itself be a symlink, which is common
 *               in development; a link inside it that points out is still refused.
 *
 * osc_ajax_plugin_url() -- the public builder plugins use to reach here -- always
 * names a .php file inside the plugins directory, so callers that were working
 * keep working.
 */
class PluginAjaxFile
{
    /**
     * Resolve a requested ajax file to an absolute path, or refuse it.
     *
     * @param string $file  path relative to the plugins directory
     * @param string $root  absolute plugins directory, i.e. osc_plugins_path()
     *
     * @return string|null the absolute path to include, or null when it is not safe to
     */
    public static function resolve($file, $root)
    {
        $file = (string) $file;
        $root = (string) $root;

        // A NUL byte truncates the path for the filesystem call but not for the
        // checks above it, so it is never part of a legitimate request.
        if ($root === '' || strpos($file, "\0") !== false) {
            return null;
        }

        // Only ever a file the plugin meant to run.
        if (strtolower((string) pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
            return null;
        }

        $parts    = self::segments($file);
        $realRoot = realpath($root);
        if ($parts === null || $realRoot === false) {
            return null;
        }

        $folder   = count($parts) > 1 ? realpath($realRoot . DIRECTORY_SEPARATOR . $parts[0]) : $realRoot;
        $realFile = realpath($realRoot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts));
        if ($folder === false || $realFile === false || !is_dir($folder) || !is_file($realFile)) {
            return null;
        }

        // The separator stops a sibling such as "plugins-backup" matching "plugins".
        $folder .= DIRECTORY_SEPARATOR;
        if (strncmp($realFile, $folder, strlen($folder)) !== 0) {
            return null;
        }

        return $realFile;
    }

    /**
     * Resolve a file given relative to $base, accepting it only when it is a .php file
     * inside one of $roots.
     *
     * @param string   $file  path relative to $base
     * @param string   $base  absolute base directory
     * @param string[] $roots absolute directories the file may live in
     *
     * @return string|null the absolute path, or null when it is not safe to include
     */
    public static function resolveWithin($file, $base, array $roots)
    {
        $parts = strpos((string) $file, "\0") === false ? self::segments((string) $file) : null;
        if ($parts === null) {
            return null;
        }
        $relative = implode(DIRECTORY_SEPARATOR, $parts);
        // The path as written first, then with symlinks resolved, in case base and root are spelt differently.
        $pairs = array(array((string) $base, null), array(realpath((string) $base), true));
        foreach ($pairs as list($dir, $real)) {
            if ($dir === false || $dir === '') {
                continue;
            }
            $full = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $relative;
            foreach ($roots as $root) {
                $root = $real ? realpath((string) $root) : (string) $root;
                if ($root === false || $root === '') {
                    continue;
                }
                $prefix = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
                if (strncmp($full, $prefix, strlen($prefix)) === 0) {
                    return self::resolve(substr($full, strlen($prefix)), $root);
                }
            }
        }

        return null;
    }

    /**
     * A relative path split into its segments, or null when it is empty or has a '..'.
     *
     * @param string $file
     *
     * @return string[]|null
     */
    private static function segments(string $file): ?array
    {
        $parts = array();
        foreach (explode('/', str_replace('\\', '/', $file)) as $part) {
            if ($part === '..') {
                return null;
            }
            if ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        return $parts === array() ? null : $parts;
    }
}
