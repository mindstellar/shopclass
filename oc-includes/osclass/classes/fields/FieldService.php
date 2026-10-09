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

namespace mindstellar\fields;

use Field;
use mindstellar\admin\AdminText;
use mindstellar\database\Db;
use mindstellar\exception\InvalidException;
use mindstellar\exception\NotFoundException;

/**
 * Custom field writes for the field screen and the API: add, edit and delete a field, with
 * its categories. Names and choices are cleaned as the field screen cleans them, and a name
 * must be unique.
 */
final class FieldService
{
    /**
     * @param string $locale the site's language, whose name a rename updates
     */
    public function __construct(private Field $fields, private string $locale)
    {
    }

    public static function make(string $locale): self
    {
        return new self(Field::getInstance(), $locale);
    }

    /**
     * The field's own row.
     *
     * @return array<string,mixed>
     * @throws NotFoundException
     */
    public function find(int $id): array
    {
        $row = FieldQuery::find($id);
        if ($row === null) {
            throw new NotFoundException(_m('No such custom field.'));
        }

        return Db::stringifyRow($row);
    }

    /**
     * The field with its stored settings merged in, as forms and the API read it.
     *
     * @return array<string,mixed> empty when there is no such field
     */
    public function extended(int $id): array
    {
        return $this->fields->findByPrimaryKey($id);
    }

    /**
     * @return string[] the ids of the categories the field is assigned to
     */
    public function categoryIds(int $id): array
    {
        return $this->fields->categories($id);
    }

    /**
     * Every field, by position.
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        return $this->fields->listAll();
    }

    /**
     * The fields a category's listings carry, its parents' included.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forCategory(int $categoryId): array
    {
        return $this->fields->findByCategory($categoryId);
    }

    /**
     * @param array{name:mixed,type:string,slug?:string,required?:bool,searchable?:bool,options?:array<int,mixed>,categories?:array<int,mixed>} $field
     *
     * @return int the new id
     * @throws InvalidException for a blank or taken name, a bad choice, or a category that does not exist
     */
    public function create(array $field): int
    {
        $name = AdminText::name($field['name'] ?? '', '/name');
        $this->checkName($name, 0);
        $options    = AdminText::options((array) ($field['options'] ?? []));
        $categories = $this->checkCategories((array) ($field['categories'] ?? []));
        $fields     = $this->fields;

        return (int) Db::transaction(static function () use ($fields, $field, $name, $options, $categories): int {
            $id = (int) $fields->insertField(
                $name,
                strtoupper((string) $field['type']),
                FieldSlug::unique(trim((string) ($field['slug'] ?? '')) !== '' ? (string) $field['slug'] : $name),
                ($field['required'] ?? false) ? 1 : 0,
                $options,
                $categories
            );
            if ($id <= 0) {
                throw new \RuntimeException('The custom field could not be saved.');
            }
            $fields->update(['b_searchable' => ($field['searchable'] ?? false) ? 1 : 0], ['pk_i_id' => $id]);

            return $id;
        });
    }

    /**
     * Change what $changes holds; the rest keeps its value. `categories` replaces the list.
     *
     * @param array<string,mixed> $changes name, slug, type, required, searchable, options, categories
     *
     * @throws NotFoundException
     * @throws InvalidException for a blank or taken name, a bad choice, or a category that does not exist
     */
    public function update(int $id, array $changes): void
    {
        $field = $this->find($id);
        $name  = array_key_exists('name', $changes) ? AdminText::name($changes['name'], '/name') : (string) $field['s_name'];
        $this->checkName($name, $id);
        $categories = array_key_exists('categories', $changes) ? $this->checkCategories((array) $changes['categories']) : null;
        $has        = static fn (string $member): bool => array_key_exists($member, $changes);
        $row        = [
            's_name'       => $name,
            's_slug'       => $has('slug') || $name !== (string) $field['s_name']
                ? FieldSlug::unique(trim((string) ($changes['slug'] ?? '')) !== '' ? (string) $changes['slug'] : $name, $id)
                : (string) $field['s_slug'],
            'e_type'       => $has('type') ? strtoupper((string) $changes['type']) : (string) $field['e_type'],
            'b_required'   => $has('required') ? ($changes['required'] ? 1 : 0) : (int) $field['b_required'],
            'b_searchable' => $has('searchable') ? ($changes['searchable'] ? 1 : 0) : (int) $field['b_searchable'],
            's_options'    => $has('options') ? AdminText::options((array) $changes['options']) : (string) $field['s_options'],
        ];
        $fields = $this->fields;
        Db::transaction(function () use ($fields, $id, $row, $categories): void {
            if ($fields->update($row, ['pk_i_id' => $id]) === false) {
                throw new \RuntimeException('The custom field could not be saved.');
            }
            $this->renameMeta($id, $row['s_name']);
            if ($categories !== null) {
                $fields->cleanCategoriesFromField($id);
                if ($categories !== [] && !$fields->insertCategories($id, $categories)) {
                    throw new \RuntimeException('The custom field\'s categories could not be saved.');
                }
            }
        });
    }

    /**
     * Delete a field and its values.
     *
     * @throws NotFoundException
     */
    public function delete(int $id): void
    {
        $id = (int) $this->find($id)['pk_i_id'];
        if ((int) $this->fields->deleteByPrimaryKey($id) <= 0) {
            throw new \RuntimeException('The custom field could not be deleted.');
        }
    }

    /**
     * Whether a field other than $self already has this name.
     */
    public function nameTaken(string $name, int $self): bool
    {
        $taken = $this->fields->findByName($name);

        return isset($taken['pk_i_id']) && (int) $taken['pk_i_id'] !== $self;
    }

    /**
     * @throws InvalidException when another field has the name
     */
    private function checkName(string $name, int $self): void
    {
        if ($this->nameTaken($name, $self)) {
            throw new InvalidException('/name', 'taken', _m('is the name of another custom field'));
        }
    }

    /**
     * @param array<int,mixed> $ids
     *
     * @return int[]
     * @throws InvalidException for a category that does not exist
     */
    private function checkCategories(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids !== [] && \mindstellar\category\CategoryStore::countIds($ids) !== count($ids)) {
            throw new InvalidException('/categories', 'unknown', _m('names a category that does not exist'));
        }

        return $ids;
    }

    /**
     * A field named in the field editor keeps its name per language in its meta; the site's
     * language follows a rename.
     */
    private function renameMeta(int $id, string $name): void
    {
        $names = $this->fields->getJsonMetaValue('locale', null, $id);
        if (!is_array($names) || $names === []) {
            return;
        }
        $names[$this->locale] = ['s_name' => $name];
        $this->fields->updateJsonMeta($id, 'locale', $names);
    }
}
