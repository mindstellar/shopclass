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

namespace mindstellar\exception;

/**
 * The thing is there but its state does not allow the change, such as a key already revoked.
 */
final class ConflictException extends RefusedException
{
    /** The user already rated this listing. */
    public const ALREADY_REVIEWED = 'already_reviewed';

    public function __construct(string $message = '', private string $reason = '')
    {
        parent::__construct($message);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
