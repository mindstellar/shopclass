<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\storage;

use mindstellar\job\JobWorker;

/**
 * Deprecated. The storage queue is now the shared job queue, and its handlers live in
 * StorageJobs. Draining it is JobWorker's job, and it drains every type, not only
 * `storage.*`.
 *
 * Kept because this class name was reachable from outside core. New code calls
 * `osc_job_run()`.
 *
 * @deprecated 6.4.0 use osc_job_run() / mindstellar\job\JobWorker::run()
 */
final class StorageWorker
{
    /**
     * @param int $maxSeconds wall-clock budget for this tick
     *
     * @return void
     */
    public static function run(int $maxSeconds = 20): void
    {
        JobWorker::run($maxSeconds);
    }
}
