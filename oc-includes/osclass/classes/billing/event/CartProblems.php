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

namespace mindstellar\billing\event;

use mindstellar\event\Bridged;

/**
 * Reasons the cart cannot be ordered yet. Old filter: `cart_problems`.
 */
final class CartProblems extends CartEvent implements Bridged
{
    /** Where a problem may point; any other entry is dropped. */
    public const POINTERS = ['/coupon', '/address/postcode', '/address/state', '/address/country', '/shipping_method'];

    /** Longest message of a problem. */
    public const MAX_TEXT = 200;

    /** Most problems kept from one event. */
    public const MAX = 20;

    /** @var mixed list of {code, pointer, message} */
    private $problems = [];

    public static function legacyNames(): array
    {
        return ['cart_problems'];
    }

    /**
     * @throws \InvalidArgumentException when the code, pointer or message breaks the rules of clean()
     */
    public function add(string $code, string $pointer, string $message): void
    {
        $entry = ['code' => $code, 'pointer' => $pointer, 'message' => $message];
        if (self::clean([$entry]) === []) {
            throw new \InvalidArgumentException(sprintf('The cart problem "%s" is not well formed.', $code));
        }
        $this->problems   = is_array($this->problems) ? $this->problems : [];
        $this->problems[] = $entry;
    }

    /**
     * The problems as the old filter sees and returns them.
     *
     * @return mixed
     */
    public function rawProblems()
    {
        return $this->problems;
    }

    public function applyLegacy(string $name, callable $fn): void
    {
        $this->problems = $fn($this->problems, $this->cart);
    }

    /**
     * The entries kept when well formed: a code of 1-32 of a-z and `_`, a pointer from POINTERS
     * and a message of 1-MAX_TEXT characters. At most MAX are kept.
     *
     * @param mixed $problems
     *
     * @return array<int,array{line:null,code:string,pointer:string,message:string}>
     */
    public static function clean($problems): array
    {
        $out = [];
        foreach (is_array($problems) ? $problems : [] as $problem) {
            if (!is_array($problem) || count($out) >= self::MAX) {
                continue;
            }
            $code    = $problem['code'] ?? null;
            $pointer = $problem['pointer'] ?? null;
            $message = is_string($problem['message'] ?? null) ? trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $problem['message']) ?? '') : '';
            if (!is_string($code) || preg_match('/^[a-z_]{1,32}$/', $code) !== 1 || !in_array($pointer, self::POINTERS, true)
                || $message === '' || mb_strlen($message, 'UTF-8') > self::MAX_TEXT) {
                continue;
            }
            $out[] = ['line' => null, 'code' => $code, 'pointer' => $pointer, 'message' => $message];
        }

        return $out;
    }
}
