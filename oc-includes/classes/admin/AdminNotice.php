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

namespace mindstellar\admin;

/**
 * One line in the admin bell: a site problem or a plugin warning.
 */
final readonly class AdminNotice
{
    public function __construct(
        public string $id,
        public string $type,
        public string $text,
        public string $detail = '',
        public string $link = '',
        public string $linkLabel = '',
        public string $menu = 'dash',
        public string $submenu = '',
        public string $source = '',
        public bool $moderator = false,
        public int $since = 0,
        public int $hiddenUntil = 0
    ) {
    }

    /**
     * @return string 'action' for an error, 'headsup' for a warning or info
     */
    public function group(): string
    {
        return $this->type === 'error' ? 'action' : 'headsup';
    }

    public function withSince(int $since): self
    {
        return self::fromArray(['since' => $since] + $this->toArray());
    }

    public function withHiddenUntil(int $at): self
    {
        return self::fromArray(['hiddenUntil' => $at] + $this->toArray());
    }

    /**
     * @return array<string,string|int|bool>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'type'        => $this->type,
            'text'        => $this->text,
            'detail'      => $this->detail,
            'link'        => $this->link,
            'linkLabel'   => $this->linkLabel,
            'menu'        => $this->menu,
            'submenu'     => $this->submenu,
            'source'      => $this->source,
            'moderator'   => $this->moderator,
            'since'       => $this->since,
            'hiddenUntil' => $this->hiddenUntil,
        ];
    }

    /**
     * @param array<string,mixed> $a what toArray() gave; a missing or wrong-typed field takes its default
     */
    public static function fromArray(array $a): self
    {
        $text = static fn (string $key, string $default = ''): string => is_scalar($a[$key] ?? null) ? (string) $a[$key] : $default;

        return new self(
            $text('id'),
            $text('type'),
            $text('text'),
            $text('detail'),
            $text('link'),
            $text('linkLabel'),
            $text('menu', 'dash'),
            $text('submenu'),
            $text('source'),
            !empty($a['moderator']),
            (int) $text('since', '0'),
            (int) $text('hiddenUntil', '0')
        );
    }
}
