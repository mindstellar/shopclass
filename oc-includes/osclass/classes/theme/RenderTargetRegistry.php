<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\theme;

use InvalidArgumentException;

/**
 * Registry of named render targets: opaque id -> absolute file path.
 *
 * osc_render_file() consults this registry before its own two filesystem checks
 * (theme plugins/ dir, then oc-content/plugins/). It is how a core file outside
 * those directories -- e.g. an oc-includes/ partial -- can be rendered through
 * ?page=custom&file=<id> without widening path resolution to arbitrary core
 * files: only ids explicitly registered here are ever reachable that way, and
 * the request only ever supplies the id, never the path.
 *
 * @package mindstellar\theme
 */
final class RenderTargetRegistry
{
    private static ?self $instance = null;

    /** @var array<string,string> absolute path, keyed by target id */
    private array $targets = [];

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register a render target.
     *
     * @param string $id   Namespaced slug, [a-z0-9_-]+(/[a-z0-9_-]+)*, max 80 chars.
     * @param string $path Absolute path to an existing .php file.
     *
     * @throws InvalidArgumentException on an invalid id or path.
     */
    public function register(string $id, string $path): void
    {
        if (!self::isValidId($id)) {
            throw new InvalidArgumentException(
                'RenderTargetRegistry: invalid render target id "' . $id . '" (expected [a-z0-9_-]+(/[a-z0-9_-]+)*, max 80 chars)'
            );
        }

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php' || !is_file($path)) {
            throw new InvalidArgumentException(
                'RenderTargetRegistry: render target "' . $id . '" must point at an existing .php file'
            );
        }

        $this->targets[$id] = $path;
    }

    /**
     * Absolute path registered for $id, or null when nothing is registered under it.
     */
    public function get(string $id): ?string
    {
        return $this->targets[$id] ?? null;
    }

    /**
     * Whether $id is a well-formed render target id.
     */
    public static function isValidId(string $id): bool
    {
        return $id !== '' && strlen($id) <= 80 && (bool) preg_match('/^[a-z0-9_-]+(\/[a-z0-9_-]+)*$/', $id);
    }
}
