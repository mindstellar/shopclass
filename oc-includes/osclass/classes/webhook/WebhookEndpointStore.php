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

namespace mindstellar\webhook;

use mindstellar\apikey\ApiSettings;
use mindstellar\database\RowHashQuery;
use mindstellar\model\KeyValue;

/**
 * Webhook endpoints in the shared t_key_value store, group `api_webhook`, one key per endpoint id.
 *
 * Every write is a compare-and-swap on the row's state column, which holds a version
 * (`v1`, `v2` ...): change() reads the row, applies the change and writes it only while the
 * state is still the version it read, else reads again and retries. So two workers counting
 * failures, or a worker and an admin editing, never lose each other's write, and only the
 * write that pauses an endpoint sees the pause happen.
 *
 * Whether any endpoint is on is kept in the preference FLAG, so a site with none skips the
 * t_key_value read on every listing or user write. Every write that can change it sets it again.
 */
final class WebhookEndpointStore
{
    public const GROUP = 'api_webhook';

    /** Preference (section api): '1' while some endpoint is on, '0' when none is, unset when not known yet. */
    public const FLAG = 'api_webhooks_on';

    /** The preference section the flag is kept in. */
    public const SECTION = ApiSettings::SECTION;

    /** Endpoints a site may have. */
    public const MAX = 50;

    /** Tries of one change before giving up under contention. */
    private const TRIES = 50;

    private KeyValue $kv;

    public function __construct(?KeyValue $kv = null)
    {
        $this->kv = $kv ?? new KeyValue();
    }

    /**
     * Every endpoint, by id.
     *
     * @return array<string,Endpoint>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->kv->group(self::GROUP, self::MAX * 2) as $id => $row) {
            $endpoint = self::decode($id, $row);
            if ($endpoint !== null) {
                $out[$id] = $endpoint;
            }
        }

        return $out;
    }

    /**
     * The endpoints that are on; no t_key_value read while the flag says there are none.
     *
     * @return Endpoint[]
     */
    public function enabled(): array
    {
        $flag = (string) osc_get_preference(self::FLAG, self::SECTION);
        if ($flag === '0') {
            return [];
        }
        $enabled = array_values(array_filter($this->all(), static fn (Endpoint $e): bool => $e->enabled()));
        if ($flag === '') {
            $this->refreshFlag($enabled !== []);
        }

        return $enabled;
    }

    public function find(string $id): ?Endpoint
    {
        if (!self::validId($id)) {
            return null;
        }
        $row = $this->kv->get(self::GROUP, $id);

        return $row === null ? null : self::decode($id, $row);
    }

    public function count(): int
    {
        return count($this->kv->group(self::GROUP, self::MAX * 2));
    }

    /**
     * Store a new endpoint at version 1.
     */
    public function insert(Endpoint $endpoint): Endpoint
    {
        $this->kv->set(self::GROUP, $endpoint->id(), self::encode($endpoint), null, 'v1');
        $this->refreshFlag();

        return $this->find($endpoint->id()) ?? $endpoint;
    }

    /**
     * Apply $change to the stored endpoint and write the result, retrying when another
     * writer got in first. $change may run more than once, so it must only compute.
     *
     * @param callable(Endpoint): ?Endpoint $change null leaves the endpoint as it is
     *
     * @return array{0: Endpoint, 1: Endpoint}|null [before, after], or null when there is no
     *                                              such endpoint or $change returned null
     * @throws \RuntimeException when the row kept changing under every try
     */
    public function change(string $id, callable $change): ?array
    {
        for ($try = 0; $try < self::TRIES; $try++) {
            $before = $this->find($id);
            if ($before === null) {
                return null;
            }
            $after = $change($before);
            if ($after === null) {
                return null;
            }
            $written = $this->kv->update(
                self::GROUP,
                $id,
                self::encode($after),
                'v' . ($before->version() + 1),
                'v' . $before->version()
            );
            if ($written === 1) {
                if ($before->enabled() !== $after->enabled()) {
                    $this->refreshFlag();
                }

                return [$before, Endpoint::fromStored($id, $after->toStored(), $before->version() + 1)];
            }
            // Random backoff that grows, so a writer that keeps losing gets a turn.
            usleep(random_int(500, 1000 * min(64, 2 ** min($try, 6))));
        }

        throw new \RuntimeException('The webhook endpoint kept changing; try again.');
    }

    public function delete(string $id): bool
    {
        $deleted = self::validId($id) && $this->kv->delete(self::GROUP, $id) > 0;
        if ($deleted) {
            $this->refreshFlag();
        }

        return $deleted;
    }

    /**
     * Set FLAG from the stored endpoints. A '0' is checked again after it is written, so a
     * writer that turned one on meanwhile is never hidden by it.
     *
     * @param bool|null $any what the caller already read; null reads it
     */
    private function refreshFlag(?bool $any = null): void
    {
        $on = fn (): bool => array_filter($this->all(), static fn (Endpoint $e): bool => $e->enabled()) !== [];
        if ($any ?? $on()) {
            osc_set_preference(self::FLAG, '1', self::SECTION, 'BOOLEAN');

            return;
        }
        osc_set_preference(self::FLAG, '0', self::SECTION, 'BOOLEAN');
        if ($on()) {
            osc_set_preference(self::FLAG, '1', self::SECTION, 'BOOLEAN');
        }
    }

    /**
     * A new endpoint id, `ep_` and 16 hex digits.
     */
    public static function newId(): string
    {
        return 'ep_' . bin2hex(random_bytes(8));
    }

    public static function validId(string $id): bool
    {
        return preg_match('/^ep_[0-9a-f]{16}$/D', $id) === 1;
    }

    /**
     * Hashes of an endpoint's stored row; empty for an id that cannot name one.
     *
     * @return string[]
     * @throws \mindstellar\database\DbException
     */
    public static function rowHashes(string $id, bool $lock): array
    {
        return self::validId($id) ? RowHashQuery::keyedHashes('t_key_value', [['s_group' => self::GROUP, 's_key' => $id]], $lock) : [];
    }

    private static function encode(Endpoint $endpoint): string
    {
        return json_encode($endpoint->toStored(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array{value:?string, state:?string} $row
     */
    private static function decode(string $id, array $row): ?Endpoint
    {
        $data = json_decode((string) $row['value'], true);
        if (!is_array($data)) {
            return null;
        }
        $version = preg_match('/^v(\d{1,15})$/D', (string) $row['state'], $m) === 1 ? (int) $m[1] : 0;

        return Endpoint::fromStored($id, $data, $version);
    }
}
