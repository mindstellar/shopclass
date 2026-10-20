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
 * A request a core service will not carry out, with the reason to show. Every web and API
 * caller handles this one family: the message is plain, translated text, and whoever shows
 * it escapes it.
 */
class RefusedException extends \InvalidArgumentException
{
    /** @var string[] */
    private array $notices = array();

    /**
     * What went wrong beside the refusal, for the form to show with it.
     *
     * @param string[] $notices
     */
    public function withNotices(array $notices): static
    {
        $this->notices = $notices;

        return $this;
    }

    /**
     * @return string[]
     */
    public function notices(): array
    {
        return $this->notices;
    }
}
