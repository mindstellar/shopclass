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

namespace mindstellar\admin\ajax;

use Category;
use mindstellar\category\CategoryService;
use mindstellar\category\CategoryStore;
use mindstellar\exception\InvalidException;
use mindstellar\exception\RefusedException;
use mindstellar\utility\AjaxResponse;
use mindstellar\utility\Utils;
use OSCLocale;
use Params;
use RuntimeException;

/**
 * The Categories screen: drag-and-drop order, the edit frame, enable, delete and save.
 */
final class CategoryAjax extends AjaxHandler
{
    /** Save the order of the categories. */
    public function order(): void
    {
        $aIds  = json_decode(Params::getParamString('list'), true);
        $order = array();
        $error = 0;

        $catManager  = Category::getInstance();
        $aRecountCat = array();
        foreach ($aIds as $cat) {
            if (isset($cat['c'])) {
                if (!isset($order[$cat['p']])) {
                    $order[$cat['p']] = 0;
                }

                $res = $catManager->update(
                    array(
                        'fk_i_parent_id' => ($cat['p'] === 'root' ? null : $cat['p']),
                        'i_position'     => $order[$cat['p']]
                    ),
                    array('pk_i_id' => $cat['c'])
                );
                if (is_bool($res) && !$res) {
                    $error = 1;
                } elseif ($res == 1) {
                    $aRecountCat[] = $cat['c'];
                }
                ++$order[$cat['p']];
            }
        }

        // A move changes the totals of both the old and the new parents, so recount the tree.
        if ($aRecountCat !== array()) {
            Utils::updateAllCategoriesStats();
        }

        $result = self::orderResult($error);

        osc_run_hook('edited_category_order', $error);

        AjaxResponse::json($result);
    }

    public function editIframe(): void
    {
        $tree = CategoryStore::tree();
        $id   = Params::getParamInt('id');
        if ($id <= 0) {
            // A new category: the same form, empty, saved by editPost() as a create. It starts from
            // the parent's expiry and price setting.
            $parentId = Params::getParamInt('parent_id');
            $parentId = $parentId > 0 && $tree->exists($parentId) ? $parentId : 0;
            $inherit  = $parentId > 0 ? Category::getInstance()->findByPrimaryKey($parentId) : false;
            $this->controller->_exportVariableToView(
                'category',
                array(
                    'pk_i_id'           => '',
                    'fk_i_parent_id'    => $parentId > 0 ? $parentId : null,
                    'i_expiration_days' => is_array($inherit) ? (int) $inherit['i_expiration_days'] : 0,
                    'b_price_enabled'   => is_array($inherit) ? (int) $inherit['b_price_enabled'] : 1,
                    'locale'            => array(),
                )
            );
            $this->controller->_exportVariableToView('has_subcategories', false);
            $this->controller->_exportVariableToView('subcategory_count', 0);
            $this->controller->_exportVariableToView('parent', $this->parentInfo($parentId));
            $this->controller->_exportVariableToView('languages', OSCLocale::getInstance()->listAllEnabled());
            $this->controller->doView('categories/iframe.php');

            return;
        }
        $category = Category::getInstance()->findByPrimaryKey($id, 'all');
        $this->controller->_exportVariableToView('category', $category);
        $this->controller->_exportVariableToView('has_subcategories', count($tree->children($id)) > 0);
        $this->controller->_exportVariableToView('subcategory_count', max(0, count($tree->subtree($id)) - 1));
        $this->controller->_exportVariableToView('parent', $this->parentInfo((int) $tree->parent($id)));
        $this->controller->_exportVariableToView('languages', OSCLocale::getInstance()->listAllEnabled());
        $this->controller->doView('categories/iframe.php');
    }

    /**
     * The parent's id and name for the drawer, or null for the top level.
     *
     * @return array{id:int,name:string}|null
     */
    private function parentInfo(int $parentId): ?array
    {
        if ($parentId <= 0) {
            return null;
        }
        $row = Category::getInstance()->findByPrimaryKey($parentId);

        return array('id' => $parentId, 'name' => is_array($row) ? (string) ($row['s_name'] ?? '') : '');
    }

    public function enable(): void
    {
        $id       = (int) \mindstellar\utility\Sanitize::stripTags(Params::getParamString('id'));
        $enabled  = (Params::getParam('enabled') != '') ? Params::getParam('enabled') : 0;

        $aCategory = Category::getInstance()->findByPrimaryKey($id);

        if ($aCategory == false) {
            AjaxResponse::json(array('error' => sprintf(__('No category with id %d exists'), $id)));

            return;
        }

        $affected = CategoryService::make()->setEnabled((int) $id, (bool) $enabled, $aCategory);
        if ($affected === null) {
            AjaxResponse::json(array('error' => __('Parent category is disabled, you can not enable that category')));

            return;
        }
        if ($aCategory['fk_i_parent_id'] == '') {
            $result = array('ok' => $enabled
                ? __('The category as well as its subcategories have been enabled')
                : __('The category as well as its subcategories have been disabled'));
        } else {
            $result = array('ok' => $enabled
                ? __('The subcategory has been enabled')
                : __('The subcategory has been disabled'));
        }
        $result['affectedIds'] = array_map(static fn (int $affectedId): array => array('id' => $affectedId), $affected);
        AjaxResponse::json($result);
    }

