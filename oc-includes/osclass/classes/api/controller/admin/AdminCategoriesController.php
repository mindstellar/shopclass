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

use mindstellar\admin\AdminText;
use mindstellar\api\ApiServices;

use mindstellar\api\auth\Credential;
use mindstellar\api\ProblemException;
use mindstellar\api\Request;
use mindstellar\api\Response;
use mindstellar\api\serializer\CategorySerializer;
use mindstellar\category\CategoryService;
use mindstellar\utility\DeferredMail;

/**
 * `/admin/categories`: every category with each language's texts, and the categories screen's
 * writes through CategoryService.
 */
final class AdminCategoriesController
{
    private CategoryService $categories;

    public function __construct(private ApiServices $api)
    {
        $this->categories = $api->categoryService();
    }

    /**
     * GET /admin/categories: every category, enabled or not.
     *
     * @param array<string,string> $args
     */
    public function index(Request $request, Credential $credential, array $args): Response
    {
        $rows  = $this->categoryRows(null);
        $texts = $this->texts(array_column($rows, 'pk_i_id'));

        return Response::collection(array_map(static fn (array $row): array => CategorySerializer::admin($row, $texts[(int) $row['pk_i_id']] ?? []), $rows));
    }

    /**
     * GET /admin/categories/{id}
     *
     * @param array<string,string> $args
     */
    public function show(Request $request, Credential $credential, array $args): Response
    {
        return Response::ok($this->categoryData((int) $args['id']));
    }

    /**
     * POST /admin/categories: added last among its siblings; slugs are made from the names.
     *
     * @param array<string,string> $args
     */
    public function create(Request $request, Credential $credential, array $args): Response
    {
        $input        = $request->input();
        $descriptions = [];
        foreach ($this->localized((array) $input['translations']) as $locale => $text) {
            $descriptions[$locale] = [
                's_name'        => AdminText::name($text['name'] ?? '', '/translations/' . $locale . '/name'),
                's_description' => AdminText::clean($text['description'] ?? ''),
            ];
        }
        $id = $this->categories->create(
            isset($input['parent_id']) ? (int) $input['parent_id'] : null,
            [
                'i_expiration_days' => (int) ($input['expiration_days'] ?? 0),
                'b_price_enabled'   => ($input['price_enabled'] ?? true) ? 1 : 0,
            ],
            $descriptions,
            array_key_exists('enabled', $input) ? (bool) $input['enabled'] : null
        );

        return Response::created($this->categoryData($id), $this->api->links()->api('admin/categories/' . $id));
    }

    /**
     * PATCH /admin/categories/{id}. Texts not sent keep their values, slugs included; a
     * changed slug keeps the old one redirecting.
     *
     * @param array<string,string> $args
     */
    public function update(Request $request, Credential $credential, array $args): Response
    {
        $id    = (int) $args['id'];
        $row   = $this->categoryRows($id)[0] ?? null;
        $input = $request->input();
        if ($row === null) {
            throw ProblemException::of('not_found', 'No such category.');
        }
        $editor = $this->categories;
        DeferredMail::transaction(function () use ($editor, $id, $row, $input): void {
            if (array_intersect_key($input, ['translations' => 1, 'expiration_days' => 1, 'price_enabled' => 1, 'apply_to_subcategories' => 1]) !== []) {
                // Expiry is rewritten into every listing of the category, so only when it was sent.
                $fields = ['b_price_enabled' => array_key_exists('price_enabled', $input) ? ($input['price_enabled'] ? 1 : 0) : (int) $row['b_price_enabled']];
                if (array_key_exists('expiration_days', $input)) {
                    $fields['i_expiration_days'] = (int) $input['expiration_days'];
                }
                if (!$editor->update($id, $fields, $this->descriptions($id, (array) ($input['translations'] ?? [])), (bool) ($input['apply_to_subcategories'] ?? false))) {
                    throw ProblemException::of('server_error', 'The category could not be saved.');
                }
            }
            if (array_key_exists('enabled', $input) && (bool) $input['enabled'] !== ((int) $row['b_enabled'] === 1)
                && $editor->setEnabled($id, (bool) $input['enabled'], $row) === null
            ) {
                throw ProblemException::of('conflict', 'The parent category is disabled. Enable it first.');
            }
        });

        return Response::ok($this->categoryData($id));
    }

