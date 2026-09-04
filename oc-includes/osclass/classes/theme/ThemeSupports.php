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
 * What the active theme declares it can do: feature name -> arguments.
 *
 * Core has never been able to ask a theme anything, so it guesses filenames and
 * renders its own page when the guess misses. A theme registers here from its
 * functions.php, which WebThemes::loadActive() requires after the helpers are
 * defined.
 *
 * Declaring is optional at every call site: a feature nobody registered reads as
 * unsupported, and core falls back to what it did before.
 *
 * @package mindstellar\theme
 */
final class ThemeSupports
{
    private static ?self $instance = null;

    /** @var array<string,mixed> feature arguments, keyed by feature name */
    private array $features = [];

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
     * @param string $feature Slug, [a-z0-9_-]+, max 60 chars.
     * @param mixed  $args    Feature arguments, or true for a bare flag.
     *
     * @throws InvalidArgumentException on an invalid feature name.
     */
    public function add(string $feature, $args = true): void
    {
        if (!self::isValidFeature($feature)) {
            throw new InvalidArgumentException(
                'ThemeSupports: invalid feature name "' . $feature . '" (expected [a-z0-9_-]+, max 60 chars)'
            );
        }
        $this->features[$feature] = $args;
    }

    /**
     * Declared arguments for $feature, or false when it was never registered.
     *
     * @return mixed
     */
    public function get(string $feature)
    {
        return $this->features[$feature] ?? false;
    }

    public function remove(string $feature): void
    {
        unset($this->features[$feature]);
    }

    /**
     * Forget every registration. For tests, and for a theme switch inside one
     * request -- the admin theme previewer loads a second functions.php.
     */
    public function reset(): void
    {
        $this->features = [];
    }

    private static function isValidFeature(string $feature): bool
    {
        return $feature !== ''
               && strlen($feature) <= 60
               && (bool) preg_match('/^[a-z0-9_-]+$/', $feature);
    }
}
