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

namespace mindstellar\api;

use mindstellar\apiaccess\Credential;

/**
 * One call to an endpoint, as its handler gets it: the request, who is calling, and the
 * values the path matched.
 */
final class ApiCall
{
    /**
     * @param array<string,string> $args the path's {name} values
     */
    public function __construct(private Request $request, private Credential $credential, private array $args = [])
    {
    }

    public function request(): Request
    {
        return $this->request;
    }

    public function credential(): Credential
    {
        return $this->credential;
    }

    /**
     * @return array<string,string>
     */
    public function args(): array
    {
        return $this->args;
    }

    /**
     * A {name} value from the path, or null when the route has none by that name.
     */
    public function arg(string $name): ?string
    {
        return $this->args[$name] ?? null;
    }

    /**
     * A {name} value as a row id, or 0 when it is missing or not digits, which finds no row.
     */
    public function intArg(string $name = 'id'): int
    {
        $value = $this->args[$name] ?? '';

        return ctype_digit($value) ? (int) $value : 0;
    }

    /**
     * The decoded JSON body, or [] when there is none.
     *
     * @return array<mixed>
     * @throws ProblemException 413, 415 or 400 when a body was sent that cannot be used
     */
    public function input(): array
    {
        return $this->request->input();
    }
}
