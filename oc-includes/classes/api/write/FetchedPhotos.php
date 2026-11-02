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
 * A listing write's photos, fetched by its route's prepare step before the write's
 * transaction. When the write is refused before it takes them, their files are removed as
 * this is let go, so no download is left in the temp folder.
 */
final class FetchedPhotos
{
    private ?PhotoBatch $batch;

    public function __construct(PhotoBatch $batch)
    {
        $this->batch = $batch;
    }

    /**
     * The photos, for the caller to finish with PhotoIntake::finish().
     *
     * @throws \LogicException when they were already taken
     */
    public function take(): PhotoBatch
    {
        $batch       = $this->batch ?? throw new \LogicException('The photos were already taken.');
        $this->batch = null;

        return $batch;
    }

    public function __destruct()
    {
        $this->batch?->discard(false);
    }
}
