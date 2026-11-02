<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\widgets;

/**
 * One admin's unpublished customiser changes for one theme: a single t_key_value row in the
 * group 'customiser', keyed '<theme>:<adminId>', that expires after seven days.
 *
 * Shape: {theme, locations: {slug: [{id, key, type, description, config}]}, settings: {name:
 * value}, updated_at}. What goes in has already been sanitised by Customiser.
 */
final class CustomiserDraft
{
    public const GROUP     = 'customiser';
    public const TTL       = 604800;
    public const MAX_BYTES = 262144;

    private string $theme;

    private int $adminId;

    public function __construct(string $theme, int $adminId)
    {
        $this->theme   = $theme;
        $this->adminId = $adminId;
    }

    public function theme(): string
    {
        return $this->theme;
    }

    public function key(): string
    {
        return $this->theme . ':' . $this->adminId;
    }

    /**
     * The stored draft, or null when there is none, it expired, or it is for another theme.
     * A database error is thrown, never read as "no draft".
     *
     * @throws \mindstellar\database\DbException
     * @return array{theme:string,locations:array<string,array<int,array<string,mixed>>>,settings:array<string,mixed>,base:array<string,string>,updated_at:int}|null
     */
    public function read(): ?array
    {
        if ($this->theme === '' || $this->adminId <= 0) {
            return null;
        }
        try {
            $draft = osc_kv_get(self::GROUP, $this->key());
        } catch (\InvalidArgumentException $e) {
            return null;
        }
        if (!is_array($draft) || ($draft['theme'] ?? null) !== $this->theme) {
            return null;
        }

        return array(
            'theme'      => $this->theme,
            'locations'  => is_array($draft['locations'] ?? null) ? $draft['locations'] : array(),
            'settings'   => is_array($draft['settings'] ?? null) ? $draft['settings'] : array(),
            'base'       => is_array($draft['base'] ?? null) ? $draft['base'] : array(),
            'updated_at' => (int) ($draft['updated_at'] ?? 0),
        );
    }

    /**
     * Store the draft, replacing the one there was.
     *
     * @param array<string,mixed> $draft
     *
     * @return int the time it was saved
     * @throws \LengthException when the encoded draft is over MAX_BYTES; nothing is stored
     * @throws \mindstellar\database\DbException
     */
    public function save(array $draft): int
    {
        $now   = time();
        $value = array(
            'theme'      => $this->theme,
            'locations'  => (array) ($draft['locations'] ?? array()),
            'settings'   => (array) ($draft['settings'] ?? array()),
            'base'       => (array) ($draft['base'] ?? array()),
            'updated_at' => $now,
        );
        if (self::bytes($value) > self::MAX_BYTES) {
            throw new \LengthException('The draft is too large.');
        }
        osc_kv_set(self::GROUP, $this->key(), $value, self::TTL);

        return $now;
    }

    /**
     * Forget the draft. Images it uploaded are Customiser's to remove first.
     *
     * @throws \mindstellar\database\DbException
     */
    public function discard(): void
    {
        osc_kv_delete(self::GROUP, $this->key());
    }

    /**
     * Encoded size of a draft value, as it would be stored.
     *
     * @param array<string,mixed> $value
     */
    public static function bytes(array $value): int
    {
        return strlen((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