    /**
     * DELETE /admin/categories/{id}: 204 when it is gone, 202 when it is too large and is
     * hidden now and emptied in the background.
     *
     * @param array<string,string> $args
     */
    public function delete(Request $request, Credential $credential, array $args): Response
    {
        return $this->categories->delete((int) $args['id']) === 'queued' ? new Response(202) : Response::noContent();
    }

    /**
     * Category rows with their listing counts: one, or all in display order.
     *
     * @return array<int,array<string,mixed>>
     */
    private function categoryRows(?int $id): array
    {
        $p   = DB_TABLE_PREFIX;
        $sql = 'SELECT c.*, s.i_num_items FROM ' . $p . 't_category c LEFT JOIN ' . $p . 't_category_stats s ON s.fk_i_category_id = c.pk_i_id';

        return osc_db_stringify_rows($id === null
            ? osc_db_select($sql . ' ORDER BY c.i_position ASC, c.pk_i_id ASC')
            : osc_db_select($sql . ' WHERE c.pk_i_id = ?', [$id]));
    }

    /**
     * Every language's texts of some categories, in one query.
     *
     * @param array<int,int|string> $ids
     *
     * @return array<int,array<int,array<string,mixed>>> category id => rows
     */
    private function texts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = osc_db_stringify_rows(osc_db_table(DB_TABLE_PREFIX . 't_category_description')
            ->whereIn('fk_i_category_id', array_map('intval', $ids))
            ->orderBy('fk_c_locale_code')
            ->get());
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['fk_i_category_id']][] = $row;
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     * @throws ProblemException 404
     */
    private function categoryData(int $id): array
    {
        $row = $this->categoryRows($id)[0] ?? null;
        if ($row === null) {
            throw ProblemException::of('not_found', 'No such category.');
        }

        return CategorySerializer::admin($row, $this->texts([$id])[$id] ?? []);
    }

    /**
     * The texts to save: each sent language's stored texts with the sent members over them,
     * so a slug that was not sent is kept rather than made again from the name.
     *
     * @param array<string,mixed> $sent locale => {name, slug, description}
     *
     * @return array<string,array<string,mixed>>
     * @throws ProblemException 422 for a language the site does not have, or a new one without a name
     */
    private function descriptions(int $id, array $sent): array
    {
        $stored = [];
        foreach ($this->texts([$id])[$id] ?? [] as $row) {
            $stored[(string) $row['fk_c_locale_code']] = ['s_name' => $row['s_name'], 's_description' => $row['s_description'], 's_slug' => $row['s_slug']];
        }
        $out = [];
        foreach ($this->localized($sent) as $locale => $text) {
            $row = $stored[$locale] ?? [];
            foreach (['name' => 's_name', 'slug' => 's_slug', 'description' => 's_description'] as $member => $column) {
                if (array_key_exists($member, $text)) {
                    $row[$column] = $member === 'slug' ? trim((string) $text[$member]) : AdminText::clean($text[$member]);
                }
            }
            if (trim((string) ($row['s_name'] ?? '')) === '') {
                throw ProblemException::field('/translations/' . $locale . '/name', 'required', 'is needed for a new language');
            }
            $out[$locale] = $row;
        }

        return $out;
    }

    /**
     * Texts keyed by the site's languages only.
     *
     * @param array<string,mixed> $texts
     *
     * @return array<string,array<string,mixed>>
     * @throws ProblemException 422 for a language the site does not have, or none at all
     */
    private function localized(array $texts): array
    {
        $locales = $this->api->facts()->locales();
        $out     = [];
        foreach ($texts as $locale => $text) {
            if (!isset($locales[(string) $locale])) {
                throw ProblemException::field('/translations/' . $locale, 'invalid', 'is not a language of this site');
            }
            $out[(string) $locale] = (array) $text;
        }

        return $out;
    }
}
