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

namespace mindstellar\api\write;

/**
 * An image file in the temp folder that passed the listing form's checks.
 */
final class CheckedPhoto
{
    public function __construct(private string $path, private string $extension)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * The extension of its real type: jpg, png, gif or webp.
     */
    public function extension(): string
    {
        return $this->extension;
    }

    public function discard(): void
    {
        @unlink($this->path);
    }
}
