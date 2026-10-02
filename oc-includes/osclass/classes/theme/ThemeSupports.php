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

    /** True while a parent theme's functions.php is being loaded. */
    private bool $inherited = false;

    /**
     * Singleton: obtain the registry through instance().
     */
    private function __construct()
    {
    }

    /**
     * Shared registry instance, created on first use.
     *
     * @return self
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Declare that the active theme supports $feature.
     *
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
        // A parent theme fills gaps; it does not overrule the child that chose it. The
        // child's functions.php is required first, so without this the newest value wins
        // and every contested feature goes to the parent.
        if ($this->inherited && array_key_exists($feature, $this->features)) {
            return;
        }
        $this->features[$feature] = $args;
    }

    /**
     * Declarations from here until endInherited() are a parent theme's: they fill in
     * features the child left unsaid and leave the rest alone.
     *
     * @return void
     */
    public function beginInherited(): void
    {
        $this->inherited = true;
    }

    /**
     * Back to ordinary declarations, where the newest value wins.
     *
     * @return void
     */
    public function endInherited(): void
    {
        $this->inherited = false;
    }

    /**
     * Declared arguments for $feature, or false when it was never registered.
     *
     * @param string $feature
     *
     * @return mixed
     */
    public function get(string $feature)
    {
        return $this->features[$feature] ?? false;
    }

    /**
     * Drop a declaration, leaving the feature unsupported again.
     *
     * @param string $feature
     */
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
        $this->features  = [];
        $this->inherited = false;
    }

    /**
     * Whether $feature is a well-formed feature name.
     *
     * @param string $feature
     *
     * @return bool
     */
    private static function isValidFeature(string $feature): bool
    {
        return $feature !== ''
               && strlen($feature) <= 60
               && (bool) preg_match('/^[a-z0-9_-]+$/', $feature);
    }
}
