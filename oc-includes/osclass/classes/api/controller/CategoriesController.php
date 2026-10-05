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

use mindstellar\api\ApiServices;
use mindstellar\api\auth\Credential;
use mindstellar\api\Problem;
use mindstellar\api\ProblemException;
use mindstellar\api\read\CategoryCatalog;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\CategorySerializer;
use mindstellar\api\serializer\CustomFieldSerializer;

/**
 * `GET /categories`, `GET /categories/{category}` (id or slug) and `GET /fields`.
 * Categories come from core's cached tree; only a category's custom fields cost a query.
 */
final class CategoriesController
{
    private CategorySerializer $serializer;

    public function __construct(private ApiServices $api)
    {
        $this->serializer = new CategorySerializer($api->extensions(), new CustomFieldSerializer());
    }

    /**
     * @param array<string,string> $args
     */
    public function index(Request $request, Credential $credential, array $args): Response
    {
        $context = $this->api->context($request, $credential, 'category', CategorySerializer::MEMBERS);
        $catalog = CategoryCatalog::fromSite();

        return Response::collection($request->queryBool('tree')
            ? $this->serializer->tree($catalog, $context)
            : $this->serializer->flat($catalog, $context));
    }

    /**
     * @param array<string,string> $args
     */
    public function show(Request $request, Credential $credential, array $args): Response
    {
        $context  = $this->api->context($request, $credential, 'category', CategorySerializer::MEMBERS);
        $category = CategoryCatalog::fromSite()->lookup((string) ($args['category'] ?? ''), $context->locale());
        if ($category === null) {
            throw ProblemException::of('not_found', 'No such category.');
        }
        $fields = \Field::newInstance()->findByCategory((int) $category['pk_i_id']);

        return Response::ok($this->serializer->one($category, $context, $fields));
    }

    /**
     * @param array<string,string> $args
     */
    public function fields(Request $request, Credential $credential, array $args): Response
    {
        $locale = $this->api->locale($request);
        $asked  = $request->queryString('category');
        if ($asked === '') {
            $fields = \Field::newInstance()->listAll();
        } else {
            $category = CategoryCatalog::fromSite()->lookup($asked, $locale);
            if ($category === null) {
                throw ProblemException::from(Problem::validation([
                    ['pointer' => '/category', 'code' => 'enum', 'message' => 'is not a known category', 'in' => 'query'],
                ]));
            }
            $fields = \Field::newInstance()->findByCategory((int) $category['pk_i_id']);
        }
        $serializer = new CustomFieldSerializer();

        return Response::collection(array_map(static fn (array $f): array => $serializer->definition($f, $locale), $fields));
    }
}
