<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin\form\store;

use RuntimeException;

/**
 * A submission a store refuses to write.
 *
 * Both cases are about the row rather than about the values, which is why they are not
 * field errors: a key that does not describe a row cannot be corrected by editing the
 * form, and a row that is gone cannot be written to at all. The save path turns either
 * into one error string of its own wording -- the message here is for a log, not a page.
 *
 * @package mindstellar\admin\form\store
 */
final class StoreException extends RuntimeException
{
    /** The caller named something that is not a row key. */
    public const BAD_KEY = 1;

    /** The key is well formed, but no row is under it. */
    public const NO_ROW = 2;

    /**
     * The caller named something that is not a row key.
     *
     * @param string $detail for a log, not for a page
     *
     * @return self
     */
    public static function badKey(string $detail): self
    {
        return new self($detail, self::BAD_KEY);
    }

    /**
     * The key is well formed, but no row is under it.
     *
     * @param string $detail for a log, not for a page
     *
     * @return self
     */
    public static function noRow(string $detail): self
    {
        return new self($detail, self::NO_ROW);
    }
}
