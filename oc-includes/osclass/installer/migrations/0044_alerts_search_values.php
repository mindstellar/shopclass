<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\database\Connection;
use mindstellar\migration\MigrationInterface;
use mindstellar\search\AlertJobs;
use mindstellar\search\AlertStore;

/**
 * Saved alerts (t_alerts.s_search) held SQL fragments that were run as written. This
 * rewrites each one as its search values (a v2 envelope), so no stored SQL runs again.
 *
 * - A row that is not exactly what core writes -- a plugin's condition, an extra table or
 *   join, a value that does not unescape cleanly -- is held: s_search becomes
 *   {"v":2,"held":"<reason>"} and b_active 0. The old text is not kept.
 * - Every row is read once, in primary-key batches of 500. A valid v2 envelope or a held
 *   marker is left alone; anything else, a malformed v2 row included, is converted or held.
 *   Each is written only if unchanged since it was read, so re-running is safe.
 * - It works for about 10 seconds, then queues a job to do the rest. Replay skips rows
 *   that are not converted yet.
 *
 * No schema change. A fresh install has no alerts, so there is nothing to do.
 */
return new class () implements MigrationInterface {
    /** Seconds to convert inline before handing the rest to the job queue. */
    public float $budget = 10.0;

    /**
     * @param Connection $conn
     *
     * @throws \mindstellar\database\DbException
     * @throws \RuntimeException when the rest cannot be queued
     */
    public function up(Connection $conn): void
    {
        $deadline = microtime(true) + $this->budget;
        $after    = 0;
        $missed   = 0;
        do {
            $result = AlertStore::convertBatch($conn, $after, AlertStore::BATCH, $deadline);
            $after  = $result['last'];
            $missed += $result['missed'];
        } while (!$result['done'] && microtime(true) < $deadline);

        if ($result['done'] && $missed === 0) {
            return;
        }
        // Out of time, the job carries on from here; a row changed while it was being
        // read is picked up by a second pass from the start.
        if (!AlertJobs::ensureQueued($result['done'] ? 0 : $after)) {
            throw new \RuntimeException('Could not queue the rest of the saved-alert conversion');
        }
    }
};
