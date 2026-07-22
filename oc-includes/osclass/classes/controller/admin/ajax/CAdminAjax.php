<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\upgrade\Osclass;
use mindstellar\upgrade\Upgrade;
use mindstellar\utility\Utils;

define('IS_AJAX', true);

/**
 * Class CAdminAjax
 */
class CAdminAjax extends AdminSecBaseModel
{

    public function __construct()
    {
        parent::__construct();
        $this->ajax = true;
        if ($this->isModerator()
            && !in_array($this->action, array('items', 'media', 'comments', 'custom', 'runhook', 'save_admin_theme', 'save_sidebar_state', 'resource_upload', 'media_list'))
        ) {
            $this->action = 'error_permissions';
        }
    }

    //Business Layer...
    public function doModel()
    {
        //specific things for this class
        switch ($this->action) {
            case 'bulk_actions':
                break;
            case 'regions': //Return regions given a countryId
                $regions = Region::newInstance()->findByCountry(Params::getParam('countryId'));
                echo json_encode($regions);
                break;
            case 'cities': //Returns cities given a regionId
                $cities = City::newInstance()->findByRegion(Params::getParam('regionId'));
                echo json_encode($cities);
                break;
            case 'location': // This is the autocomplete AJAX
                $cities = City::newInstance()->ajax(Params::getParam('term'));
                echo json_encode($cities);
                break;
            case 'userajax': // This is the autocomplete AJAX
                $users = User::newInstance()->ajax(Params::getParam('term'));
                if (count($users) == 0) {
                    echo json_encode(array(
                        0 => array(
                            'id'    => '',
                            'label' => __('No results'),
                            'value' => __('No results')
                        )
                    ));
                } else {
                    echo json_encode($users);
                }
                break;
            case 'date_format':
                echo json_encode(array(
                    'format'        => Params::getParam('format'),
                    'str_formatted' => osc_format_date(date('Y-m-d H:i:s'), Params::getParam('format'))
                ));
                break;
            case 'media_list': // JSON media for the editor's media picker (read-only)
                header('Content-Type: application/json');
                $type = Params::getParam('type');
                if ($type === '' || $type === null) {
                    $type = 'all';
                }
                if (!in_array($type, array_merge(array('all', 'item'), osc_media_owner_types()), true)) {
                    $type = 'all';
                }
                $iPage   = max(1, (int) Params::getParam('iPage'));
                $perPage = 30;
                $data    = osc_media_library_query($type, $iPage, $perPage);

                $items = array();
                foreach ($data['rows'] as $row) {
                    $urls    = osc_media_row_urls($row);
                    $items[] = array(
                        'id'         => (int) $row['id'],
                        'src'        => $row['src'],
                        'owner_type' => $row['owner_type'],
                        'owner_id'   => (int) $row['owner_id'],
                        'name'       => (string) $row['s_name'],
                        'thumb'      => $urls['thumb'],
                        'url'        => $urls['full'],
                    );
                }
                echo json_encode(array(
                    'items'   => $items,
                    'total'   => (int) $data['total'],
                    'page'    => $iPage,
                    'perPage' => $perPage,
                    'more'    => ($iPage * $perPage) < (int) $data['total'],
                ));
                break;
            case 'resource_upload': // editor image upload -> the resource pipeline
                osc_csrf_check();
                header('Content-Type: application/json');

                $ownerType = Params::getParam('owner_type');
                $ownerId   = (int) Params::getParam('owner_id');

                // Two targets are allowed: a page image (must exist) and an
                // unattached library upload (ownerless). Whitelisting the owner
                // type here stops the generic endpoint writing for arbitrary owners.
                if ($ownerType === \mindstellar\model\Resource::OWNER_LIBRARY) {
                    $ownerId = 0;
                } elseif ($ownerType !== \mindstellar\model\Resource::OWNER_PAGE
                    || !osc_resource_owner_exists($ownerType, $ownerId)
                ) {
                    http_response_code(403);
                    echo json_encode(array('error' => __('Upload target not allowed')));
                    break;
                }

                if (!isset($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
                    http_response_code(400);
                    echo json_encode(array('error' => __('No file was received')));
                    break;
                }

                // ResourceUploader validates the file is a real image and rewrites
                // it into managed variants (storage-aware: local or S3), so nothing
                // untrusted is stored as-is.
                $row = (new \mindstellar\storage\ResourceUploader())
                    ->upload($ownerType, $ownerId, $_FILES['file']['tmp_name']);

                if ($row === false) {
                    http_response_code(415);
                    echo json_encode(array('error' => __('The file is not a valid image')));
                    break;
                }

                // TinyMCE expects { location: url }.
                echo json_encode(array('location' => osc_get_resource_url($row)));
                break;
            case 'save_admin_theme': // persist this admin's light/dark choice
                osc_csrf_check();
                $theme = Params::getParam('theme');
                // Whitelist: Params::getParam is raw request data, and this value is echoed
                // straight into the data-bs-theme attribute on every future page load.
                if ($theme !== 'dark' && $theme !== 'light') {
                    $theme = 'light';
                }
                // Namespaced by admin id: Shopclass has no admin-meta table, and t_preference
                // keys on (section, name) with no uniqueness beyond the pair, so one row per
                // admin under this section is a clean per-user store with no schema change.
                osc_set_preference((string) osc_logged_admin_id(), $theme, 'admin_theme', 'STRING');
                osc_reset_preferences();
                echo json_encode(array('done' => 1, 'theme' => $theme));
                break;
            case 'save_sidebar_state': // persist this admin's collapsed/expanded sidebar choice
                osc_csrf_check();
                $state = Params::getParam('state');
                // Whitelist: raw request data echoed straight into the data-osc-sidebar
                // attribute on every future page load. Same store and namespacing as
                // admin_theme above — one t_preference row per admin, no schema change.
                if ($state !== 'collapsed' && $state !== 'expanded') {
                    $state = 'expanded';
                }
                osc_set_preference((string) osc_logged_admin_id(), $state, 'admin_sidebar', 'STRING');
                osc_reset_preferences();
                echo json_encode(array('done' => 1, 'state' => $state));
                break;
            case 'runhook': // run hooks
                $hook = Params::getParam('hook');

                if ($hook == '') {
                    echo json_encode(array('error' => 'hook parameter not defined'));
                    break;
                }

                switch ($hook) {
                    case 'item_form':
                        osc_run_hook('item_form', Params::getParam('catId'));
                        break;
                    case 'item_edit':
                        $catId  = Params::getParam('catId');
                        $itemId = Params::getParam('itemId');
                        osc_run_hook('item_edit', $catId, $itemId);
                        break;
                    default:
                        osc_run_hook('ajax_admin_' . $hook);
                        break;
                }
                break;
            case 'categories_order': // Save the order of the categories
                osc_csrf_check();
                $aIds  = json_decode(Params::getParam('list'), true);
                $order = array();
                $error = 0;

                $catManager  = Category::newInstance();
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

                // update category stats
                foreach ($aRecountCat as $rId) {
                    Utils::updateCategoryStatsById($rId);
                }

                if ($error) {
                    $result = array('error' => __('An error occurred'));
                } else {
                    $result = array('ok' => __('Order saved'));
                }

                osc_run_hook('edited_category_order', $error);

                echo json_encode($result);
                break;
            case 'category_edit_iframe':
                $this->_exportVariableToView(
                    'category',
                    Category::newInstance()->findByPrimaryKey(Params::getParam('id'), 'all')
                );
                if (count(Category::newInstance()->findSubcategories(Params::getParam('id'))) > 0) {
                    $this->_exportVariableToView('has_subcategories', true);
                } else {
                    $this->_exportVariableToView('has_subcategories', false);
                }
                $this->_exportVariableToView('languages', OSCLocale::newInstance()->listAllEnabled());
                $this->doView('categories/iframe.php');
                break;
            case 'field_categories_iframe':
                $selected = Field::newInstance()->categories(Params::getParam('id'));
                if ($selected == null) {
                    $selected = array();
                }
                $this->_exportVariableToView('selected', $selected);
                $this->_exportVariableToView('field', Field::newInstance()->findByPrimaryKey(Params::getParam('id')));
                $this->_exportVariableToView('categories', Category::newInstance()->toTreeAll());
                // Sibling fields power the conditional-visibility "controlling field"
                // picker (a field can be shown/required based on another's value).
                $this->_exportVariableToView('allFields', Field::newInstance()->listAll());
                // Groups populate the membership dropdown (Ungrouped + each group).
                $this->_exportVariableToView('allGroups', FieldGroup::newInstance()->listAll());
                $this->doView('fields/iframe.php');
                break;
            case 'field_categories_post':
                osc_csrf_check();
                $error = 0;
                $field = Field::newInstance()->findByName(Params::getParam('s_name'));

                if (!isset($field['pk_i_id'])
                    || (isset($field['pk_i_id'])
                        && $field['pk_i_id'] == Params::getParam('id'))
                ) {
                    // remove categories from a field
                    Field::newInstance()->cleanCategoriesFromField(Params::getParam('id'));
                    // no error... continue updating fields
                    if ($error == 0) {
                        $slug     = Params::getParam('field_slug') != '' ? Params::getParam('field_slug')
                            : Params::getParam('s_name');
                        $slug     =
                            preg_replace('|([-]+)|', '-', preg_replace('|[^a-z0-9_-]|', '-', strtolower($slug)));
                        $slug_tmp = $slug;
                        $slug_k   = 0;
                        while (true) {
                            $field = Field::newInstance()->findBySlug($slug);
                            if (!$field || $field['pk_i_id'] == Params::getParam('id')) {
                                break;
                            }

                            $slug_k++;
                            $slug = $slug_tmp . '_' . $slug_k;
                        }
                        // prepare multi locale data
                        $currentAdminLocale = osc_current_admin_locale();
                        $aMetaNames        = Params::getParam('meta_s_name');
                        $metaLocale = array();
                        foreach ($aMetaNames as $k => $v) {
                            if (!empty($v)) {
                                $metaLocale[$k] = array(
                                    's_name' => trim($v),
                                );
                            }
                        }

                        $metaOptions     = Params::getParam('s_options');

                        // trim all csv options
                        if (!empty($metaOptions)) {
                            $tmpValues = explode(',', $metaOptions);
                            foreach ($tmpValues as $k => $v) {
                                $tmpValues[$k] = trim($v);
                            }
                            $metaOptions = implode(',', $tmpValues);
                            unset($tmpValues);
                        }

                        // The chosen type may be a registry type (e.g. EMAIL) that
                        // stores as a primitive. e_type holds the storage primitive;
                        // a non-primitive type persists its real id in s_meta['type'].
                        $chosenType   = Params::getParam('field_type');
                        $storageType  = osc_field_type_storage($chosenType);
                        $realTypeMeta = in_array($chosenType, \mindstellar\fields\FieldTypeRegistry::STORAGE_PRIMITIVES, true)
                            ? '' : $chosenType;

                        // Group membership: 0/empty means loose (Ungrouped).
                        $groupId = (int)Params::getParam('field_group');
                        $res = Field::newInstance()->update(
                            array(
                                's_name'        => $aMetaNames[$currentAdminLocale],
                                'e_type'        => $storageType,
                                's_slug'        => $slug,
                                'b_required'    => Params::getParam('field_required') == '1' ? 1 : 0,
                                'b_searchable'  => Params::getParam('field_searchable') == '1' ? 1 : 0,
                                's_options'     => $metaOptions,
                                'fk_i_group_id' => $groupId > 0 ? $groupId : null,
                            ),
                            array('pk_i_id' => Params::getParam('id'))
                        );
                        Field::newInstance()->updateJsonMeta(Params::getParam('id'), 'type', $realTypeMeta);
                        Field::newInstance()->updateJsonMeta(Params::getParam('id'), 'b_new_tab', Params::getParam('b_new_tab'));
                        Field::newInstance()->updateJsonMeta(Params::getParam('id'), 'locale', $metaLocale);
                        // Per-type config (placeholder, help text, numeric bounds, …).
                        self::persistFieldConfig((int)Params::getParam('id'), $chosenType);
                        if (is_bool($res) && !$res) {
                            $error = 1;
                        }
                    }
                    // no error... continue inserting categories-field
                    if ($error == 0) {
                        $aCategories = Params::getParam('categories');
                        if (is_array($aCategories) && count($aCategories) > 0) {
                            $res = Field::newInstance()->insertCategories(Params::getParam('id'), $aCategories);
                            if (!$res) {
                                $error = 1;
                            }
                        }
                    }
                    // error while updating?
                    if ($error == 1) {
                        $message = __('An error occurred while updating');
                    }
                } else {
                    $error   = 1;
                    $message = __('Sorry, you already have a field with that name');
                }

                if ($error) {
                    $result = array('error' => $message);
                } else {
                    $result = array(
                        'ok'       => __('Saved'),
                        'text'     => $aMetaNames[$currentAdminLocale],
                        'field_id' => Params::getParam('id')
                    );
                }

                echo json_encode($result);

                break;
            case 'delete_field':
                osc_csrf_check();
                $res = Field::newInstance()->deleteByPrimaryKey(Params::getParam('id'));

                if ($res > 0) {
                    $result = array('ok' => __('The custom field has been deleted'));
                } else {
                    $result = array('error' => __('An error occurred while deleting'));
                }

                echo json_encode($result);
                break;
            case 'add_field':
                osc_csrf_check();
                $s_name   = __('NEW custom field');
                $slug     = preg_replace('|([-]+)|', '-', preg_replace('|[^a-z0-9_-]|', '-', strtolower($s_name)));
                $slug_tmp = $slug;
                $slug_k   = 0;
                while (true) {
                    $field = Field::newInstance()->findBySlug($slug);
                    if (!$field || $field['pk_i_id'] == Params::getParam('id')) {
                        break;
                    }

                    $slug_k++;
                    $slug = $slug_tmp . '_' . $slug_k;
                }
                $fieldManager = Field::newInstance();
                $result       = $fieldManager->insertField($s_name, 'TEXT', $slug, 0, '', array());
                if ($result) {
                    echo json_encode(array(
                        'error'      => 0,
                        'field_id'   => $fieldManager->dao->insertedId(),
                        'field_name' => $s_name
                    ));
                } else {
                    echo json_encode(array('error' => 1));
                }
                break;
            case 'fields_order':
                osc_csrf_check();
                $aIds  = json_decode(Params::getParam('list'), true);
                $order = array();
                $error = 0;

                $fieldManager = Field::newInstance();
                foreach ($aIds as $pos => $field) {
                    $res = $fieldManager->update(
                        array(
                            'i_position' => $pos
                        ),
                        array('pk_i_id' => $field)
                    );
                    if (is_bool($res) && !$res) {
                        $error = 1;
                    }
                }

                if ($error) {
                    $result = array('error' => __('An error occurred'));
                } else {
                    $result = array('ok' => __('Order saved'));
                }

                echo json_encode($result);
                break;
            case 'add_group':
                osc_csrf_check();
                $groupManager = FieldGroup::newInstance();
                $newId        = $groupManager->insertGroup(__('New field group'));
                if ($newId) {
                    echo json_encode(array('error' => 0, 'group_id' => $newId, 'group_name' => __('New field group')));
                } else {
                    echo json_encode(array('error' => 1));
                }
                break;
            case 'group_post':
                osc_csrf_check();
                $groupManager = FieldGroup::newInstance();
                $groupId      = (int)Params::getParam('id');
                $name         = trim((string)Params::getParam('group_name'));
                $error        = 0;
                if ($groupId <= 0 || $name === '') {
                    $error = 1;
                } else {
                    $slugParam = trim((string)Params::getParam('group_slug'));
                    $existing  = $groupManager->findByPrimaryKey($groupId);
                    $slug      = $slugParam !== '' ? $slugParam : (isset($existing['s_slug']) ? $existing['s_slug'] : '');
                    // regenerate a unique slug only when it is empty or being changed
                    if ($slug === '' || (isset($existing['s_slug']) && $slug !== $existing['s_slug'])) {
                        $slug = $groupManager->uniqueSlug($slug !== '' ? $slug : $name);
                    }
                    $res = $groupManager->update(
                        array('s_name' => $name, 's_slug' => $slug),
                        array('pk_i_id' => $groupId)
                    );
                    if (is_bool($res) && !$res) {
                        $error = 1;
                    }
                    // rewrite the group's category assignments
                    $groupManager->cleanCategoriesFromGroup($groupId);
                    $aCategories = Params::getParam('categories');
                    if (is_array($aCategories) && count($aCategories) > 0) {
                        $groupManager->insertCategories($groupId, $aCategories);
                    }
                }
                if ($error) {
                    echo json_encode(array('error' => __('An error occurred while saving the group')));
                } else {
                    echo json_encode(array('ok' => __('Saved'), 'group_id' => $groupId, 'text' => $name));
                }
                break;
            case 'delete_group':
                osc_csrf_check();
                $res = FieldGroup::newInstance()->deleteByPrimaryKey((int)Params::getParam('id'));
                if ($res !== false) {
                    echo json_encode(array('ok' => __('The field group has been deleted')));
                } else {
                    echo json_encode(array('error' => __('An error occurred while deleting')));
                }
                break;
            case 'group_categories_iframe':
                $groupId = (int)Params::getParam('id');
                $selected = FieldGroup::newInstance()->categories($groupId);
                $this->_exportVariableToView('selected', $selected);
                $this->_exportVariableToView('group', FieldGroup::newInstance()->findByPrimaryKey($groupId));
                $this->_exportVariableToView('categories', Category::newInstance()->toTreeAll());
                $this->doView('fields/group_iframe.php');
                break;
            case 'enable_category':
                osc_csrf_check();
                $id       = strip_tags(Params::getParam('id'));
                $enabled  = (Params::getParam('enabled') != '') ? Params::getParam('enabled') : 0;
                $error    = 0;
                $result   = array();
                $aUpdated = array();

                $mCategory = Category::newInstance();
                $aCategory = $mCategory->findByPrimaryKey($id);

                if ($aCategory == false) {
                    $result = array('error' => sprintf(__('No category with id %d exists'), $id));
                    echo json_encode($result);
                    break;
                }

                // root category
                if ($aCategory['fk_i_parent_id'] == '') {
                    $mCategory->update(array('b_enabled' => $enabled), array('pk_i_id' => $id));
                    $mCategory->update(array('b_enabled' => $enabled), array('fk_i_parent_id' => $id));

                    $subCategories = $mCategory->findSubcategories($id);

                    $aIds       = array($id);
                    $aUpdated[] = array('id' => $id);
                    foreach ($subCategories as $subcategory) {
                        $aIds[]     = $subcategory['pk_i_id'];
                        $aUpdated[] = array('id' => $subcategory['pk_i_id']);
                    }

                    Item::newInstance()->enableByCategory($enabled, $aIds);

                    if ($enabled) {
                        $result = array(
                            'ok' => __('The category as well as its subcategories have been enabled')
                        );
                    } else {
                        $result = array(
                            'ok' => __('The category as well as its subcategories have been disabled')
                        );
                    }
                    $result['affectedIds'] = $aUpdated;
                    echo json_encode($result);
                    break;
                }

                // subcategory
                $parentCategory = $mCategory->findRootCategory($id);
                if (!$parentCategory['b_enabled']) {
                    $result = array('error' => __('Parent category is disabled, you can not enable that category'));
                    echo json_encode($result);
                    break;
                }

                $mCategory->update(array('b_enabled' => $enabled), array('pk_i_id' => $id));
                if ($enabled) {
                    $result = array(
                        'ok' => __('The subcategory has been enabled')
                    );
                } else {
                    $result = array(
                        'ok' => __('The subcategory has been disabled')
                    );
                }
                $result['affectedIds'] = array(array('id' => $id));
                echo json_encode($result);

                break;
            case 'delete_category':
                osc_csrf_check();
                $id    = Params::getParam('id');
                $error = 0;

                $categoryManager = Category::newInstance();
                $res             = $categoryManager->deleteByPrimaryKey($id);

                if ($res > 0) {
                    $message = __('The categories have been deleted');
                } else {
                    $error   = 1;
                    $message = __('An error occurred while deleting');
                }

                if ($error) {
                    $result = array('error' => $message);
                } else {
                    $result = array('ok' => $message);
                }
                echo json_encode($result);

                break;
            case 'edit_category_post':
                osc_csrf_check();
                $id                             = Params::getParam('id');
                $fields['i_expiration_days']    =
                    (Params::getParam('i_expiration_days') != '') ? Params::getParam('i_expiration_days') : 0;
                $fields['b_price_enabled']      = (Params::getParam('b_price_enabled') != '') ? 1 : 0;
                $apply_changes_to_subcategories =
                    Params::getParam('apply_changes_to_subcategories') == 1 ? true : false;

                $error         = 0;
                $has_one_title = 0;
                $postParams    = Params::getParamsAsArray();
                foreach ($postParams as $k => $v) {
                    if (preg_match('|(.+?)#(.+)|', $k, $m)) {
                        if ($m[2] === 's_name') {
                            if ($v != '') {
                                $has_one_title                    = 1;
                                $aFieldsDescription[$m[1]][$m[2]] = $v;
                                $s_text                           = $v;
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
                if ($error == 0 || ($error == 1 && $has_one_title == 1)) {
                    $categoryManager = Category::newInstance();
                    $res             = $categoryManager->updateByPrimaryKey(array(
                        'fields'             => $fields,
                        'aFieldsDescription' => $aFieldsDescription
                    ), $id);
                    $categoryManager->updateExpiration(
                        $id,
                        $fields['i_expiration_days'],
                        $apply_changes_to_subcategories
                    );
                    $categoryManager->updatePriceEnabled(
                        $id,
                        $fields['b_price_enabled'],
                        $apply_changes_to_subcategories
                    );
                    if (is_bool($res)) {
                        $error = 2;
                    }
                }

                osc_run_hook('edited_category', (int)($id), $error);

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
                echo json_encode(array('error' => $error, 'msg' => $msg, 'text' => $aFieldsDescription[$l]['s_name']));

                break;
            case 'custom': // Execute via AJAX custom file
                if (Params::existParam('route')) {
                    $routes = Rewrite::newInstance()->getRoutes();
                    $rid    = Params::getParam('route');
                    $file   = $routes[$rid]['file'] ?? '../';
                } else {
                    $file = Params::getParam('ajaxfile');
                }

                if ($file == '') {
                    echo json_encode(array('error' => 'no action defined'));
                    break;
                }

                // valid file?
                if (strpos($file, '../') !== false || strpos($file, '..\\') !== false) {
                    echo json_encode(array('error' => 'no valid file'));
                    break;
                }

                if (!file_exists(osc_plugins_path() . $file)) {
                    echo json_encode(array('error' => "file doesn't exist"));
                    break;
                }

                require_once osc_plugins_path() . $file;
                break;
            case 'test_mail':
                $title = sprintf(__('Test email, %s'), osc_page_title());
                $body  = __('Test email') . '<br><br>' . osc_page_title();

                $emailParams = array(
                    'subject'  => $title,
                    'to'       => osc_contact_email(),
                    'to_name'  => 'admin',
                    'body'     => $body,
                    'alt_body' => $body
                );

                $array = array();
                if (osc_sendMail($emailParams)) {
                    $array = array('status' => '1', 'html' => __('Email sent successfully'));
                } else {
                    $array = array('status' => '0', 'html' => __('An error occurred while sending email'));
                }
                echo json_encode($array);
                break;
            case 'test_mail_template':
                // replace por valores por defecto
                $email = Params::getParam('email');
                $title = Params::getParam('title');
                $body  = Params::getParam('body', false, false);

                $emailParams = array(
                    'subject'  => $title,
                    'to'       => $email,
                    'to_name'  => 'admin',
                    'body'     => $body,
                    'alt_body' => $body
                );

                $array = array();
                if (osc_sendMail($emailParams)) {
                    $array = array('status' => '1', 'html' => __('Email sent successfully'));
                } else {
                    $array = array('status' => '0', 'html' => __('An error occurred while sending email'));
                }
                echo json_encode($array);
                break;
            case 'order_pages':
                osc_csrf_check();
                $order = Params::getParam('order');
                $id    = Params::getParam('id');
                if ($order != '' && $id != '') {
                    $mPages       = Page::newInstance();
                    $actual_page  = $mPages->findByPrimaryKey($id);
                    $actual_order = $actual_page['i_order'];

                    $array     = array();
                    $condition = array();
                    $new_order = $actual_order;

                    if ($order === 'up') {
                        $page = $mPages->findPrevPage($actual_order);
                    } elseif ($order === 'down') {
                        $page = $mPages->findNextPage($actual_order);
                    }
                    if (isset($page['i_order'])) {
                        $mPages->update(array('i_order' => $page['i_order']), array('pk_i_id' => $id));
                        $mPages->update(array('i_order' => $actual_order), array('pk_i_id' => $page['pk_i_id']));
                    }
                }
                break;
            case 'check_version':
                // The whole flow is wrapped: getPackageInfo() reaches out to GitHub, and both
                // it and the Osclass constructor can throw when the API returns an unexpected
                // payload (rate limit, outage, a release with no downloadable asset). An
                // uncaught throwable here is a 500 on a routine background poll, so degrade to
                // a plain "couldn't check" instead. \Throwable, not Exception: a missing class
                // or type error is an Error, and Error is not an Exception.
                try {
                    $package_json = Osclass::getPackageInfo(true);
                    if (is_array($package_json) && !empty($package_json)) {
                        $upgradeOsclass    = new Osclass($package_json);
                        $upgrade_available = $upgradeOsclass->isUpgradable();
                        osc_set_preference('update_core_available', $upgrade_available ? '1' : '');
                        osc_set_preference('update_core_json', json_encode($package_json));
                        osc_set_preference('last_version_check', time());
                        echo json_encode(array(
                            'error' => 0,
                            'msg'   => $upgrade_available ? __('Update available') : __('No update available'),
                        ));
                    } else {
                        echo json_encode(array('error' => 1, 'msg' => __('Could not check for updates')));
                    }
                } catch (\Throwable $e) {
                    echo json_encode(array('error' => 1, 'msg' => __('Could not check for updates')));
                }

                break;
            case 'check_languages':
                $total = _osc_check_languages_update();
                echo json_encode(array('msg' => __('Checked updates'), 'total' => $total));
                break;
            case 'check_themes':
                $total = _osc_check_themes_update();
                echo json_encode(array('msg' => __('Checked updates'), 'total' => $total));
                break;
            case 'check_plugins':
                $total = _osc_check_plugins_update();
                echo json_encode(array('msg' => __('Checked updates'), 'total' => $total));
                break;

            /******************************
             ** COMPLETE UPGRADE PROCESS **
             ******************************/
            case 'upgrade': // AT THIS POINT WE KNOW IF THERE'S AN UPDATE OR NOT
                osc_csrf_check();
                if (defined('DEMO')) {
                    $msg    = __('This action cannot be done because it is a demo site');
                    $result = array('error' => 6, 'message' => $msg);
                    osc_add_flash_warning_message($msg, 'admin');
                } else {
                    $osclassUpgradeObj = new Osclass(Osclass::getPackageInfo());
                    $upgradeOsclass = new Upgrade($osclassUpgradeObj);
                    try {
                        $upgradeOsclass->doUpgrade();
                        $db_upgrade_result = json_decode($osclassUpgradeObj::upgradeDB(Params::getParam('skipdb')), true);
                        $result            = ['error' => $db_upgrade_result['error'], 'message' => $db_upgrade_result['message']];
                    } catch (Exception $e) {
                        $result = ['error' => 1, 'message' => $e->getMessage()];
                        osc_add_flash_error_message($e->getMessage(), 'admin');
                    }
                    if (isset($db_upgrade_result) && $db_upgrade_result['error'] == 1) {
                        $result = ['error' => 5, 'message' => $db_upgrade_result['message']];
                        osc_add_flash_warning_message(__('Error occurred while upgrading osclass Database.'), 'admin');
                    }
                }
                echo json_encode($result);
                break;
            case 'reinstall_osclass': // We are forcing an update
                osc_csrf_check();
                if (defined('DEMO')) {
                    $msg    = __('This action cannot be done because it is a demo site');
                    $result = array('error' => 6, 'message' => $msg);
                    osc_add_flash_warning_message($msg, 'admin');
                } else {
                    $osclassUpgradeObj = new Osclass(Osclass::getPackageInfo(), true);
                    $upgradeOsclass = new Upgrade($osclassUpgradeObj);
                    try {
                        $upgradeOsclass->doUpgrade();
                        $db_upgrade_result = json_decode($osclassUpgradeObj::upgradeDB(), true);
                        $result            = ['error' => 0, 'message' => __('Shopclass upgraded successfully.')];
                    } catch (Exception $e) {
                        $result = ['error' => 1, 'message' => $e->getMessage()];
                        osc_add_flash_error_message($e->getMessage(), 'admin');
                    }
                    if (isset($db_upgrade_result) && $db_upgrade_result['status'] !== true) {
                        $result = ['error' => 5, 'message' => $db_upgrade_result['message']];
                        osc_add_flash_warning_message(__('Error occurred while upgrading osclass Database.'), 'admin');
                    }
                }
                echo json_encode($result);
                break;
            case 'upgrade_db':
                osc_csrf_check();
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m('This action cannot be done because it is a demo site'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true));
                }
                $this->ajax     = true;
                $upgrade_result = Osclass::upgradeDB(Params::getParam('skipdb'));
                echo $upgrade_result;
                break;
            case 'location_stats':
                osc_csrf_check();
                $workToDo = osc_update_location_stats();
                if ($workToDo > 0) {
                    $array['status']  = 'more';
                    $array['pending'] = $workToDo;
                } else {
                    $array['status'] = 'done';
                }
                echo json_encode($array);
                break;
            case 'country_slug':
                $exists = Country::newInstance()->findBySlug(Params::getParam('slug'));
                if (isset($exists['s_slug'])) {
                    echo json_encode(array('error' => 1, 'country' => $exists));
                } else {
                    echo json_encode(array('error' => 0));
                }
                break;
            case 'region_slug':
                $exists = Region::newInstance()->findBySlug(Params::getParam('slug'));
                if (isset($exists['s_slug'])) {
                    echo json_encode(array('error' => 1, 'region' => $exists));
                } else {
                    echo json_encode(array('error' => 0));
                }
                break;
            case 'city_slug':
                $exists = City::newInstance()->findBySlug(Params::getParam('slug'));
                if (isset($exists['s_slug'])) {
                    echo json_encode(array('error' => 1, 'city' => $exists));
                } else {
                    echo json_encode(array('error' => 0));
                }
                break;
            case 'error_permissions':
                echo json_encode(array('error' => __("You don't have the necessary permissions")));
                break;
            default:
                echo json_encode(array('error' => __('no action defined')));
                break;
        }
        // clear all keep variables into session
        Session::newInstance()->_dropKeepForm();
        Session::newInstance()->_clearVariables();
    }

    //hopefully generic...

    /**
     * Persist the per-type configuration (placeholder, help text, numeric bounds,
     * conditional rules, …) for a field into its s_meta JSON. Only the config keys
     * the chosen type declares in the registry are read from the request, so a
     * posted key a type does not support is ignored. An empty value clears the key
     * (updateJsonMeta unsets on '' / null), keeping s_meta compact.
     *
     * @param int    $fieldId
     * @param string $typeId  the chosen (possibly non-primitive) field type id
     *
     * @return void
     */
    private static function persistFieldConfig($fieldId, $typeId)
    {
        $spec = osc_field_type($typeId);
        if ($spec === null || empty($spec['config'])) {
            return;
        }
        $field = Field::newInstance();
        foreach ($spec['config'] as $key) {
            // b_new_tab has its own dedicated checkbox handled above; skip it here.
            if ($key === 'b_new_tab') {
                continue;
            }
            $value = Params::getParam('cfg_' . $key);
            // numeric config keys stay numeric; everything else is a trimmed string.
            if (in_array($key, array('min', 'max', 'step', 'rows', 'maxlength'), true)) {
                $value = ($value === '' || $value === null) ? '' : $value + 0;
            } elseif (is_string($value)) {
                $value = trim($value);
            }
            $field->updateJsonMeta($fieldId, $key, $value);
        }

        // Conditional-visibility rules, posted as a JSON string by the builder.
        $rules = Params::getParam('cfg_rules');
        if (is_string($rules) && $rules !== '') {
            $decoded = json_decode($rules, true);
            $field->updateJsonMeta($fieldId, 'rules', is_array($decoded) ? $decoded : '');
        } else {
            $field->updateJsonMeta($fieldId, 'rules', '');
        }

        // Cascading options (small-set): a dropdown/radio whose options are filtered
        // by a parent field's value. cfg_cascade_map is a line-per-parent-value block
        // ("Toyota: Corolla, Camry"); parse it into a { parentValue: [opts] } map.
        $cascadeParent = trim((string)Params::getParam('cfg_cascade_parent'));
        $cascadeText   = (string)Params::getParam('cfg_cascade_map');
        if ($cascadeParent !== '' && trim($cascadeText) !== '') {
            $map = array();
            foreach (preg_split('/\r\n|\r|\n/', $cascadeText) as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, ':') === false) {
                    continue;
                }
                list($key, $vals) = explode(':', $line, 2);
                $key  = trim($key);
                $opts = array_values(array_filter(
                    array_map('trim', explode(',', $vals)),
                    static function ($v) {
                        return $v !== '';
                    }
                ));
                if ($key !== '' && $opts) {
                    $map[$key] = $opts;
                }
            }
            $field->updateJsonMeta($fieldId, 'cascade_parent', $map ? $cascadeParent : '');
            $field->updateJsonMeta($fieldId, 'cascade_map', $map ?: '');
        } else {
            $field->updateJsonMeta($fieldId, 'cascade_parent', '');
            $field->updateJsonMeta($fieldId, 'cascade_map', '');
        }
    }

    /**
     * @param $file
     *
     * @return void
     */
    public function doView($file)
    {
        osc_current_admin_theme_path($file);
    }
}
