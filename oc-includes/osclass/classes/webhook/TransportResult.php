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

/**
 * What a POST came to: the HTTP status, or 0 and why no answer came.
 */
final class TransportResult
{
    public function __construct(private int $status, private string $error = '', private int $retryAfter = 0)
    {
    }

    public static function answered(int $status, int $retryAfter = 0): self
    {
        return new self($status, '', max(0, $retryAfter));
    }

    public static function failed(string $error): self
    {
        return new self(0, $error);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * Seconds the receiver asked us to wait (Retry-After), 0 when it did not say.
     */
    public function retryAfter(): int
    {
        return $this->retryAfter;
    }

    public function gone(): bool
    {
        return $this->status === 410;
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Short text for the endpoint's last status: `HTTP 503`, or the transport error.
     */
    public function describe(): string
    {
        return $this->status > 0 ? 'HTTP ' . $this->status : ($this->error !== '' ? $this->error : 'No answer');
    }
}
