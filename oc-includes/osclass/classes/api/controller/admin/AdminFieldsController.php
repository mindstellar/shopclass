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

use mindstellar\api\ApiServices;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\CustomFieldSerializer;
use mindstellar\apiaccess\Credential;
use mindstellar\fields\FieldService;

/**
 * `/admin/fields`: the custom field screen's writes, through FieldService.
 */
final class AdminFieldsController
{
    private FieldService $fields;

    public function __construct(private ApiServices $api)
    {
        $this->fields = $api->fieldService();
    }

    /**
     * POST /admin/fields
     *
     * @param array<string,string> $args
     */
    public function create(Request $request, Credential $credential, array $args): Response
    {
        $id = $this->fields->create($request->input());

        return Response::created($this->field($id), $this->api->links()->api('admin/fields/' . $id));
    }

    /**
     * GET /admin/fields/{id}
     *
     * @param array<string,string> $args
     */
    public function show(Request $request, Credential $credential, array $args): Response
    {
        $this->fields->find((int) $args['id']);

        return Response::ok($this->field((int) $args['id']));
    }

    /**
     * PATCH /admin/fields/{id}. Members not sent keep their values; `categories` replaces
     * the list.
     *
     * @param array<string,string> $args
     */
    public function update(Request $request, Credential $credential, array $args): Response
    {
        $this->fields->update((int) $args['id'], $request->input());

        return Response::ok($this->field((int) $args['id']));
    }

    /**
     * DELETE /admin/fields/{id}
     *
     * @param array<string,string> $args
     */
    public function delete(Request $request, Credential $credential, array $args): Response
    {
        $this->fields->delete((int) $args['id']);

        return Response::noContent();
    }

    /**
     * @return array<string,mixed>
     */
    private function field(int $id): array
    {
        return (new CustomFieldSerializer())->definition($this->fields->extended($id), $this->api->facts()->defaultLocale())
            + ['categories' => array_map('intval', $this->fields->categoryIds($id))];
    }
}
