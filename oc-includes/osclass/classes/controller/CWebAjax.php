<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

define('IS_AJAX', true);

/**
 * Class CWebAjax
 */
use mindstellar\auth\Actor;
use mindstellar\listing\ListingPolicy;
use mindstellar\listing\PhotoService;
use mindstellar\utility\AjaxResponse;

class CWebAjax extends BaseModel
{
    /**
     * Boots the base controller, flags the request as AJAX and fires the `init_ajax` hook.
     */
    public function __construct()
    {
        parent::__construct();
        $this->ajax = true;
        osc_run_hook('init_ajax');
    }

    /**
     * Business Layer...
     *
     * Dispatches the AJAX action and echoes its JSON response; an unknown action answers
     * with a JSON error.
     *
     * @return void
     */
    public function doModel()
    {
        //specific things for this class
        switch ($this->action) {
            case 'bulk_actions':
                break;
            case 'regions': //Return regions given a countryId
                $regions = Region::getInstance()->findByCountry(Params::getParam('countryId'));
                AjaxResponse::json($regions);
                break;
            case 'cities': //Returns cities given a regionId
                $cities = City::getInstance()->findByRegion(Params::getParamInt('regionId'));
                AjaxResponse::json($cities);
                break;
            case 'location': // This is the autocomplete AJAX
                $cities = City::getInstance()->ajax(Params::getParam('term'));
                foreach ($cities as $k => $city) {
                    $cities[$k]['label'] = $city['label'] . ' (' . $city['region'] . ')';
                }
                AjaxResponse::json($cities);
                break;
            case 'location_countries': // This is the autocomplete AJAX
                $countries = Country::getInstance()->ajax(Params::getParam('term'));
                AjaxResponse::json($countries);
                break;
            case 'custom_field_autocomplete': // Suggestions for an AUTOCOMPLETE custom field
                AjaxResponse::json($this->customFieldAutocomplete(
                    (int) Params::getParam('field'),
                    (string) Params::getParam('term')
                ));
                break;
            case 'location_regions': // This is the autocomplete AJAX
                $regions = Region::getInstance()
                    ->ajax(Params::getParam('term'), Params::getParam('country'));
                AjaxResponse::json($regions);
                break;
            case 'location_cities': // This is the autocomplete AJAX
                $cities =
                // @phpstan-ignore argument.type (region may be an id or a name)
                    City::getInstance()->ajax(Params::getParam('term'), Params::getParam('region'));
                AjaxResponse::json($cities);
                break;
            case 'delete_image': // Delete images via AJAX
                $ajax_photo = Params::getParam('ajax_photo');
                $id         = Params::getParam('id');
                $item       = Params::getParam('item');
                $code       = Params::getParamString('code');
                $secret     = Params::getParamString('secret');
                $json       = array();

                if ($ajax_photo != '') {
                    $success = false;

                    // deleteByTokenFile is the authorisation: a positive count means this
                    // browser's upload token really staged that file, so it may be removed.
                    // Anything else (a forged or foreign filename) matches no row and is left
                    // untouched, which also keeps the unlink below to real staged basenames.
                    if (ItemTmpUpload::getInstance()->deleteByTokenFile(osc_upload_token(), $ajax_photo) > 0) {
                        $success = @unlink(osc_content_path() . 'uploads/temp/' . $ajax_photo);
                    }

                    AjaxResponse::json(array(
                        'success' => $success,
                        'msg'     => _m($success
                            ? 'The selected photo has been successfully deleted'
                            : "The selected photo couldn't be deleted")
                    ));

                    return;
                }

                $userId = osc_is_web_user_logged_in() ? osc_logged_user_id() : null;

                // Check for required fields
                if (!(is_numeric($id) && is_numeric($item)
                    && preg_match('/^([a-z0-9]+)$/i', $code))
                ) {
                    $json['success'] = false;
                    $json['msg']     =
                        _m("The selected photo couldn't be deleted, the url doesn't exist");
                    AjaxResponse::json($json);

                    return;
                }

                $aItem = Item::getInstance()->findByPrimaryKey((int) $item);

                // Check if the item exists
                if (count($aItem) == 0) {
                    $json['success'] = false;
                    $json['msg']     = _m("The listing doesn't exist");
                    AjaxResponse::json($json);

                    return;
                }

                $actor = new Actor(
                    (int) $userId,
                    osc_is_admin_user_logged_in() ? (int) osc_logged_admin_id() : null,
                    (string) Params::getServerParam('REMOTE_ADDR'),
                    $secret
                );
                if (!ListingPolicy::canManage($aItem, $actor)) {
                    $json['success'] = false;
                    $json['msg']     = _m("The listing doesn't belong to you");
                    AjaxResponse::json($json);

                    return;
                }

                // Does id & code combination exist?
                $result = ItemResource::getInstance()->existResource((int) $id, $code);

                if ($result > 0) {
                    $resource = ItemResource::getInstance()->findByPrimaryKey($id);

                    if (ListingPolicy::isPhotoOf($resource, $aItem, $code)
                        && (new PhotoService())->delete((int) $id, (int) $item, $actor, $code)
                    ) {
                        $json['msg']     = _m('The selected photo has been successfully deleted');
                        $json['success'] = 'true';
                    } else {
                        $json['msg']     = _m('The selected photo does not belong to you');
                        $json['success'] = 'false';
                    }
                } else {
                    $json['msg']     = _m("The selected photo couldn't be deleted");
                    $json['success'] = 'false';
                }

                AjaxResponse::json($json);

                return;
            case 'alerts': // Allow to register to an alert given (not sure it's used on admin)
                echo (string)osc_subscribe_alert(Params::getParamString('alert'), Params::getParamString('email'));

                return;
            case 'runhook': // run hooks
                $hook = Params::getParam('hook');

                if ($hook == '') {
                    AjaxResponse::json(array('error' => 'hook parameter not defined'));
                    break;
                }

                switch ($hook) {
                    case 'item_form':
                        osc_run_hook('item_form', Params::getParam('catId'));
                        break;
                    case 'item_edit':
                        $catId  = Params::getParam('catId');
                        $itemId = Params::getParamInt('itemId');
                        // Stored values go only to someone who may edit the listing.
                        if ($itemId > 0 && ListingPolicy::manageable($itemId, new Actor(
                            osc_is_web_user_logged_in() ? (int) osc_logged_user_id() : null,
                            osc_is_admin_user_logged_in() ? (int) osc_logged_admin_id() : null,
                            (string) Params::getServerParam('REMOTE_ADDR'),
                            Params::getParamString('secret')
                        )) === null) {
                            $itemId = 0;
                        }
                        osc_run_hook('item_edit', $catId, $itemId);
                        break;
                    default:
                        osc_run_hook('ajax_' . $hook);
                        break;
                }
                break;
            case 'custom': // Execute via AJAX custom file
                if (Params::existParam('route')) {
                    $routes = Rewrite::getInstance()->getRoutes();
                    $rid    = Params::getParam('route');
                    $file   = '../';
                    if (isset($routes[$rid]['file'])) {
                        $file = $routes[$rid]['file'];
                    }
                } else {
                    // DEPRECATED: Disclosed path in URL is deprecated, use routes instead
                    // This will be REMOVED in 3.4
                    $file = Params::getParam('ajaxfile');
                }

                if ($file == '') {
                    AjaxResponse::json(array('error' => 'no action defined'));
                    break;
                }

                // valid file?
                if (strpos($file, '../') !== false || strpos($file, '..\\') !== false
                    || stripos($file, '/admin/') !== false
                ) { //If the file is inside an "admin" folder, it should NOT be opened in frontend
                    AjaxResponse::json(array('error' => 'no valid ajaxFile'));
                    break;
                }

                if (!file_exists(osc_plugins_path() . $file)) {
                    AjaxResponse::json(array('error' => "ajaxFile doesn't exist"));
                    break;
                }

                // Unauthenticated, and it ends in require_once: resolve the path before
                // running it -- .php only, and inside the plugins directory once symlinks
                // are followed.
                $resolved = \mindstellar\security\PluginAjaxFile::resolve($file, osc_plugins_path());
                if ($resolved === null) {
                    AjaxResponse::json(array('error' => 'no valid ajaxFile'));
                    break;
                }

                require_once $resolved;
                break;
            case 'check_username_availability':
                $username = (new \mindstellar\utility\Sanitize())->username(Params::getParam('s_username'));
                if (osc_is_username_blacklisted($username)) {
                    AjaxResponse::json(array('exists' => 1, 's_username' => $username));
                } else {
                    $user = User::getInstance()->findByUsername($username);
                    if (isset($user['s_username'])) {
                        AjaxResponse::json(array('exists' => 1, 's_username' => $username));
                    } else {
                        AjaxResponse::json(array('exists' => 0, 's_username' => $username));
                    }
                }
                break;
            case 'ajax_upload':
                $refused = $this->uploadRefusal();
                if ($refused !== '') {
                    AjaxResponse::json(array('success' => false, 'error' => $refused));
                    break;
                }
                $uploader = new AjaxUploader();
                $original = pathinfo($uploader->getOriginalName());
                $original['extension'] = $original['extension'] ?? '';
                $filename = uniqid('qqfile_', true) . '.' . $original['extension'];
                try {
                    $result =
                        $uploader->handleUpload(osc_content_path() . 'uploads/temp/' . $filename);
                } catch (Exception $e) {
                    trigger_error($e->getMessage(), E_USER_WARNING);
                    AjaxResponse::json(array('success' => false));
                    break;
                }

                // auto rotate

                $img = ImageProcessing::fromFile(osc_content_path() . 'uploads/temp/' . $filename);
                $img->autoRotate();
                try {
                    $img->saveToFile(
                        osc_content_path() . 'uploads/temp/auto_' . $filename,
                        $original['extension']
                    );
                } catch (Exception $e) {
                    trigger_error($e->getMessage(), E_USER_NOTICE);
                    AjaxResponse::json(array('success' => false));
                    break;
                }
                try {
                    $img->saveToFile(
                        osc_content_path() . 'uploads/temp/' . $filename,
                        $original['extension']
                    );
                } catch (Exception $e) {
                    trigger_error($e->getMessage(), E_USER_NOTICE);
                    AjaxResponse::json(array('success' => false));
                    break;
                }

                $result['uploadName'] = 'auto_' . $filename;
                // Stage the file against the form's upload token (a cookie, not the session).
                // Record the name the client attaches and deletes by (uploadName), so the
                // "remove photo" action authorises against — and unlinks — the right file.
                ItemTmpUpload::getInstance()->add(
                    osc_upload_token(),
                    Params::getParam('qquuid'),
                    $result['uploadName']
                );
                if (!osc_is_web_user_logged_in() && !osc_is_admin_user_logged_in()) {
                    \mindstellar\security\ActionThrottle::record('ajax_upload');
                }
                echo htmlspecialchars(json_encode($result), ENT_NOQUOTES);
                break;
            default:
                AjaxResponse::json(array('error' => __('no action defined')));
                break;
        }
    }