    public function delete(): void
    {
        try {
            $result = CategoryService::make()->delete(Params::getParamInt('id')) === 'queued'
                ? array('ok' => __('The category is too large to delete at once. It has been hidden and is being emptied in the background.'), 'queued' => true)
                : array('ok' => __('The categories have been deleted'), 'queued' => false);
        } catch (RefusedException | RuntimeException $e) {
            $result = array('error' => __('An error occurred while deleting'));
        }

        AjaxResponse::json($result);
    }

    /** Move a category under a parent (0 = top level) at a position among its siblings. */
    public function move(): void
    {
        $parent = Params::getParamInt('parent_id');
        try {
            CategoryService::make()->move(Params::getParamInt('id'), $parent > 0 ? $parent : null, Params::getParamInt('position'));
        } catch (RefusedException $e) {
            AjaxResponse::json(self::refusal($e));

            return;
        } catch (RuntimeException $e) {
            AjaxResponse::json(array('error' => __('An error occurred while updating')));

            return;
        }

        AjaxResponse::json(array('ok' => true, 'message' => __('Moved.')));
    }

    /** Enable, disable, delete, move or set the expiry of several categories at once. */
    public function bulk(): void
    {
        $ids = self::ids();
        if ($ids === array()) {
            AjaxResponse::json(array('error' => __('No such category.'), 'field' => '/ids'));

            return;
        }
        $do   = Params::getParamString('do');
        $args = array(
            'parent'          => Params::getParamInt('parent_id'),
            'position'        => Params::getParamInt('position'),
            'days'            => Params::getParamInt('days'),
            'newDates'        => Params::getParamString('expiry_listings') === 'new',
            'toSubcategories' => Params::getParamBool('to_subcategories'),
        );
        try {
            $result = CategoryService::make()->bulk($do, $ids, $args);
        } catch (RefusedException $e) {
            AjaxResponse::json(self::refusal($e));

            return;
        } catch (RuntimeException $e) {
            AjaxResponse::json(array('error' => __('An error occurred while updating')));

            return;
        }

        AjaxResponse::json(array('ok' => true) + $result + array('message' => self::bulkMessage($do, $result, $args['newDates'])));
    }

    /** What deleting these categories would remove. */
    public function deletePreview(): void
    {
        AjaxResponse::json(CategoryService::make()->deletePreview(self::ids()));
    }

    /** How many listings a new expiry would reach, and how many would end at once. */
    public function expiryPreview(): void
    {
        $days = max(0, Params::getParamInt('days'));
        AjaxResponse::json(CategoryService::make()->expiryPreview(self::ids(), $days, Params::getParamBool('to_subcategories')));
    }

