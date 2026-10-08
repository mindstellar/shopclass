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

use mindstellar\api\ApiCall;
use mindstellar\api\ApiServices;
use mindstellar\api\Response;
use mindstellar\api\serializer\CustomFieldSerializer;
use mindstellar\fields\FieldService;

/**
 * `/admin/custom-fields`: the custom field screen's writes, through FieldService.
 */
final class AdminFieldsController
{
    private FieldService $fields;

    public function __construct(private ApiServices $api)
    {
        $this->fields = $api->fieldService();
    }

    public function create(ApiCall $call): Response
    {
        $id = $this->fields->create($call->input());

        return Response::created($this->field($id), $this->api->links()->api('admin/custom-fields/' . $id, $call->request()->version()));
    }

    public function show(ApiCall $call): Response
    {
        $this->fields->find($call->intArg());

        return Response::ok($this->field($call->intArg()));
    }

    /**
     * Members not sent keep their values; `categories` replaces
     * the list.
     */
    public function update(ApiCall $call): Response
    {
        $this->fields->update($call->intArg(), $call->input());

        return Response::ok($this->field($call->intArg()));
    }

    public function delete(ApiCall $call): Response
    {
        $this->fields->delete($call->intArg());

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
