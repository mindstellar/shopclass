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
use mindstellar\utility\AjaxResponse;
use mindstellar\utility\Utils;
use mindstellar\validation\InvalidException;
use mindstellar\validation\RefusedException;
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
        $aIds  = json_decode(Params::getParam('list'), true);
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
        $this->controller->_exportVariableToView(
            'category',
            Category::getInstance()->findByPrimaryKey(Params::getParam('id'), 'all')
        );
        if (count(Category::getInstance()->findSubcategories(Params::getParam('id'))) > 0) {
            $this->controller->_exportVariableToView('has_subcategories', true);
        } else {
            $this->controller->_exportVariableToView('has_subcategories', false);
        }
        $this->controller->_exportVariableToView('languages', OSCLocale::getInstance()->listAllEnabled());
        $this->controller->doView('categories/iframe.php');
    }

    public function enable(): void
    {
        $id       = strip_tags(Params::getParam('id'));
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
                ? array('ok' => __('The category is too large to delete at once. It has been hidden and is being emptied in the background.'))
                : array('ok' => __('The categories have been deleted'));
        } catch (RefusedException | RuntimeException $e) {
            $result = array('error' => __('An error occurred while deleting'));
        }

        AjaxResponse::json($result);
    }

    public function editPost(): void
    {
        $id                             = Params::getParam('id');
        $fields['i_expiration_days']    =
            (Params::getParam('i_expiration_days') != '') ? Params::getParam('i_expiration_days') : 0;
        $fields['b_price_enabled']      = (Params::getParam('b_price_enabled') != '') ? 1 : 0;
        $apply_changes_to_subcategories =
            Params::getParam('apply_changes_to_subcategories') == 1 ? true : false;

        $error         = 0;
        $has_one_title = 0;
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

        $l = osc_language();
        // The service fires edited_category with the outcome, saved or not.
        $editor = CategoryService::make();
        if ($error == 0 || ($error == 1 && $has_one_title == 1)) {
            try {
                if (!$editor->update((int) $id, $fields, $aFieldsDescription, $apply_changes_to_subcategories, (int) $error)) {
                    $error = 2;
                }
            } catch (InvalidException $e) {
                AjaxResponse::json(array('error' => 3, 'msg' => $e->getMessage()));

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
        AjaxResponse::json(array('error' => $error, 'msg' => $msg, 'text' => $aFieldsDescription[$l]['s_name']));
    }
}
