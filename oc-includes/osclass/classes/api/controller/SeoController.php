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
use mindstellar\api\serializer\PageSerializer;
use mindstellar\seo\SeoResolver;

/**
 * `GET /seo?for=home|listing|category|page|search&id=...`: the head data of a public page.
 * A listing that is not live or expired, or a page or category that does not exist, is a 404.
 */
final class SeoController
{
    public function __construct(private ApiServices $api)
    {
    }

    public function show(ApiCall $call): Response
    {
        $request  = $call->request();
        $locale   = $this->api->locale($request);
        $resolver = SeoResolver::fromSite();
        $id       = $request->queryString('id');
        $for      = $request->queryString('for');

        $data = match ($for) {
            'home'     => $resolver->home($locale),
            'listing'  => $this->listing($resolver, $this->requireId($id), $locale),
            'category' => $this->category($resolver, $this->requireId($id), $locale),
            'page'     => $this->page($resolver, $this->requireId($id), $locale),
            'search'   => $resolver->search($locale, $request->queryString('q')),
            default    => throw ProblemException::field('/for', 'enum', 'must be one of: home, listing, category, page, search', 'query'),
        };

        return Response::ok($data);
    }

    /**
     * @return array<string,mixed>
     */
    private function listing(SeoResolver $resolver, string $id, string $locale): array
    {
        $reader = $this->api->listingReader();
        $item   = ProblemException::found(ctype_digit($id) ? $reader->row((int) $id) : null, 'listing');
        $photos = $reader->photos((int) $item['pk_i_id']);
        $image  = $photos[0]['normal'] ?? null;

        $path   = $this->categoryPath($reader->categories(), (int) ($item['fk_i_category_id'] ?? 0));

        return ProblemException::found($resolver->listing($item, $path, $locale, is_string($image) ? $image : null), 'listing');
    }

    /**
     * @return array<string,mixed>
     */
    private function category(SeoResolver $resolver, string $id, string $locale): array
    {
        $catalog = CategoryCatalog::fromSite();
        $row     = ProblemException::found($catalog->lookup($id, $locale), 'category');

        return $resolver->category($this->categoryPath($catalog, (int) $row['pk_i_id']), $locale);
    }

    /**
     * The category and its ancestors, root first; empty for a category that is off.
     *
     * @return array<int,array<string,mixed>>
     */
    private function categoryPath(CategoryCatalog $catalog, int $id): array
    {
        $row = $catalog->find($id);

        return $row === null ? [] : [...$catalog->ancestors($id), $row];
    }

    /**
     * @return array<string,mixed>
     */
    private function page(SeoResolver $resolver, string $id, string $locale): array
    {
        $query = $this->api->pageQuery();
        $row   = ProblemException::found($query->find($id), 'page');
        $texts = $query->texts([(int) $row['pk_i_id']])[(int) $row['pk_i_id']] ?? [];
        $one   = ProblemException::found(
            (new PageSerializer($this->api->facts()->defaultLocale(), \Closure::fromCallable([PageSerializer::class, 'siteUrl'])))->one($row, $texts, $locale),
            'page'
        );

        return $resolver->page($one, $texts);
    }

    private function requireId(string $id): string
    {
        if ($id === '') {
            throw ProblemException::field('/id', 'required', 'is required for this kind of page', 'query');
        }

        return $id;
    }
}
