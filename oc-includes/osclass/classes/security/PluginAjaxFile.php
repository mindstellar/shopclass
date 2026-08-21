<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
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
 *   containment realpath() the candidate and require the plugins directory to be
 *               its prefix. String matching on '../' misses symlinks and the
 *               encodings a filesystem accepts, whereas resolving the path first
 *               answers the only question that matters: where did it land.
 *
 * osc_ajax_plugin_url() -- the public builder plugins use to reach here -- always
 * names a .php file inside the plugins directory, so callers that were working
 * keep working.
 */
class PluginAjaxFile
{
    /**
     * @param string $file  path relative to the plugins directory
     * @param string $root  absolute plugins directory, i.e. osc_plugins_path()
     *
     * @return string|null the absolute path to include, or null when it is not safe to
     */
    public static function resolve($file, $root)
    {
        $file = (string) $file;
        $root = (string) $root;

        if ($file === '' || $root === '') {
            return null;
        }

        // A NUL byte truncates the path for the filesystem call but not for the
        // checks above it, so it is never part of a legitimate request.
        if (strpos($file, "\0") !== false) {
            return null;
        }

        // Only ever a file the plugin meant to run.
        if (strtolower((string) pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
            return null;
        }

        $realRoot = realpath($root);
        $realFile = realpath(rtrim($root, '/\\') . DIRECTORY_SEPARATOR . ltrim($file, '/\\'));

        if ($realRoot === false || $realFile === false || !is_file($realFile)) {
            return null;
        }

        // Compare with the separator appended so a sibling directory whose name
        // merely starts with the plugins path ("plugins-backup") is not a prefix
        // match for it.
        $realRoot .= DIRECTORY_SEPARATOR;
        if (strncmp($realFile, $realRoot, strlen($realRoot)) !== 0) {
            return null;
        }

        return $realFile;
    }
}
