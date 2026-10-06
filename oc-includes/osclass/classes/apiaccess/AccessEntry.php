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

namespace mindstellar\apiaccess;

/**
 * One thing that acts for a user: a sign-in (a refresh family) or a personal key.
 */
final class AccessEntry
{
    public const TOKEN = 'token';
    public const KEY   = 'key';

    private function __construct(private string $id, private string $type, private StoredKey $row)
    {
    }

    /**
     * A sign-in, by the newest row of its refresh family.
     */
    public static function signIn(StoredKey $row): self
    {
        return new self((string) $row->family(), self::TOKEN, $row);
    }

    public static function key(StoredKey $row): self
    {
        return new self('key-' . $row->id(), self::KEY, $row);
    }

    /**
     * The family for a sign-in, `key-<id>` for a key.
     */
    public function id(): string
    {
        return $this->id;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function isKey(): bool
    {
        return $this->type === self::KEY;
    }

    public function row(): StoredKey
    {
        return $this->row;
    }

    /**
     * A key's public prefix; null for a sign-in.
     */
    public function prefix(): ?string
    {
        return $this->isKey() ? ApiKeys::KEY_PREFIX . $this->row->tokenId() : null;
    }

    /**
     * Whether this is the sign-in or key that made the request.
     */
    public function isCurrent(Credential $credential): bool
    {
        return $this->isKey() ? $credential->id() === $this->row->id() : $credential->family() === $this->row->family();
    }

    /**
     * The entry as `GET /account/sessions` lists it.
     *
     * @param Credential $credential the caller, to mark the session making the request
     *
     * @return array<string,mixed>
     */
    public function toArray(Credential $credential): array
    {
        return [
            'id'           => $this->id,
            'type'         => $this->type,
            'label'        => $this->row->name(),
            'prefix'       => $this->prefix(),
            'scopes'       => $this->row->scopes(),
            'last_used_at' => self::timestamp($this->row->lastUsedAt() ?? $this->row->createdAt()),
            'last_ip'      => $this->row->lastIp() === '' ? null : $this->row->lastIp(),
            'expires_at'   => self::timestamp($this->row->expiresAt()),
            'current'      => $this->isCurrent($credential),
        ];
    }

    private static function timestamp(?int $time): ?string
    {
        return $time === null ? null : gmdate('Y-m-d\TH:i:s\Z', $time);
    }
}
