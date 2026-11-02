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

namespace mindstellar\api\controller\admin;

use mindstellar\admin\ExposedSettings;
use mindstellar\api\ApiCall;
use mindstellar\api\Response;
use mindstellar\api\serializer\Format;

/**
 * `/admin/settings`, the settings ExposedSettings lets the API read and change, and
 * `/admin/jobs`, the background queue's state, read only.
 */
final class AdminSettingsController
{
    public const DEAD_LETTERS = 50;

    private ExposedSettings $settings;

    public function __construct()
    {
        $this->settings = new ExposedSettings();
    }

    public function show(): Response
    {
        return Response::ok($this->settings->read());
    }

    public function update(ApiCall $call): Response
    {
        $input = $call->input();
        if ($input !== []) {
            $this->settings->save($input);
        }

        return $this->show();
    }

    public function jobs(ApiCall $call): Response
    {
        return Response::ok([
            'counts'       => array_map('intval', osc_job_summary()),
            'dead_letters' => array_map([self::class, 'job'], osc_job_dead_letters($call->request()->queryInt('limit', self::DEAD_LETTERS))),
        ]);
    }

    /**
     * A job as `/admin/jobs` shows it. Never its payload, which can carry anything a plugin queued.
     *
     * @param array<string,mixed> $row a t_job_queue row
     *
     * @return array<string,mixed>
     */
    private static function job(array $row): array
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
