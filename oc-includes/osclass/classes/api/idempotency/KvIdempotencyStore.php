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

namespace mindstellar\api\idempotency;

use mindstellar\model\KeyValue;

/**
 * Idempotency-Keys in t_key_value, group `api_idempotency`, keyed by the key's hash. The value is
 * JSON holding the request's fingerprint and, while the first request runs, its lock token; then
 * its HTTP status and answer. The state is `locked` while the first request runs, then `done`.
 */
final class KvIdempotencyStore implements IdempotencyStore
{
    public const GROUP = 'api_idempotency';

    private KeyValue $kv;

    public function __construct(?KeyValue $kv = null)
    {
        $this->kv = $kv ?? new KeyValue();
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public function claim(string $hash, string $fingerprint, int $now, int $expiresAt, int $lockTtl, string $lock): ?IdempotencyRecord
    {
        $value = self::encode($fingerprint, $lock);
        for ($try = 0; $try < 2; $try++) {
            if ($this->kv->claim(self::GROUP, $hash, $expiresAt, IdempotencyRecord::LOCKED, $value, $now)) {
                return null;
            }
            $row = $this->kv->get(self::GROUP, $hash, $now);
            if ($row === null) {
                continue;
            }
            $record = self::toRecord($row);
            if ($row['state'] === IdempotencyRecord::LOCKED && $row['created'] < $now - $lockTtl
                && hash_equals($record->fingerprint(), $fingerprint)
                && $this->kv->claim(self::GROUP, $hash, $expiresAt, IdempotencyRecord::LOCKED, $value, $now, $now - $lockTtl)
            ) {
                return null;
            }

            return $record;
        }

        // Gone again between two reads: report it as running, so the caller retries.
        return new IdempotencyRecord($fingerprint, IdempotencyRecord::LOCKED);
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public function complete(string $hash, string $lock, int $status, string $response): void
    {
        $held = $this->held($hash, $lock);
        if ($held === null) {
            return;
        }
        $this->kv->update(
            self::GROUP,
            $hash,
            self::encode((string) (json_decode($held, true)['fingerprint'] ?? ''), null, $status, $response),
            IdempotencyRecord::DONE,
            IdempotencyRecord::LOCKED,
            onlyValue: $held
        );
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public function release(string $hash, string $lock): void
    {
        $held = $this->held($hash, $lock);
        if ($held !== null) {
            $this->kv->delete(self::GROUP, $hash, IdempotencyRecord::LOCKED, $held);
        }
    }

    /**
     * The stored value while the key is still locked by $lock, so a write can be made only on it.
     *
     * @throws \mindstellar\database\DbException
     */
    private function held(string $hash, string $lock): ?string
    {
        $row  = $this->kv->get(self::GROUP, $hash);
        $data = $row !== null && $row['state'] === IdempotencyRecord::LOCKED ? json_decode((string) $row['value'], true) : null;

        return is_array($data) && is_string($data['lock'] ?? null) && hash_equals($data['lock'], $lock) ? (string) $row['value'] : null;
    }

    private static function encode(string $fingerprint, ?string $lock, ?int $status = null, ?string $response = null): string
    {
        return (string) json_encode(
            ['fingerprint' => $fingerprint, 'lock' => $lock, 'status' => $status, 'response' => $response],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /**
     * @param array{value:?string, state:?string} $row
     */
    private static function toRecord(array $row): IdempotencyRecord
    {
        $data     = json_decode((string) $row['value'], true);
        $response = is_array($data) && is_string($data['response'] ?? null) ? $data['response'] : null;

        return new IdempotencyRecord(
            is_array($data) ? (string) ($data['fingerprint'] ?? '') : '',
            (string) $row['state'],
            $response
        );
    }
}
