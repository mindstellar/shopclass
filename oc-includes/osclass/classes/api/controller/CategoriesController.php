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

namespace mindstellar\api\controller;

use mindstellar\api\ApiCall;
use mindstellar\api\ApiServices;
use mindstellar\api\ProblemException;
use mindstellar\api\read\CategoryCatalog;
use mindstellar\api\Response;
use mindstellar\api\serializer\CategorySerializer;
use mindstellar\api\serializer\CustomFieldSerializer;

/**
 * `GET /categories`, `GET /categories/{category}` (id or slug) and `GET /custom-fields`.
 * Categories come from core's cached tree; only a category's custom fields cost a query.
 */
final class CategoriesController
{
    private CategorySerializer $serializer;

    public function __construct(private ApiServices $api)
    {
        $this->serializer = new CategorySerializer($api->extensions(), new CustomFieldSerializer());
    }

    public function index(ApiCall $call): Response
    {
        $request = $call->request();

        $context = $this->api->context($request, $call->credential(), 'category', CategorySerializer::MEMBERS);
        $catalog = CategoryCatalog::fromSite();

        return Response::collection($request->queryBool('tree')
            ? $this->serializer->tree($catalog, $context)
            : $this->serializer->flat($catalog, $context));
    }

    public function show(ApiCall $call): Response
    {
        $context  = $this->api->context($call->request(), $call->credential(), 'category', CategorySerializer::MEMBERS);
        $category = CategoryCatalog::fromSite()->lookup((string) ($call->arg('category') ?? ''), $context->locale());
        if ($category === null) {
            throw ProblemException::of('not_found', 'No such category.');
        }
        $fields = $this->api->fieldService()->forCategory((int) $category['pk_i_id']);

        return Response::ok($this->serializer->one($category, $context, $fields));
    }

    public function fields(ApiCall $call): Response
    {
        $request = $call->request();

        $locale = $this->api->locale($request);
        $asked  = $request->queryString('category');
        if ($asked === '') {
            $fields = $this->api->fieldService()->all();
        } else {
            $fields = $this->api->fieldService()->forCategory(CategoryCatalog::fromSite()->resolve([$asked], $locale)[0]);
        }
        $serializer = new CustomFieldSerializer();

        return Response::collection(array_map(static fn (array $f): array => $serializer->definition($f, $locale), $fields));
    }
}