    /**
     * Why this visitor may not stage another photo, or '' when they may. Only guests have an hourly limit.
     *
     * @return string
     */
    private function uploadRefusal(): string
    {
        if (osc_is_admin_user_logged_in() || osc_is_web_user_logged_in()) {
            return '';
        }
        if (ListingPolicy::requiresSignIn(Actor::guest((string) Params::getServerParam('REMOTE_ADDR')))) {
            return _m('Only registered users are allowed to post listings');
        }
        if (\mindstellar\security\ActionThrottle::exceededFor('ajax_upload')) {
            return _m('Too many tries from your connection. Please try again later.');
        }

        return '';
    }

    //hopefully generic...

    /**
     * Renders the given theme template between the `before_html` and `after_html` hooks.
     *
     * @param string $file Absolute path to the located template
     *
     * @return void
     */
    public function doView($file)
    {
        osc_run_hook('before_html');
        osc_current_web_theme_path($file);
        osc_run_hook('after_html');
    }

    /**
     * Suggestions for an AUTOCOMPLETE custom field: distinct existing values of the
     * field on live listings, prefix-matched against $term. Only SEARCHABLE fields
     * expose their values (a non-searchable field's data is not meant to be
     * enumerable), and the query binds every value, so it is injection-safe. Plugins
     * can replace/augment the list via the `custom_field_autocomplete_source` filter.
     *
     * @param int    $fieldId t_meta_fields.pk_i_id
     * @param string $term    the typed prefix
     *
     * @return array<int, array{value:string, label:string}>
     */
    private function customFieldAutocomplete($fieldId, $term)
    {
        $term = trim($term);
        if ($fieldId <= 0 || $term === '') {
            return array();
        }

        $field = Field::getInstance()->findByPrimaryKey($fieldId);
        if (!is_array($field) || (int) ($field['b_searchable'] ?? 0) !== 1) {
            return array();
        }

        // Escape LIKE wildcards in the user term so they match literally.
        $like = str_replace(array('\\', '%', '_'), array('\\\\', '\\%', '\\_'), $term) . '%';
        $rows = \mindstellar\fields\FieldQuery::suggest((int) $fieldId, $like);

        $results = array();
        foreach ($rows as $r) {
            if (isset($r['value']) && $r['value'] !== '') {
                $results[] = array('value' => (string) $r['value'], 'label' => (string) $r['value']);
            }
        }

        return osc_apply_filter('custom_field_autocomplete_source', $results, $fieldId, $term, $field);
    }
}

/* file end: ./CWebAjax.php */
