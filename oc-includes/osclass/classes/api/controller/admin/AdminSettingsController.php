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
use mindstellar\api\ApiServices;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\JobSerializer;
use mindstellar\apiaccess\Credential;

/**
 * `/admin/settings`, the settings ExposedSettings lets the API read and change, and
 * `/admin/jobs`, the background queue's state, read only.
 */
final class AdminSettingsController
{
    public const DEAD_LETTERS = 50;

    private ExposedSettings $settings;

    public function __construct(private ApiServices $api)
    {
        $this->settings = $api->exposedSettings();
    }

    /**
     * @param array<string,string> $args
     */
    public function show(Request $request, Credential $credential, array $args): Response
    {
        return Response::ok($this->settings->read());
    }

    /**
     * PATCH /admin/settings: all or none.
     *
     * @param array<string,string> $args
     */
    public function update(Request $request, Credential $credential, array $args): Response
    {
        $input = $request->input();
        if ($input !== []) {
            $this->settings->save($input);
        }

        return $this->show($request, $credential, $args);
    }

    /**
     * GET /admin/jobs
     *
     * @param array<string,string> $args
     */
    public function jobs(Request $request, Credential $credential, array $args): Response
    {
        $serializer = new JobSerializer();

        return Response::ok([
            'counts'       => array_map('intval', osc_job_summary()),
            'dead_letters' => array_map([$serializer, 'one'], osc_job_dead_letters($request->queryInt('limit', self::DEAD_LETTERS))),
        ]);
    }
}
