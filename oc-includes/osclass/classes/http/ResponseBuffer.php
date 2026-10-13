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

namespace mindstellar\http;

/**
 * Collects one response: its headers, lower-cased, and a body that refuses to grow past a limit.
 *
 * @internal used by CurlHttpClient
 */
final class ResponseBuffer
{
    /** The most header bytes taken before the transfer is stopped. */
    public const MAX_HEADER_BYTES = 65536;

    private string $body = '';
    private int $headerBytes = 0;
    private bool $headersOverflowed = false;
    private bool $overflowed = false;

    /** @var array<string,string> */
    private array $headers = [];

    public function __construct(private int $maxBytes)
    {
    }

    /** Add a body chunk. Returns the bytes taken, or 0 once the limit would be passed, which stops curl. */
    public function append(string $chunk): int
    {
        if (strlen($this->body) + strlen($chunk) > $this->maxBytes) {
            $this->overflowed = true;

            return 0;
        }
        $this->body .= $chunk;

        return strlen($chunk);
    }

    /** Take one header line. A status line starts a new set, so a 100 Continue leaves no headers behind. */
    public function header(string $line): int
    {
        $this->headerBytes += strlen($line);
        if ($this->headerBytes > self::MAX_HEADER_BYTES) {
            $this->headersOverflowed = true;

            return 0;
        }
        if (str_starts_with($line, 'HTTP/')) {
            $this->headers = [];
        } elseif (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $name  = strtolower(trim($name));
            $value = trim($value);
            if ($name !== '') {
                $this->headers[$name] = isset($this->headers[$name]) ? $this->headers[$name] . ', ' . $value : $value;
            }
        }

        return strlen($line);
    }

    public function headersOverflowed(): bool
    {
        return $this->headersOverflowed;
    }

    public function overflowed(): bool
    {
        return $this->overflowed;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }
}
