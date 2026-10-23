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

namespace mindstellar\live;

use mindstellar\model\KeyValue;
use mindstellar\utility\Clock;

/**
 * The outcome of the last publish, for System info. Written only when it flips between ok and
 * failed, so a healthy site adds no writes and a broken one logs once.
 */
class LiveStatus
{
    public const GROUP = 'live';
    public const KEY   = 'publish';

    public function __construct(private KeyValue $kv, private Clock $clock)
    {
    }

    public function ok(): void
    {
        $this->flip(true, '');
    }

    public function failed(string $error): void
    {
        $error = mb_substr($error, 0, 200);
        if ($this->flip(false, $error)) {
            error_log('Live messages: publish failed: ' . $error);
        }
    }

    /**
     * @return array{ok:bool, at:int, error:string}|null null when nothing was published yet
     */
    public function last(): ?array
    {
        try {
            $row  = $this->kv->get(self::GROUP, self::KEY);
            $data = $row === null ? null : json_decode((string) $row['value'], true);
        } catch (\Throwable) {
            return null;
        }
        if (!is_array($data) || !isset($data['ok'], $data['at'])) {
            return null;
        }

        return ['ok' => (bool) $data['ok'], 'at' => (int) $data['at'], 'error' => (string) ($data['error'] ?? '')];
    }

    /**
     * Store the outcome when it differs from the last one.
     *
     * @return bool whether it was written
     */
    private function flip(bool $ok, string $error): bool
    {
        try {
            $last = $this->last();
            if ($last !== null && $last['ok'] === $ok) {
                return false;
            }
            $this->kv->set(self::GROUP, self::KEY, json_encode(['ok' => $ok, 'at' => $this->clock->now(), 'error' => $error]));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
