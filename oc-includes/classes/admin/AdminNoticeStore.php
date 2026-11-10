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

use mindstellar\model\KeyValue;

/**
 * The admin_notice rows in t_key_value. Reads the whole group once, and keeps that copy current
 * after each write.
 */
final class AdminNoticeStore
{
    private const GROUP      = 'admin_notice';
    private const ROW_LIMIT  = 1000;
    private const CHECKS     = 'checks';
    private const LOCK       = 'checks:lock';
    private const LOCK_SECS  = 60;

    private KeyValue $kv;
    private \Closure $clock;

    /** @var array<string,array{value:?string, expires:?int}>|null */
    private ?array $rows = null;

    public function __construct(?KeyValue $kv = null, ?callable $clock = null)
    {
        $this->kv    = $kv ?? new KeyValue();
        $this->clock = $clock === null ? time(...) : \Closure::fromCallable($clock);
    }

    public function load(): void
    {
        if ($this->rows === null) {
            $this->reload();
        }
    }

    /**
     * Read the group again, for writers that may run long after the first read.
     */
    public function reload(): void
    {
        $this->rows = $this->kv->group(self::GROUP, self::ROW_LIMIT, ($this->clock)());
    }

    /**
     * @return array<string,AdminNotice> by id
     */
    public function oneOffs(): array
    {
        $out = [];
        foreach ($this->rowsUnder('n:') as $id => $row) {
            if (($row['id'] ?? null) === (string) $id) {
                $out[(string) $id] = AdminNotice::fromArray($row);
            }
        }

        return $out;
    }

    public function oneOffExpires(string $id): ?int
    {
        $this->load();

        return $this->rows['n:' . $id]['expires'] ?? null;
    }

    /**
     * @return array<string,int> id => the time the standing check first fired
     */
    public function since(): array
    {
        $out = [];
        foreach ($this->rowsUnder('since:') as $id => $row) {
            $out[(string) $id] = (int) ($row['at'] ?? 0);
        }

        return $out;
    }

    /**
     * @return array<string,int> id => the time this admin's hide ends
     */
    public function snoozed(int $admin): array
    {
        $out = [];
        foreach ($this->rowsUnder('snooze:' . $admin . ':') as $id => $row) {
            $out[(string) $id] = (int) ($row['until'] ?? 0);
        }

        return $out;
    }

    /**
     * @return array{at:int, env:array<string,mixed>}|null
     */
    public function checks(): ?array
    {
        $row = $this->decoded(self::CHECKS);
        if ($row === null || !is_array($row['env'] ?? null)) {
            return null;
        }

        return ['at' => (int) ($row['at'] ?? 0), 'env' => $row['env']];
    }

    /**
     * @param array<string,mixed> $env
     */
    public function saveChecks(array $env, int $now): void
    {
        // No expiry: a row that expired would empty the bell until the next refresh.
        $this->write(self::CHECKS, ['at' => $now, 'env' => $env], null);
    }

    public function forgetChecks(): void
    {
        $this->kv->delete(self::GROUP, self::CHECKS);
        unset($this->rows[self::CHECKS]);
    }

    /**
     * @return bool true when this request is the one to refresh the checks
     */
    public function claimRefresh(int $now): bool
    {
        return $this->kv->claim(self::GROUP, self::LOCK, $now + self::LOCK_SECS, 'locked', null, $now);
    }

    public function releaseRefresh(): void
    {
        $this->kv->delete(self::GROUP, self::LOCK);
    }

    public function putOneOff(AdminNotice $n, int $ttl): void
    {
        $this->write('n:' . $n->id, $n->toArray(), ($this->clock)() + $ttl);
    }

    public function deleteOneOff(string $id): void
    {
        $this->remove('n:' . $id);
    }

    public function setSince(string $id, int $at): void
    {
        $this->write('since:' . $id, ['at' => $at], null);
    }

    public function deleteSince(string $id): void
    {
        $this->remove('since:' . $id);
    }

    public function snooze(int $admin, string $id, int $until): void
    {
        $this->write('snooze:' . $admin . ':' . $id, ['until' => $until], $until);
    }

    public function unsnooze(int $admin, string $id): void
    {
        $this->remove('snooze:' . $admin . ':' . $id);
    }

    /**
     * Remove every admin's hide of a notice.
     */
    public function deleteSnoozes(string $id): void
    {
        $this->load();
        foreach (array_keys($this->rows ?? []) as $key) {
            $parts = explode(':', (string) $key, 3);
            if ($parts[0] === 'snooze' && ($parts[2] ?? null) === $id) {
                $this->remove((string) $key);
            }
        }
    }

    /**
     * @return array<string,array<string,mixed>> what follows the prefix => the decoded value
     */
    private function rowsUnder(string $prefix): array
    {
        $this->load();
        $out = [];
        foreach (array_keys($this->rows ?? []) as $key) {
            $key = (string) $key;
            if (str_starts_with($key, $prefix) && ($row = $this->decoded($key)) !== null) {
                $out[substr($key, strlen($prefix))] = $row;
            }
        }

        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decoded(string $key): ?array
    {
        $this->load();
        $value = json_decode((string) ($this->rows[$key]['value'] ?? ''), true);

        return is_array($value) ? $value : null;
    }

    /**
     * @param array<string,mixed> $value
     */
    private function write(string $key, array $value, ?int $expires): void
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->kv->set(self::GROUP, $key, $json, $expires, null, ($this->clock)());
        $this->load();
        $this->rows[$key] = ['value' => $json, 'expires' => $expires];
    }

    private function remove(string $key): void
    {
        $this->load();
        if (isset($this->rows[$key])) {
            $this->kv->delete(self::GROUP, $key);
            unset($this->rows[$key]);
        }
    }
}
