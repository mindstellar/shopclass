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

use mindstellar\market\Catalog;
use mindstellar\market\Compatibility;
use mindstellar\market\Installer;
use mindstellar\market\PackageIndex;
use mindstellar\upgrade\Osclass;
use mindstellar\upgrade\Upgrade;
use mindstellar\utility\FileSystem;
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
            case 'location_catalog': // Countries the published catalog offers for import
                header('Content-Type: application/json');
                // Read through the catalog rather than letting the browser fetch the
                // published URL: that URL names the current release rather than listing
                // countries, and following it is server-side work that is cached here.
                $catalog = new \mindstellar\location\LocationCatalog();
                $rows    = array();
                foreach ($catalog->status(Params::getParamInt('refresh') === 1) as $row) {
                    if ($row['file'] === '' && $row['ndjson'] === '') {
                        continue;
                    }
                    // Only what the dialog draws. A country is named by its code rather
                    // than by a file path, so the list survives the catalog rearranging
                    // its files; the checksums are the bulk of a row and the browser has
                    // no use for them.
                    $rows[] = array(
                        'code'      => $row['code'],
                        'name'      => $row['name'],
                        'installed' => (bool) $row['installed'],
                        'current'   => (bool) $row['current'],
                        'rows'      => (int) $row['rows'],
                    );
                }
                echo json_encode(array(
                    'release'   => $catalog->release(),
                    'countries' => $rows,
                ));
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
                $iPage   = max(1, Params::getParamInt('iPage'));
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
                $ownerId   = Params::getParamInt('owner_id');

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
                // How many forms this field is placed in — the editor warns when a save
                // will change the field in more than one form.
                $this->_exportVariableToView(
                    'form_count',
                    (new \mindstellar\forms\FormService())->formCountForField(Params::getParamInt('id'))
                );
                $this->doView('fields/iframe.php');
                break;
            case 'field_categories_post':
                osc_csrf_check();
                $error = 0;
                // Builder mode edits the field DEFINITION only; form membership and
                // category placement are managed by drag-drop / on the form, so those
                // controls are absent from the post and must not be cleared here.
                $builderMode = Params::getParam('builder') == '1';
                $field = Field::newInstance()->findByName(Params::getParam('s_name'));

                if (!isset($field['pk_i_id'])
                    || (isset($field['pk_i_id'])
                        && $field['pk_i_id'] == Params::getParam('id'))
                ) {
                    // remove categories from a field (definition-only saves keep them)
                    if (!$builderMode) {
                        Field::newInstance()->cleanCategoriesFromField(Params::getParam('id'));
                    }
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
                        $aMetaNames        = Params::getParamArray('meta_s_name');
                        $metaLocale = array();
                        foreach ($aMetaNames as $k => $v) {
                            if (!empty($v)) {
                                $metaLocale[$k] = array(
                                    's_name' => trim($v),
                                );
                            }
                        }

                        $metaOptions     = Params::getParamString('s_options');

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

                        // Definition fields always saved. Group membership is only
                        // touched by the legacy single-group editor (not builder mode).
                        $updateData = array(
                            's_name'       => $aMetaNames[$currentAdminLocale],
                            'e_type'       => $storageType,
                            's_slug'       => $slug,
                            'b_required'   => Params::getParam('field_required') == '1' ? 1 : 0,
                            'b_searchable' => Params::getParam('field_searchable') == '1' ? 1 : 0,
                            's_options'    => $metaOptions,
                        );
                        if (!$builderMode) {
                            $groupId = Params::getParamInt('field_group');
                            $updateData['fk_i_group_id'] = $groupId > 0 ? $groupId : null;
                        }
                        $res = Field::newInstance()->update($updateData, array('pk_i_id' => Params::getParam('id')));
                        // Keep the link table (source of truth) in sync with the
                        // single-group editor; the builder manages links via drag-drop.
                        if (!$builderMode) {
                            FieldGroup::newInstance()->setFieldSingleGroup(Params::getParamInt('id'), $groupId);
                        }
                        Field::newInstance()->updateJsonMeta(Params::getParam('id'), 'type', $realTypeMeta);
                        Field::newInstance()->updateJsonMeta(Params::getParam('id'), 'b_new_tab', Params::getParam('b_new_tab'));
                        Field::newInstance()->updateJsonMeta(Params::getParam('id'), 'locale', $metaLocale);
                        // Per-type config (placeholder, help text, numeric bounds, …).
                        self::persistFieldConfig(Params::getParamInt('id'), $chosenType);
                        if (is_bool($res) && !$res) {
                            $error = 1;
                        }
                    }
                    // no error... continue inserting categories-field (skipped in
                    // builder mode, which does not manage per-field categories)
                    if ($error == 0 && !$builderMode) {
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
                    $typeSpec = osc_field_type($chosenType);
                    $result = array(
                        'ok'         => __('Saved'),
                        'text'       => $aMetaNames[$currentAdminLocale],
                        'field_id'   => Params::getParam('id'),
                        'type_label' => $typeSpec !== null ? __($typeSpec['label']) : $chosenType,
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
                $fieldId      = $fieldManager->insertField($s_name, 'TEXT', $slug, 0, '', array());
                if ($fieldId) {
                    echo json_encode(array(
                        'error'      => 0,
                        'field_id'   => $fieldId,
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
                $groupId      = Params::getParamInt('id');
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
                    // "Available as a block" flag → s_meta.placeable (core.form picker)
                    $groupManager->setMeta($groupId, 'placeable', Params::getParam('group_placeable') == '1' ? 1 : '');
                }
                if ($error) {
                    echo json_encode(array('error' => __('An error occurred while saving the group')));
                } else {
                    echo json_encode(array('ok' => __('Saved'), 'group_id' => $groupId, 'text' => $name));
                }
                break;
            case 'delete_group':
                osc_csrf_check();
                // Row count, not just "did not throw": deleteByPrimaryKey() reports 0
                // for an id that matched nothing, and reporting that as a success left
                // the deleted form sitting in the list until the page was reloaded.
                $res = FieldGroup::newInstance()->deleteByPrimaryKey(Params::getParamInt('id'));
                if ($res > 0) {
                    echo json_encode(array('ok' => __('The field group has been deleted')));
                } else {
                    echo json_encode(array('error' => __('An error occurred while deleting')));
                }
                break;
            case 'form_set_fields':
                // The builder posts a form's whole ordered field list after each drag;
                // FormService reconciles the link table (add/remove/reorder in one go).
                osc_csrf_check();
                $formId = Params::getParamInt('form_id');
                $ids    = json_decode((string)Params::getParam('fields'), true);
                if ($formId <= 0 || !is_array($ids)) {
                    echo json_encode(array('error' => __('Invalid request')));
                    break;
                }
                $service = new \mindstellar\forms\FormService();
                if ($service->setFormFields($formId, $ids)) {
                    echo json_encode(array('ok' => __('Saved')));
                } else {
                    echo json_encode(array('error' => __('An error occurred while saving the form')));
                }
                break;
            case 'migrate_loose_fields':
                // Bridge legacy loose fields into the forms model: gather fields that
                // sit directly on categories but in no form into forms (one per
                // distinct category set), so the builder can manage them. Reversible —
                // see FormService::migrateLooseFields().
                osc_csrf_check();
                $result = (new \mindstellar\forms\FormService())->migrateLooseFields();
                if ($result['forms'] === 0) {
                    echo json_encode(array('ok' => __('There were no fields to move.')));
                } else {
                    echo json_encode(array('ok' => sprintf(
                        __('Moved %1$d fields into %2$d forms.'),
                        $result['fields'],
                        $result['forms']
                    )));
                }
                break;
            case 'form_submission_status':
                osc_csrf_check();
                $ok = \mindstellar\model\FormSubmission::newInstance()
                    ->setStatus(Params::getParamInt('id'), (string)Params::getParam('status'));
                echo json_encode($ok ? array('ok' => __('Saved')) : array('error' => __('An error occurred')));
                break;
            case 'form_submission_delete':
                osc_csrf_check();
                $ok = \mindstellar\model\FormSubmission::newInstance()->delete(Params::getParamInt('id'));
                echo json_encode($ok ? array('ok' => __('The submission has been deleted')) : array('error' => __('An error occurred while deleting')));
                break;
            case 'form_submissions_purge':
                osc_csrf_check();
                $res = \mindstellar\model\FormSubmission::newInstance()->deleteByForm(Params::getParamInt('form_id'));
                echo json_encode($res !== false ? array('ok' => __('All submissions for this form have been deleted')) : array('error' => __('An error occurred while deleting')));
                break;
            case 'group_categories_iframe':
                $groupId = Params::getParamInt('id');
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
                    $isFresh      = false;
                    $package_json = Osclass::getPackageInfo(true, $isFresh);
                    if ($isFresh && is_array($package_json) && !empty($package_json)) {
                        // A live fetch succeeded: record the result and reset the once-a-day clock.
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
                        // The fetch produced nothing and getPackageInfo() served the stale cache
                        // (or nothing). Do NOT reset the full-day clock — that would freeze the
                        // badge on last-known-good data for 24h and hide a release published during
                        // the outage — and do NOT touch update_core_available/json. Instead schedule
                        // the next attempt one hour out, so the admin footer retries soon without
                        // re-hitting GitHub on every page load (its throttle keys off this stamp).
                        self::scheduleUpdateCheckRetry();
                        echo json_encode(array('error' => 1, 'msg' => __('Could not check for updates')));
                    }
                } catch (\Throwable $e) {
                    self::scheduleUpdateCheckRetry();
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

                /**********************
                 ** PACKAGE MARKET   **
                 ** docs/MARKET.md §8.2 **
                 **********************/
            case 'market_refresh':
                osc_csrf_check();
                header('Content-Type: application/json');
                echo json_encode($this->marketRefresh(Params::getParamString('type')));
                break;
            case 'market_install':
                osc_csrf_check();
                header('Content-Type: application/json');
                echo json_encode($this->marketInstallOrUpdate(
                    'install',
                    Params::getParamString('type'),
                    Params::getParamString('slug'),
                    Params::getParamString('version')
                ));
                break;
            case 'market_update':
                osc_csrf_check();
                header('Content-Type: application/json');
                echo json_encode($this->marketInstallOrUpdate(
                    'update',
                    Params::getParamString('type'),
                    Params::getParamString('slug'),
                    Params::getParamString('version')
                ));
                break;
            case 'market_detail':
                osc_csrf_check();
                header('Content-Type: application/json');
                echo json_encode($this->marketDetail(
                    Params::getParamString('type'),
                    Params::getParamString('slug')
                ));
                break;

                /******************************
                 ** COMPLETE UPGRADE PROCESS **
                 ******************************/
            case 'upgrade': // AT THIS POINT WE KNOW IF THERE'S AN UPDATE OR NOT
                osc_csrf_check();
                if (osc_self_update_disabled()) {
                    $msg    = __('In-app updates are disabled on this installation. Update by deploying a newer container image; the database is migrated automatically on start.');
                    $result = array('error' => 6, 'message' => $msg);
                    osc_add_flash_warning_message($msg, 'admin');
                } elseif (defined('DEMO')) {
                    $msg    = __('This action cannot be done because it is a demo site');
                    $result = array('error' => 6, 'message' => $msg);
                    osc_add_flash_warning_message($msg, 'admin');
                } else {
                    $osclassUpgradeObj = new Osclass(Osclass::getPackageInfo());
                    $upgradeOsclass = new Upgrade($osclassUpgradeObj);
                    try {
                        $upgradeOsclass->doUpgrade();
                        $db_upgrade_result = json_decode($osclassUpgradeObj::upgradeDB(Params::getParam('skipdb')), true);
                        $result            = [
                            'error'   => $db_upgrade_result['error'],
                            'message' => $db_upgrade_result['message'],
                            'repairs' => $db_upgrade_result['repairs'] ?? [],
                        ];
                        $this->flashSchemaRepairs($result['repairs']);
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
                if (osc_self_update_disabled()) {
                    $msg    = __('In-app updates are disabled on this installation. Update by deploying a newer container image; the database is migrated automatically on start.');
                    $result = array('error' => 6, 'message' => $msg);
                    osc_add_flash_warning_message($msg, 'admin');
                } elseif (defined('DEMO')) {
                    $msg    = __('This action cannot be done because it is a demo site');
                    $result = array('error' => 6, 'message' => $msg);
                    osc_add_flash_warning_message($msg, 'admin');
                } else {
                    $osclassUpgradeObj = new Osclass(Osclass::getPackageInfo(), true);
                    $upgradeOsclass = new Upgrade($osclassUpgradeObj);
                    try {
                        $upgradeOsclass->doUpgrade();
                        $db_upgrade_result = json_decode($osclassUpgradeObj::upgradeDB(), true);
                        $result            = [
                            'error'   => 0,
                            'message' => __('Shopclass upgraded successfully.'),
                            'repairs' => $db_upgrade_result['repairs'] ?? [],
                        ];
                        $this->flashSchemaRepairs($result['repairs']);
                    } catch (Exception $e) {
                        $result = ['error' => 1, 'message' => $e->getMessage()];
                        osc_add_flash_error_message($e->getMessage(), 'admin');
                    }
                    // upgradeDB() reports through 'error', and never returned a 'status'
                    // key at all — so this read an absent index and treated every
                    // successful reinstall as a database failure.
                    if (isset($db_upgrade_result) && (int) $db_upgrade_result['error'] !== 0) {
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
        // cascade_parent is a field slug; reject anything that is not slug-shaped so a
        // crafted value cannot ride through into rendered markup.
        if ($cascadeParent !== '' && !preg_match('/^[a-z0-9_-]+$/', $cascadeParent)) {
            $cascadeParent = '';
        }
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
     * Back-date the core update-check timestamp so the next attempt is due in about an
     * hour instead of a full day, used when a check could not reach GitHub. The admin
     * footer poll and getPackageInfo()'s own throttle both fire when
     * now - last_version_check exceeds 24h, so leaving one hour on that clock retries
     * soon without re-hitting GitHub on every admin page load during an outage.
     *
     * @return void
     */
    private static function scheduleUpdateCheckRetry()
    {
        $dayInSeconds   = 24 * 3600;
        $retryInSeconds = 3600;
        osc_set_preference('last_version_check', time() - ($dayInSeconds - $retryInSeconds));
    }

    /**
     * Validates the posted `type` against the market's two package kinds, before it
     * reaches a filesystem path or a Catalog/Installer factory call.
     *
     * @param string $type
     *
     * @return string|null 'plugin', 'theme', or null when neither
     */
    private static function marketType($type)
    {
        return in_array($type, array('plugin', 'theme'), true) ? $type : null;
    }

    private static function marketCatalog($type)
    {
        return $type === 'theme' ? Catalog::forThemes() : Catalog::forPlugins();
    }

    private static function marketPackageIndex($type)
    {
        return $type === 'theme' ? PackageIndex::forThemes() : PackageIndex::forPlugins();
    }

    private static function marketInstaller($type)
    {
        return $type === 'theme' ? Installer::forThemes() : Installer::forPlugins();
    }

    /**
     * Say so when the upgrade had to repair the schema on its way through.
     *
     * The migrations build the schema and a release cannot ship unless they reproduce
     * it on their own, so this list is empty on an install in good order. A non-empty
     * one means the database had drifted by some other route -- a hand-edited column, a
     * plugin's leftovers, an upgrade interrupted half way -- and the site owner is
     * better off knowing that happened than having it fixed silently.
     *
     * @param array $repairs statements the repair pass applied
     */
    private function flashSchemaRepairs($repairs)
    {
        if (!is_array($repairs) || $repairs === array()) {
            return;
        }

        osc_add_flash_warning_message(
            sprintf(
                __('The database schema had drifted and %d difference(s) were repaired during the upgrade.'),
                count($repairs)
            ),
            'admin'
        );
    }

    /**
     * action=market_refresh -- forces a live catalog check (conditional GET; a 304 on
     * the common path) for the requested package kind and reports what the Browse /
     * Updates screens will now show. docs/MARKET.md §8.2.
     *
     * @param string $type 'plugin' or 'theme'
     *
     * @return array{ok:bool, message:string, checked_at:int, error:?string,
     *               counts:array{available:int, updates:int}}
     */
    private function marketRefresh($type)
    {
        $emptyCounts = array('available' => 0, 'updates' => 0);

        $type = self::marketType($type);
        if ($type === null) {
            return array(
                'ok' => false, 'message' => __('Invalid package type.'),
                'checked_at' => 0, 'error' => null, 'counts' => $emptyCounts,
            );
        }

        if (defined('DEMO')) {
            return array(
                'ok' => false, 'message' => __("This action can't be done because it's a demo site"),
                'checked_at' => 0, 'error' => null, 'counts' => $emptyCounts,
            );
        }
        if (osc_package_installs_disabled()) {
            return array(
                'ok' => false, 'message' => __('Catalog refresh is disabled on this deployment.'),
                'checked_at' => 0, 'error' => null, 'counts' => $emptyCounts,
            );
        }

        $catalog = self::marketCatalog($type);
        $catalog->index(true);
        $catalog->updates(true);

        $packageIndex = self::marketPackageIndex($type);
        $counts       = array(
            'available' => count($packageIndex->available()),
            'updates'   => count($packageIndex->pendingUpdates()),
        );

        $error = $catalog->lastError();

        return array(
            'ok'         => $error === null,
            'message'    => $error === null ? __('Catalog refreshed.') : $error,
            'checked_at' => $catalog->lastChecked(),
            'error'      => $error,
            'counts'     => $counts,
        );
    }

    /**
     * action=market_install / action=market_update -- resolves the version to install
     * server-side (Compatibility::pickBestVersion() for a fresh install,
     * PackageIndex::pendingUpdates() for an update) and refuses when the client-supplied
     * version no longer matches, instead of trusting a version string handed in by the
     * browser. docs/MARKET.md §8.2, §9.
     *
     * @param string $mode    'install' or 'update'
     * @param string $type    'plugin' or 'theme'
     * @param string $slug
     * @param string $version version the client last saw; re-validated, never trusted
     *
     * @return array{ok:bool, message:string, slug:string, version:?string, rolled_back:bool}
     */
    private function marketInstallOrUpdate($mode, $type, $slug, $version)
    {
        $slug = trim((string) $slug);

        $type = self::marketType($type);
        if ($type === null) {
            return array(
                'ok' => false, 'message' => __('Invalid package type.'),
                'slug' => $slug, 'version' => null, 'rolled_back' => false,
            );
        }

        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,40}$/', $slug)) {
            return array(
                'ok' => false, 'message' => __('Invalid package slug.'),
                'slug' => $slug, 'version' => null, 'rolled_back' => false,
            );
        }

        $version = trim((string) $version);
        if ($version === '') {
            return array(
                'ok' => false, 'message' => __('No version was specified.'),
                'slug' => $slug, 'version' => null, 'rolled_back' => false,
            );
        }

        if (defined('DEMO')) {
            return array(
                'ok' => false, 'message' => __("This action can't be done because it's a demo site"),
                'slug' => $slug, 'version' => null, 'rolled_back' => false,
            );
        }
        if (osc_package_installs_disabled()) {
            return array(
                'ok' => false, 'message' => __('Package installs are disabled on this deployment.'),
                'slug' => $slug, 'version' => null, 'rolled_back' => false,
            );
        }

        $packageIndex  = self::marketPackageIndex($type);
        $installedRows = $packageIndex->installed();
        $isInstalled   = isset($installedRows[$slug]);

        if ($mode === 'install') {
            if ($isInstalled) {
                return array(
                    'ok' => false, 'message' => __('This package is already installed; use Update instead.'),
                    'slug' => $slug, 'version' => null, 'rolled_back' => false,
                );
            }

            $catalog  = self::marketCatalog($type);
            $versions = $catalog->updates()[$slug] ?? array();
            $best     = Compatibility::pickBestVersion($versions);
        } else {
            if (!$isInstalled) {
                return array(
                    'ok' => false, 'message' => __('This package is not installed; use Install instead.'),
                    'slug' => $slug, 'version' => null, 'rolled_back' => false,
                );
            }

            $pending = $packageIndex->pendingUpdates();
            $best    = $pending[$slug] ?? null;
        }

        if ($best === null) {
            return array(
                'ok' => false, 'message' => __('No compatible version is available for this package.'),
                'slug' => $slug, 'version' => null, 'rolled_back' => false,
            );
        }

        // Re-resolve, never trust: the version the client saw may be stale by the time
        // this request lands (a catalog refresh, or another admin, in between).
        if ((string) $best['version'] !== $version) {
            return array(
                'ok'          => false,
                'message'     => __('The available version has changed since you loaded this page; refresh and try again.'),
                'slug'        => $slug,
                'version'     => null,
                'rolled_back' => false,
            );
        }

        $installer = self::marketInstaller($type);

        return $mode === 'install' ? $installer->install($slug, $best) : $installer->update($slug, $best);
    }

    /**
     * action=market_detail -- the payload for the Browse/Updates detail dialog
     * (docs/MARKET.md §8.2): screenshots, rendered README, per-version compatibility and
     * support links, sourced from `Catalog::detail()`'s own cache (a cheap conditional GET
     * on the common path, never a forced fetch). Read-only, so unlike install/update this
     * carries no DEMO or self-update-disabled refusal.
     *
     * @param string $type 'plugin' or 'theme'
     * @param string $slug
     *
     * @return array{ok:bool, message:string, detail:?array}
     */
    private function marketDetail($type, $slug)
    {
        $type = self::marketType($type);
        if ($type === null) {
            return array('ok' => false, 'message' => __('Invalid package type.'), 'detail' => null);
        }

        $slug = trim((string) $slug);
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,40}$/', $slug)) {
            return array('ok' => false, 'message' => __('Invalid package slug.'), 'detail' => null);
        }

        $raw = self::marketCatalog($type)->detail($slug);
        if ($raw === null) {
            return array(
                'ok' => false, 'message' => __('No details are available for this package yet.'),
                'detail' => null,
            );
        }

        return array('ok' => true, 'message' => '', 'detail' => self::marketBuildDetail($slug, $raw));
    }

    /**
     * Shapes `Catalog::detail()`'s cached payload into what the dialog renders. The catalog
     * type-checks its fields on the way in but does not sanitise `description_html` (it is a
     * third party's README) and does not re-check `screenshots[].src` / support links against
     * the host allowlist on every read -- both happen here, on the response path, rather than
     * trusting whatever is already sitting in the cache.
     *
     * @param string $slug
     * @param array  $raw  Catalog::detail()'s sanitised (but not description-purified) array
     *
     * @return array
     */
    private static function marketBuildDetail($slug, array $raw)
    {
        return array(
            'slug'             => $slug,
            'name'             => is_string($raw['name'] ?? null) ? $raw['name'] : $slug,
            'author'           => is_string($raw['author'] ?? null) ? $raw['author'] : '',
            'description_html' => self::marketPurifyDescription($raw['description_html'] ?? ''),
            'screenshots'      => self::marketSanitizeScreenshots($raw),
            'versions'         => self::marketSanitizeVersions($raw),
            'links'            => self::marketSanitizeLinks($raw),
            'categories'       => isset($raw['categories']) && is_array($raw['categories']) ? array_values($raw['categories']) : array(),
            'tags'             => isset($raw['tags']) && is_array($raw['tags']) ? array_values($raw['tags']) : array(),
            'downloads'        => is_int($raw['downloads'] ?? null) ? $raw['downloads'] : 0,
        );
    }

    /**
     * Every screenshot re-checked against the package host allowlist here, on the way out to
     * the browser -- not just trusted from `Catalog::sanitizeDetail()`'s own pass on the way
     * in, so a host that was allowed when this slug was cached and is not allowed today (or a
     * cache entry that predates a stricter policy) still can't reach the dialog's DOM.
     *
     * @param array $raw
     *
     * @return array<int, array{src:string, caption:string}>
     */
    private static function marketSanitizeScreenshots(array $raw)
    {
        $shots = array();
        foreach ((array) ($raw['screenshots'] ?? array()) as $shot) {
            if (!is_array($shot) || !is_string($shot['src'] ?? null) || $shot['src'] === '') {
                continue;
            }
            if (!FileSystem::isAllowedPackageHost($shot['src'])) {
                continue;
            }
            $shots[] = array(
                'src'     => $shot['src'],
                'caption' => is_string($shot['caption'] ?? null) ? $shot['caption'] : '',
            );
        }

        return $shots;
    }

    /**
     * The version table: one row per catalog release, each with its own
     * `Compatibility::evaluate()` verdict against THIS row's `requires`/`requires_php`/
     * `tested` -- evaluating once against the latest version and reusing it across every row
     * would show every version as equally (in)compatible, which defeats the point of the
     * table.
     *
     * `released_at` is always null: `Catalog::sanitizeVersionEntry()` (shared by
     * `updates.json` and this detail payload) does not carry the catalog's `published_at`
     * field through, so there is nothing to surface here without a `Catalog.php` change.
     *
     * @param array $raw
     *
     * @return array
     */
    private static function marketSanitizeVersions(array $raw)
    {
        $versions = array();
        foreach ((array) ($raw['versions'] ?? array()) as $entry) {
            if (!is_array($entry) || !isset($entry['version']) || $entry['version'] === '') {
                continue;
            }

            $compatInfo = array(
                'requires'     => is_string($entry['requires'] ?? null) ? $entry['requires'] : '',
                'requires_php' => is_string($entry['requires_php'] ?? null) ? $entry['requires_php'] : '',
                'tested_up_to' => is_string($entry['tested'] ?? null) ? $entry['tested'] : '',
            );

            $verdict = Compatibility::evaluate($compatInfo);

            $versions[] = array(
                'version'      => (string) $entry['version'],
                'requires'     => $compatInfo['requires'],
                'requires_php' => $compatInfo['requires_php'],
                'tested'       => $compatInfo['tested_up_to'],
                'size'         => (int) ($entry['size'] ?? 0),
                'downloads'    => is_int($entry['downloads'] ?? null) ? $entry['downloads'] : 0,
                'released_at'  => null,
                'compat'       => array(
                    'status'  => $verdict['status'],
                    'blocked' => $verdict['blocked'],
                    'reason'  => $verdict['reason'],
                    'badge'   => Compatibility::badgeLabel($compatInfo),
                ),
            );
        }

        return $versions;
    }

    /**
     * `homepage` / `repo` / `issues` / `docs`, each re-checked against the package host
     * allowlist (the catalog only URL-shape-checks support links, unlike screenshots, so this
     * is the first allowlist pass they get). `Catalog::detail()` does not carry the raw
     * payload's top-level `homepage` field through sanitisation -- only its `support` object
     * survives -- so when neither `homepage` nor `repo` is present in that object, a repo link
     * is derived from the issue tracker URL instead: a GitHub issue tracker always lives at
     * "<repo>/issues".
     *
     * @param array $raw
     *
     * @return array{homepage:?string, repo:?string, issues:?string, docs:?string}
     */
    private static function marketSanitizeLinks(array $raw)
    {
        $support = isset($raw['links']) && is_array($raw['links']) ? $raw['links'] : array();

        $pick = static function ($key) use ($support) {
            $value = $support[$key] ?? null;

            return (is_string($value) && $value !== '' && FileSystem::isAllowedPackageHost($value)) ? $value : null;
        };

        $issues   = $pick('issues');
        $docs     = $pick('docs');
        $homepage = $pick('homepage');
        $repo     = $pick('repo');

        if ($homepage === null && $repo === null && $issues !== null
            && preg_match('#^(https://github\.com/[^/]+/[^/]+)/issues/?$#i', $issues, $matches)
        ) {
            $homepage = $matches[1];
            $repo     = $matches[1];
        }

        return array('homepage' => $homepage, 'repo' => $repo, 'issues' => $issues, 'docs' => $docs);
    }

    /** @var \HTMLPurifier|null */
    private static $marketPurifier;

    /**
     * `description_html` is a third party's rendered README. The catalog builder sanitises it
     * before publishing, but that is an upstream promise, not a guarantee this codebase can
     * rely on for markup it is about to inject into its own admin DOM -- so it is purified
     * again here, independently, right before it goes into the JSON response.
     *
     * A tight allowlist only: headings, paragraphs, line breaks, lists, emphasis, inline code,
     * pre blocks, links and images. No scripts, no event handlers, no iframes, no style
     * attributes -- none of those are in the allowed list, so HTMLPurifier drops them
     * regardless of what the source markup contained. `URI.AllowedSchemes` is narrowed to
     * http/https only, so a `javascript:` or `data:` URL in an href or src cannot survive.
     * `target="_blank"` and `rel="nofollow noopener"` are forced onto every link.
     *
     * A valid http(s) URL is still not necessarily one this admin should silently *fetch* from
     * a third party's README: an `<img src>` fires on render, with no click and no consent, so
     * every `<img>` that survives purification is re-checked against the same package host
     * allowlist that governs `screenshots[].src` and the support links (marketDropUnallowedHostUrls()).
     *

     * @param mixed $html
     *
     * @return string
     */
    private static function marketPurifyDescription($html)
    {
        if (!is_string($html) || $html === '') {
            return '';
        }

        if (self::$marketPurifier === null) {
            $config = HTMLPurifier_Config::createDefault();
            $config->set(
                'HTML.Allowed',
                'h1,h2,h3,h4,h5,h6,p,br,blockquote,ul,ol,li,strong,b,em,i,code,pre,a[href],img[src|alt|loading],'
                . 'table,thead,tbody,tr,th[class],td[class]'
            );
            $config->set('HTML.TargetBlank', true);
            $config->set('HTML.TargetNoopener', true);
            $config->set('HTML.Nofollow', true);
            $config->set('URI.AllowedSchemes', array('http' => true, 'https' => true));
            // Only the two alignment classes renderMarkdownSafe() (tools/ci/build-catalog.php)
            // ever emits are let through -- everything else on a class attribute is stripped.
            $config->set('Attr.AllowedClasses', array('text-center' => true, 'text-end' => true));
            // Stripping down to a small tag set leaves nothing worth persisting a definition
            // cache for; the in-memory NullCache avoids writing serializer blobs to disk, same
            // as Params::purify()'s own HTMLPurifier instance.
            $config->set('Cache.DefinitionImpl', null);
            self::$marketPurifier = new HTMLPurifier($config);
        }

        return self::marketDropUnallowedHostUrls(self::$marketPurifier->purify($html));
    }

    /**
     * Second pass over already-purified README markup: drops any `<img>` whose `src` is not on
     * the package host allowlist. Unlike an `<a href>` -- a click-through the visitor chooses to
     * make, already carrying `nofollow noopener` and restricted to http(s) -- an `<img>` fires
     * on render with no click and no consent, so it gets the same allowlist `screenshots[].src`
     * and the support links get, rather than being trusted just because HTMLPurifier's tag/
     * attribute/scheme rules let it through. A malformed fragment DOMDocument can't parse is
     * returned as an empty string rather than passed through.
     *
     * @param string $html already-purified, well-formed (small allowlist) HTML fragment
     *
     * @return string
     */
    private static function marketDropUnallowedHostUrls($html)
    {
        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<?xml encoding="utf-8"?><div>' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();

        // loadHTML() wraps a bare fragment in its own <html><body> -- the wrapper <div> above
        // is what scopes this back down to just the fragment's own nodes.
        $root = $doc->getElementsByTagName('div')->item(0);
        if (!$loaded || $root === null) {
            return '';
        }

        $xpath = new DOMXPath($doc);

        foreach (iterator_to_array($xpath->query('.//img[@src]', $root)) as $img) {
            /** @var DOMElement $img */
            if (!FileSystem::isAllowedPackageHost($img->getAttribute('src'))) {
                $img->parentNode->removeChild($img);
            }
        }

        $result = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $result .= $doc->saveHTML($child);
        }

        return $result;
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
