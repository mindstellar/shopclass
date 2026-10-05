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
 * JSON holding the request's fingerprint, then its HTTP status and answer; the state is
 * `locked` while the first request runs, then `done`.
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
    public function claim(string $hash, string $fingerprint, int $now, int $expiresAt, int $lockTtl): ?IdempotencyRecord
    {
        $value = self::encode($fingerprint);
        for ($try = 0; $try < 2; $try++) {
            if ($this->kv->claim(self::GROUP, $hash, $expiresAt, IdempotencyRecord::LOCKED, $value, $now, $now - $lockTtl)) {
                return null;
            }
            $row = $this->kv->get(self::GROUP, $hash, $now);
            if ($row !== null) {
                return self::toRecord($row);
            }
        }

        // Gone again between two reads: report it as running, so the caller retries.
        return new IdempotencyRecord($fingerprint, IdempotencyRecord::LOCKED);
    }

    /**
     * Keep the answer, only while this request still holds the lock: a lock that went stale
     * and was taken over belongs to the request that took it.
     *
     * @throws \mindstellar\database\DbException
     */
    public function complete(string $hash, int $status, string $response): void
    {
        $row = $this->kv->get(self::GROUP, $hash);
        if ($row === null || $row['state'] !== IdempotencyRecord::LOCKED) {
            return;
        }
        $this->kv->update(
            self::GROUP,
            $hash,
            self::encode(self::toRecord($row)->fingerprint(), $status, $response),
            IdempotencyRecord::DONE,
            IdempotencyRecord::LOCKED
        );
    }

    /**
     * @throws \mindstellar\database\DbException
     */
    public function release(string $hash): void
    {
        $this->kv->delete(self::GROUP, $hash, IdempotencyRecord::LOCKED);
    }

    private static function encode(string $fingerprint, ?int $status = null, ?string $response = null): string
    {
        return (string) json_encode(
            ['fingerprint' => $fingerprint, 'status' => $status, 'response' => $response],
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
