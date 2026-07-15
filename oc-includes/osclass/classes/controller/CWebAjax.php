<?php

/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 * Copyright (c) 2021.  Mindstellar
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 *                     GNU GENERAL PUBLIC LICENSE
 *                        Version 3, 29 June 2007
 *
 *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
 *  Everyone is permitted to copy and distribute verbatim copies
 *  of this license document, but changing it is not allowed.
 *
 *  You should have received a copy of the GNU Affero General Public
 *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
 *
 */

define('IS_AJAX', true);

/**
 * Class CWebAjax
 */
class CWebAjax extends BaseModel
{
    public function __construct()
    {
        parent::__construct();
        $this->ajax = true;
        osc_run_hook('init_ajax');
    }

    /**
     * Business Layer...
     */
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
                foreach ($cities as $k => $city) {
                    $cities[$k]['label'] = $city['label'] . ' (' . $city['region'] . ')';
                }
                echo json_encode($cities);
                break;
            case 'location_countries': // This is the autocomplete AJAX
                $countries = Country::newInstance()->ajax(Params::getParam('term'));
                echo json_encode($countries);
                break;
            case 'location_regions': // This is the autocomplete AJAX
                $regions = Region::newInstance()
                    ->ajax(Params::getParam('term'), Params::getParam('country'));
                echo json_encode($regions);
                break;
            case 'location_cities': // This is the autocomplete AJAX
                $cities =
                    City::newInstance()->ajax(Params::getParam('term'), Params::getParam('region'));
                echo json_encode($cities);
                break;
            case 'delete_image': // Delete images via AJAX
                $ajax_photo = Params::getParam('ajax_photo');
                $id         = Params::getParam('id');
                $item       = Params::getParam('item');
                $code       = Params::getParam('code');
                $secret     = Params::getParam('secret');
                $json       = array();

                if ($ajax_photo != '') {
                    $files   = Session::newInstance()->_get('ajax_files');
                    $success = false;

                    foreach ($files as $uuid => $file) {
                        if ($file == $ajax_photo) {
                            $filename = $file;
                            unset($files[$uuid]);
                            Session::newInstance()->_set('ajax_files', $files);
                            $success = @unlink(osc_content_path() . 'uploads/temp/' . $filename);
                            break;
                        }
                    }

                    echo json_encode(array(
                        'success' => $success,
                        'msg'     => _m($success
                            ? 'The selected photo has been successfully deleted'
                            : "The selected photo couldn't be deleted")
                    ));

                    return false;
                }

                if (Session::newInstance()->_get('userId') != '') {
                    $userId = Session::newInstance()->_get('userId');
                    $user   = User::newInstance()->findByPrimaryKey($userId);
                } else {
                    $userId = null;
                    $user   = null;
                }

                // Check for required fields
                if (!(is_numeric($id) && is_numeric($item)
                    && preg_match('/^([a-z0-9]+)$/i', $code))
                ) {
                    $json['success'] = false;
                    $json['msg']     =
                        _m("The selected photo couldn't be deleted, the url doesn't exist");
                    echo json_encode($json);

                    return false;
                }


                $aItem = Item::newInstance()->findByPrimaryKey($item);

                // Check if the item exists
                if (count($aItem) == 0) {
                    $json['success'] = false;
                    $json['msg']     = _m("The listing doesn't exist");
                    echo json_encode($json);

                    return false;
                }

                if (!osc_is_admin_user_logged_in()) {
                    // Check if the item belong to the user
                    if ($userId != null && $userId != $aItem['fk_i_user_id']) {
                        $json['success'] = false;
                        $json['msg']     = _m("The listing doesn't belong to you");
                        echo json_encode($json);

                        return false;
                    }

                    // Check if the secret passphrase match with the item
                    if ($userId == null && $aItem['fk_i_user_id'] == null
                        && $secret != $aItem['s_secret']
                    ) {
                        $json['success'] = false;
                        $json['msg']     = _m("The listing doesn't belong to you");
                        echo json_encode($json);

                        return false;
                    }
                }

                // Does id & code combination exist?
                $result = ItemResource::newInstance()->existResource($id, $code);

