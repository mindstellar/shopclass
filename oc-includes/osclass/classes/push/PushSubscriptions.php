<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\push;

use mindstellar\database\Db;
use mindstellar\database\UtcDatetime;
use mindstellar\model\KeyValue;
use mindstellar\security\AddressGuard;
use mindstellar\utility\Clock;

/**
 * The browsers each user asked to wake for new messages, one t_key_value row per browser under
 * "<user id>:<browser id>". A row keeps the endpoint, the browser family and when it was added;
 * no push keys, since a push carries no payload. Rows expire 90 days after the last renewal.
 */
final class PushSubscriptions
{
    public const GROUP        = 'push';
    public const TTL          = 7776000;
    public const MAX_ENDPOINT = 1024;
    public const MAX_PER_USER = 20;

    public function __construct(private KeyValue $kv, private Clock $clock, private AddressGuard $guard)
    {
    }

    /**
     * Keep $endpoint for the user, or refresh it when it is already kept.
     *
     * @return string the browser id
     * @throws \InvalidArgumentException for an endpoint that is not a public https:// URL of at most 1024 bytes
     * @throws \mindstellar\database\DbException
     */
    public function add(int $userId, string $endpoint, string $ua): string
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('No user to keep the browser for.');
        }
        if ($endpoint === '' || strlen($endpoint) > self::MAX_ENDPOINT) {
            throw new \InvalidArgumentException('The push address is empty or too long.');
        }
        if (strtolower((string) parse_url($endpoint, PHP_URL_SCHEME)) !== 'https') {
            throw new \InvalidArgumentException('The push address must start with https://.');
        }
        $check = $this->guard->check($endpoint);
        if (!$check['ok']) {
            throw new \InvalidArgumentException((string) ($check['error'] ?? 'The push address is not allowed.'));
        }
        $now     = $this->clock->now();
        $browser = self::browserId($endpoint);
        $value   = json_encode(['endpoint' => $endpoint, 'ua' => self::browserFamily($ua), 'seen' => $now], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->kv->set(self::GROUP, $userId . ':' . $browser, $value, $now + self::TTL, null, $now);

        return $browser;
    }

    /**
     * Push the row's expiry to 90 days from now.
     *
     * @return bool false when the user has no row for $endpoint
     * @throws \mindstellar\database\DbException
     */
    public function renew(int $userId, string $endpoint): bool
    {
        if ($userId <= 0 || $endpoint === '') {
            return false;
        }
        $key = $userId . ':' . self::browserId($endpoint);
        $now = $this->clock->now();
        if ($this->kv->touch(self::GROUP, $key, $now + self::TTL, $now) > 0) {
            return true;
        }

        // A second renewal in the same second changes no row, so it counts nothing.
        return $this->kv->get(self::GROUP, $key, $now) !== null;
    }

    /**
     * @return int rows removed
     * @throws \mindstellar\database\DbException
     */
    public function remove(int $userId, string $endpoint): int
    {
        if ($userId <= 0 || $endpoint === '') {
            return 0;
        }

        return $this->kv->delete(self::GROUP, $userId . ':' . self::browserId($endpoint));
    }

    /**
     * Remove a row by its full key, as forUser() keys it; for a browser the push service says is gone.
     *
     * @return int rows removed
     * @throws \mindstellar\database\DbException
     */
    public function removeEndpoint(string $key): int
    {
        if (preg_match('/^[1-9][0-9]*:[A-Za-z0-9_-]{22}$/D', $key) !== 1) {
            return 0;
        }

        return $this->kv->delete(self::GROUP, $key);
    }

    /**
     * The user's live browsers, by full key, at most 20.
     *
     * @return array<string, array{endpoint:string, ua:string, seen:int}>
     * @throws \mindstellar\database\DbException
     */
    public function forUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $prefix = $userId . ':';
        $out    = [];
        foreach ($this->kv->page(self::GROUP, self::MAX_PER_USER, null, $prefix, $this->clock->now()) as $row) {
            // Keys sort as text, so the next user's rows follow straight after this one's.
            if (!str_starts_with($row['key'], $prefix)) {
                break;
            }
            $data = json_decode((string) $row['value'], true);
            if (!is_array($data) || !is_string($data['endpoint'] ?? null)) {
                continue;
            }
            $out[$row['key']] = ['endpoint' => $data['endpoint'], 'ua' => (string) ($data['ua'] ?? ''), 'seen' => (int) ($data['seen'] ?? 0)];
        }

        return $out;
    }

    /**
     * @return int rows removed
     * @throws \mindstellar\database\DbException
     */
    public function removeUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        return Db::table(DB_TABLE_PREFIX . KeyValue::TABLE)
            ->where('s_group', self::GROUP)
            ->whereRaw('s_key LIKE ?', [$userId . ':%'])
            ->delete();
    }

    /**
     * Live browser rows of every user.
     *
     * @throws \mindstellar\database\DbException
     */
    public function count(): int
    {
        $now = UtcDatetime::format($this->clock->now());

        return Db::table(DB_TABLE_PREFIX . KeyValue::TABLE)
            ->where('s_group', self::GROUP)
            ->whereRaw('s_key LIKE ?', ['%:%'])
            ->whereGroup(static fn ($q) => $q->whereNull('dt_expires')->orWhere('dt_expires', '>', $now))
            ->count();
    }

    /**
     * The first 22 characters of base64url(sha256(endpoint)).
     */
    public static function browserId(string $endpoint): string
    {
        return substr(rtrim(strtr(base64_encode(hash('sha256', $endpoint, true)), '+/', '-_'), '='), 0, 22);
    }

    /**
     * The browser family of a User-Agent, and nothing more of it.
     */
    public static function browserFamily(string $ua): string
    {
        foreach (['Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser/' => 'Samsung Internet', 'Firefox/' => 'Firefox', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'] as $needle => $family) {
            if (str_contains($ua, $needle)) {
                return $family;
            }
        }

        return $ua === '' ? '' : 'Other';
    }
}
