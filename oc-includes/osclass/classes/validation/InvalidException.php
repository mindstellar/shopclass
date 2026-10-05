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

namespace mindstellar\validation;

/**
 * Fields of the request are not acceptable: for each, which one (a JSON pointer such as
 * `/name`), a short code and the reason to show.
 */
final class InvalidException extends RefusedException
{
    /** @var array<int,array{pointer:string,code:string,message:string}> */
    private array $errors;

    /**
     * @param string $pointer e.g. `/name`; '' for the request as a whole
     * @param string $reason  a short code, e.g. `minLength`, `taken`, `unknown`
     */
    public function __construct(string $pointer, string $reason, string $message)
    {
        parent::__construct($message);
        $this->errors = [['pointer' => $pointer, 'code' => $reason, 'message' => $message]];
    }

    /**
     * Several fields at once; the first one is the message unless $message says otherwise.
     *
     * @param array<int,array{pointer:string,code:string,message:string}> $errors at least one
     */
    public static function all(array $errors, ?string $message = null): self
    {
        $errors  = array_values($errors);
        $first   = $errors[0] ?? ['pointer' => '', 'code' => 'rejected', 'message' => _m('The request was refused.')];
        $invalid = new self($first['pointer'], $first['code'], $first['message']);
        $invalid->errors = $errors === [] ? [$first] : $errors;
        if ($message !== null) {
            $invalid->message = $message;
        }

        return $invalid;
    }

    public function pointer(): string
    {
        return $this->errors[0]['pointer'];
    }

    public function reason(): string
    {
        return $this->errors[0]['code'];
    }

    /**
     * @return array<int,array{pointer:string,code:string,message:string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
