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

/**
 * The codes of the `warnings` a write answer may carry next to `data`. A warning is not an
 * error: the write worked, but not quite as asked.
 *
 * @api
 */
final class Warning
{
    /** @api */
    public const LISTING_PENDING = 'listing_pending';

    /** @api */
    public const PHOTO_SKIPPED = 'photo_skipped';

    /** @api */
    public const COMMENT_PENDING = 'comment_pending';

    /** @api */
    public const EMAIL_CONFIRMATION_SENT = 'email_confirmation_sent';

    /**
     * Every code, in the order the docs list them.
     *
     * @api
     */
    public const CODES = [self::LISTING_PENDING, self::PHOTO_SKIPPED, self::COMMENT_PENDING, self::EMAIL_CONFIRMATION_SENT];

    private function __construct()
    {
    }

    /**
     * The answer's `warnings` member, for Response::ok() or created(); none for no warnings.
     *
     * @api
     *
     * @param array<string,string> $warnings code => message
     *
     * @return array<string,mixed>
     */
    public static function member(array $warnings): array
    {
        $list = [];
        foreach ($warnings as $code => $message) {
            $list[] = ['code' => $code, 'message' => $message];
        }

        return $list === [] ? [] : ['warnings' => $list];
    }
}
