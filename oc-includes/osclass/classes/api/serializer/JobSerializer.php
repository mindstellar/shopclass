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

namespace mindstellar\api\serializer;

/**
 * A background job as `/admin/jobs` shows it. Never its payload, which can carry anything a
 * plugin queued.
 */
final class JobSerializer
{
    /**
     * @param array<string,mixed> $row a t_job_queue row
     *
     * @return array<string,mixed>
     */
    public function one(array $row): array
    {
        return [
            'id'          => Format::int($row['pk_i_id'] ?? 0),
            'type'        => (string) ($row['s_type'] ?? ''),
            'status'      => (string) ($row['s_status'] ?? ''),
            'attempts'    => Format::int($row['i_attempts'] ?? 0),
            'last_error'  => Format::text($row['s_last_error'] ?? null),
            'created_at'  => Format::time($row['dt_created'] ?? null),
            'next_run_at' => Format::time($row['dt_next_run'] ?? null),
        ];
    }
}
