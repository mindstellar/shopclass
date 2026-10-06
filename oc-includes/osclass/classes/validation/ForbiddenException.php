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
 * The site does not allow this, with the reason as one of the constants ('' for none given).
 */
final class ForbiddenException extends RefusedException
{
    /** The account, e-mail or address is banned. */
    public const BANNED = 'banned';
    /** The site has the feature switched off. */
    public const DISABLED = 'feature_disabled';
    /** Only a signed-in user may do this. */
    public const SIGN_IN = 'wrong_credential';
    /** Only the owner or author may do this. */
    public const NOT_OWNER = 'not_owner';

    public function __construct(string $message = '', private string $reason = '')
    {
        parent::__construct($message);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