                if ($result > 0) {
                    $resource = ItemResource::newInstance()->findByPrimaryKey($id);

                    if ($resource['fk_i_item_id'] == $item) {
                        // Delete: file, db table entry
                        if (defined('OC_ADMIN') && OC_ADMIN) {
                            osc_deleteResource($id, true);
                            Log::newInstance()->insertLog(
                                'ajax',
                                'deleteimage',
                                $id,
                                $id,
                                'admin',
                                osc_logged_admin_id()
                            );
                        } else {
                            osc_deleteResource($id, false);
                            Log::newInstance()->insertLog(
                                'ajax',
                                'deleteimage',
                                $id,
                                $id,
                                'user',
                                osc_logged_user_id()
                            );
                        }
                        ItemResource::newInstance()->delete(array(
                            'pk_i_id'      => $id,
                            'fk_i_item_id' => $item,
                            's_name'       => $code
                        ));

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

                echo json_encode($json);

                return true;
                break;
            case 'alerts': // Allow to register to an alert given (not sure it's used on admin)
                // Optionally require a logged-in user before creating a subscription, to stop
                // anonymous email harvesting / confirmation-email abuse through this endpoint.
                if (osc_get_preference('alerts_require_login') && !osc_is_web_user_logged_in()) {
                    echo '-4';

                    return false;
                }
                $encoded_alert = Params::getParam('alert');
                $alert         = osc_decrypt_alert(base64_decode($encoded_alert));

                // Integrity check: a genuine alert is encrypted with the persistent per-install
                // key, so a valid token decrypts to a JSON search array. A forged or tampered
                // token decrypts to garbage and is rejected here — no session state needed.
                if ($alert === '' || !is_array(json_decode($alert, true))) {
                    echo '-2';

                    return false;
                }

                $email  = Params::getParam('email');
                $userid = Params::getParam('userid');

                if (osc_is_web_user_logged_in()) {
                    $userid = osc_logged_user_id();
                    $user   = User::newInstance()->findByPrimaryKey($userid);
                    $email  = $user['s_email'];
                }

                if ($alert != '' && $email != '') {
                    if (osc_validate_email($email)) {
                        $secret = osc_genRandomPassword();

                        if ($alertID =
                            Alerts::newInstance()->createAlert($userid, $email, $alert, $secret)
                        ) {
                            if ((int)$userid > 0) {
                                $user = User::newInstance()->findByPrimaryKey($userid);
                                if ($user['b_active'] == 1 && $user['b_enabled'] == 1) {
                                    Alerts::newInstance()->activate($alertID);
                                    echo '1';

                                    return true;
                                }

                                echo '-1';

                                return false;
                            }

                            $aAlert = Alerts::newInstance()->findByPrimaryKey($alertID);
                            osc_run_hook(
                                'hook_email_alert_validation',
                                $aAlert,
                                $email,
                                $secret
                            );

                            echo '1';
                        } else {
                            echo '0';
                        }

                        return true;
                    }

                    echo '-1';

                    return false;
                }
                echo '0';

                return false;
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
                        osc_run_hook('ajax_' . $hook);
                        break;
                }
                break;
            case 'custom': // Execute via AJAX custom file
                if (Params::existParam('route')) {
                    $routes = Rewrite::newInstance()->getRoutes();
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
                    echo json_encode(array('error' => 'no action defined'));
                    break;
                }

                // valid file?
                if (strpos($file, '../') !== false || strpos($file, '..\\') !== false
                    || stripos($file, '/admin/') !== false
                ) { //If the file is inside an "admin" folder, it should NOT be opened in frontend
                    echo json_encode(array('error' => 'no valid ajaxFile'));
                    break;
                }

                if (!file_exists(osc_plugins_path() . $file)) {
                    echo json_encode(array('error' => "ajaxFile doesn't exist"));
                    break;
                }

                require_once osc_plugins_path() . $file;
                break;
            case 'check_username_availability':
                $username = osc_sanitize_username(Params::getParam('s_username'));
                if (osc_is_username_blacklisted($username)) {
                    echo json_encode(array('exists' => 1, 's_username' => $username));
                } else {
                    $user = User::newInstance()->findByUsername($username);
                    if (isset($user['s_username'])) {
                        echo json_encode(array('exists' => 1, 's_username' => $username));
                    } else {
                        echo json_encode(array('exists' => 0, 's_username' => $username));
                    }
                }
                break;
            case 'ajax_upload':
                // Include the uploader class
                $uploader = new AjaxUploader();
                $original = pathinfo($uploader->getOriginalName());
                $filename = uniqid('qqfile_', true) . '.' . $original['extension'];
                try {
                    $result =
                        $uploader->handleUpload(osc_content_path() . 'uploads/temp/' . $filename);
                } catch (Exception $e) {
                    trigger_error($e->getMessage(), E_USER_WARNING);
                    echo json_encode(array('success' => false));
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
                    echo json_encode(array('success' => false));
                    break;
                }
                try {
                    $img->saveToFile(
                        osc_content_path() . 'uploads/temp/' . $filename,
                        $original['extension']
                    );
                } catch (Exception $e) {
                    trigger_error($e->getMessage(), E_USER_NOTICE);
                    echo json_encode(array('success' => false));
                    break;
                }

                $result['uploadName'] = 'auto_' . $filename;
                echo htmlspecialchars(json_encode($result), ENT_NOQUOTES);
                break;
            case 'ajax_validate':
                $id = Params::getParam('id');
                if (!is_numeric($id)) {
                    echo json_encode(array('success' => false));
                    die();
                }
                $secret = Params::getParam('secret');

                $item = Item::newInstance()->findByPrimaryKey($id);

                if ($item['s_secret'] != $secret) {
                    echo json_encode(array('success' => false));
                    die();
                }
                $nResources = ItemResource::newInstance()->countResources($id);
                $result     = array(
                    'success' => $nResources < osc_max_images_per_item(),
                    'count'   => $nResources
                );
                echo json_encode($result);
                break;
            case 'delete_ajax_upload':
                $files    = Session::newInstance()->_get('ajax_files');
                $success  = false;
                $filename = '';
                if (isset($files[Params::getParam('qquuid')])
                    && $files[Params::getParam('qquuid')] != ''
                ) {
                    $filename = $files[Params::getParam('qquuid')];
                    unset($files[Params::getParam('qquuid')]);
                    Session::newInstance()->_set('ajax_files', $files);
                    $success = @unlink(osc_content_path() . 'uploads/temp/' . $filename);
                }
                echo json_encode(array('success' => $success, 'uploadName' => $filename));
                break;
            default:
                echo json_encode(array('error' => __('no action defined')));
                break;
        }
    }

    //hopefully generic...

    /**
     * @param $file
     *
     * @return void
     */
    public function doView($file)
    {
        osc_run_hook('before_html');
        osc_current_web_theme_path($file);
        osc_run_hook('after_html');
    }
}

/* file end: ./CWebAjax.php */
