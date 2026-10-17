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
use mindstellar\api\read\Page;
use mindstellar\api\Response;
use mindstellar\api\serializer\PageSerializer;

/**
 * `GET /pages`, `GET /pages/{page}` (id or internal name) and `GET /menus`.
 */
final class PagesController
{
    private PageSerializer $serializer;

    public function __construct(private ApiServices $api)
    {
        $this->serializer = new PageSerializer($api->facts()->defaultLocale(), PageSerializer::siteUrl(...));
    }

    public function index(ApiCall $call): Response
    {
        return Page::whole($this->linked($this->api->locale($call->request())), $this->api->links(), $call);
    }

    public function show(ApiCall $call): Response
    {
        $locale = $this->api->locale($call->request());
        $query  = $this->api->pageQuery();
        $page   = ProblemException::found($query->find((string) ($call->arg('page') ?? '')), 'page');
        $texts  = $query->texts([(int) $page['pk_i_id']])[(int) $page['pk_i_id']] ?? [];

        return Response::ok(ProblemException::found($this->serializer->one($page, $texts, $locale), 'page'));
    }

    public function menus(ApiCall $call): Response
    {
        $locale = $this->api->locale($call->request());
        $menus  = $this->serializer->menus(
            $this->linked($locale),
            CategoryCatalog::fromSite(),
            $locale,
            static fn (array $row): string => (string) osc_search_url(['sCategory' => CategoryCatalog::text($row, 's_slug', $locale)])
        );

        return Page::whole($menus, $this->api->links(), $call);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function linked(string $locale): array
    {
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
    }
}