    public function editPost(): void
    {
        $id                             = Params::getParam('id');
        $fields['i_expiration_days']    =
            (Params::getParam('i_expiration_days') != '') ? Params::getParam('i_expiration_days') : 0;
        $fields['b_price_enabled']      = (Params::getParam('b_price_enabled') != '') ? 1 : 0;
        $apply_changes_to_subcategories = Params::getParamInt('apply_changes_to_subcategories') === 1;
        $newDates                       = Params::getParamString('expiry_listings') === 'new';

        $error         = 0;
        $has_one_title = 0;
        $aFieldsDescription = array();
        foreach (Params::getParamsAsArray() as $k => $v) {
            if (preg_match('|(.+?)#(.+)|', (string) $k, $m)) {
                if ($m[2] === 's_name') {
                    if ($v != '') {
                        $has_one_title                    = 1;
                        $aFieldsDescription[$m[1]][$m[2]] = $v;
                    } else {
                        $aFieldsDescription[$m[1]][$m[2]] = null;
                        $error                            = 1;
                    }
                } else {
                    $aFieldsDescription[$m[1]][$m[2]] = $v;
                }
            }
        }

        $l     = osc_language();
        $moved = false;
        if ((int) $id <= 0) {
            AjaxResponse::json($this->create($fields, $aFieldsDescription, $has_one_title === 1, Params::getParamInt('parent_id')));

            return;
        }
        // The service fires edited_category with the outcome, saved or not.
        $editor = CategoryService::make();
        $canSave = $error == 0 || $has_one_title == 1;
        // A changed parent moves the category to the end of its new group, but only when the fields can be saved too.
        if ($canSave && Params::getParamString('parent_id') !== '') {
            $newParent = Params::getParamInt('parent_id');
            if ($newParent !== (int) CategoryStore::tree()->parent((int) $id)) {
                try {
                    $editor->move((int) $id, $newParent > 0 ? $newParent : null, PHP_INT_MAX);
                    $moved = true;
                } catch (RefusedException $e) {
                    AjaxResponse::json(array('error' => 3, 'msg' => $e->getMessage(), 'field' => '/parent_id'));

                    return;
                }
            }
        }
        if ($canSave) {
            try {
                if (!$editor->update((int) $id, $fields, $aFieldsDescription, $apply_changes_to_subcategories, (int) $error, $newDates)) {
                    $error = 2;
                }
            } catch (InvalidException $e) {
                AjaxResponse::json(array(
                    'error'     => 3,
                    'msg'       => $e->getMessage(),
                    'parent_id' => (int) CategoryStore::tree()->parent((int) $id),
                    'moved'     => $moved,
                ));

                return;
            }
        } else {
            $editor->edited((int) $id, (int) $error);
        }

        if ($error == 0) {
            $msg = __('Category updated correctly');
        } elseif ($error == 1) {
            if ($has_one_title == 1) {
                $error = 4;
                $msg   = __('Category updated correctly, but some titles are empty');
            } else {
                $msg = __('Sorry, including at least a title is mandatory');
            }
        } elseif ($error == 2) {
            $msg = __('An error occurred while updating');
        }
        AjaxResponse::json(array(
            'error'     => $error,
            'msg'       => $msg,
            'text'      => $aFieldsDescription[$l]['s_name'],
            'parent_id' => (int) CategoryStore::tree()->parent((int) $id),
            'moved'     => $moved,
        ));
    }

    /**
     * Create a category from the drawer, under $parentId (0 = top level), with the names typed
     * there. The slug comes from the name, so no placeholder row is ever written.
     *
     * @param array<string,mixed>               $fields
     * @param array<string,array<string,mixed>> $descriptions
     *
     * @return array<string,mixed>
     */
    private function create(array $fields, array $descriptions, bool $hasTitle, int $parentId = 0): array
    {
        if (!$hasTitle) {
            return array('error' => 1, 'msg' => __('Sorry, including at least a title is mandatory'));
        }
        // A language left blank gets no row; the editor can fill it in later.
        $descriptions = array_filter($descriptions, static fn (array $d): bool => ($d['s_name'] ?? '') !== '');
        foreach ($descriptions as $locale => $d) {
            unset($descriptions[$locale]['s_slug']);
        }
        try {
            $id = CategoryService::make()->create(
                $parentId > 0 ? $parentId : null,
                array('i_expiration_days' => (int) $fields['i_expiration_days'], 'b_price_enabled' => (int) $fields['b_price_enabled']),
                $descriptions,
                true,
                $parentId <= 0
            );
        } catch (RefusedException $e) {
            $refusal = self::refusal($e);

            return array('error' => 2, 'msg' => $refusal['error']) + array_intersect_key($refusal, array('field' => 1));
        } catch (RuntimeException $e) {
            return array('error' => 2, 'msg' => __('An error occurred while updating'));
        }
        $msg = __('A new category has been added');
        osc_add_flash_ok_message($msg, 'admin');

        return array('error' => 0, 'msg' => $msg, 'created' => true, 'id' => $id);
    }

    /**
     * The posted category ids: whole numbers above 0 only.
     *
     * @return int[]
     */
    private static function ids(): array
    {
        return array_values(array_filter(array_map('intval', Params::getParamArray('ids')), static fn (int $id): bool => $id > 0));
    }

    /**
     * @return array{error:string,field?:string}
     */
    private static function refusal(RefusedException $e): array
    {
        return $e instanceof InvalidException
            ? array('error' => $e->getMessage(), 'field' => $e->pointer())
            : array('error' => $e->getMessage());
    }

    /**
     * @param array{done:int[],failed:array<int,string>,queued:int[]} $result
     */
    private static function bulkMessage(string $do, array $result, bool $newDates): string
    {
        $done   = count($result['done']);
        $queued = count($result['queued']);

        return match ($do) {
            'enable'  => sprintf(_n('%d category enabled.', '%d categories enabled.', $done), $done),
            'disable' => sprintf(_n('%d category disabled.', '%d categories disabled.', $done), $done),
            'move'    => __('Moved.'),
            'delete'  => $queued > 0
                ? sprintf(_n('%d category is being deleted in the background.', '%d categories are being deleted in the background.', $queued), $queued)
                : sprintf(_n('%d category deleted.', '%d categories deleted.', $done), $done),
            default   => $queued > 0
                ? __('Listings are getting new end dates in the background.')
                : ($newDates ? __('Expiry saved. Listings get new end dates.') : __('Expiry saved. Listings keep their end dates.')),
        };
    }
}
