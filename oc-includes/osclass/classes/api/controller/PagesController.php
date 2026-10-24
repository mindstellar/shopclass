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
use mindstellar\api\read\Page;
use mindstellar\api\Response;
use mindstellar\api\serializer\PageSerializer;
use mindstellar\cache\CacheGroup;
use mindstellar\menu\MenuService;

/**
 * `GET /pages`, `GET /pages/{page}` (id or internal name) and `GET /menus`.
 */
final class PagesController
{
    private PageSerializer $serializer;

    public function __construct(private ApiServices $api)
    {
        $this->serializer = $api->pageSerializer();
    }

    public function index(ApiCall $call): Response
    {
        return Page::whole($this->linked($this->api->locale($call->request())), $this->api->links(), $call);
    }

    public function show(ApiCall $call): Response
    {
        [$page] = $this->api->page((string) ($call->arg('page') ?? ''), $this->api->locale($call->request()));

        return Response::ok($page);
    }

    public function menus(ApiCall $call): Response
    {
        $locale  = $this->api->locale($call->request());
        $service = MenuService::getInstance();
        $menus   = [];
        foreach ($service->menuIds() as $id) {
            $menus[$id] = $service->resolve($id, $locale);
        }

        return Page::whole($this->serializer->menus($menus, $locale), $this->api->links(), $call);
    }

    /**
     * The linked pages in $locale, cached in the `page` group with the permalink settings.
     *
     * @return array<int,array<string,mixed>>
     */
    private function linked(string $locale): array
    {
        $key = 'linked|' . $locale . '|' . CacheGroup::generation('preference');

        return CacheGroup::remember('page', $key, function () use ($locale): array {
            $query = $this->api->pageQuery();
            $pages = $query->linked();
            $texts = $query->texts(array_map(static fn (array $p): int => (int) $p['pk_i_id'], $pages));
            $out   = [];
            foreach ($pages as $page) {
                $row = $this->serializer->summary($page, $texts[(int) $page['pk_i_id']] ?? [], $locale);
                if ($row !== null) {
                    $out[] = $row;
                }
            }

            return $out;
        }) ?? [];
    }
}
