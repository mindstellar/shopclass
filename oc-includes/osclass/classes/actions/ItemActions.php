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

use mindstellar\utility\Sanitize;

/**
 * Class ItemActions
 */
class ItemActions
{
    public $is_admin;
    public $data;
    private $manager;
    private $Sanitize;

    /**
     * ItemActions constructor.
     *
     * @param bool $is_admin
     */
    public function __construct($is_admin = false)
    {
        $this->is_admin = $is_admin;
        $this->manager  = Item::newInstance();
        $this->Sanitize = (new Sanitize());
    }

    /**
     * Delete resources from the hard drive
     *
     * @param int  $itemId
     * @param bool $is_admin
     */
    public static function deleteResourcesFromHD($itemId, $is_admin = false)
    {
        $resources = ItemResource::newInstance()->getAllResourcesFromItem($itemId);
        Log::newInstance()
            ->insertLog(
                'itemActions',
                'deleteResourcesFromHD',
                $itemId,
                $itemId,
                $is_admin ? 'admin' : 'user',
                $is_admin ? osc_logged_admin_id() : osc_logged_user_id()
            );
        $log_ids = '';
        foreach ($resources as $resource) {
            osc_deleteResource($resource['pk_i_id'], $is_admin);
            $log_ids .= $resource['pk_i_id'] . ',';
        }
        Log::newInstance()->insertLog(
            'itemActions',
            'deleteResourcesFromHD',
            $itemId,
            substr($log_ids, 0, 250),
            $is_admin ? 'admin' : 'user',
            $is_admin ? osc_logged_admin_id() : osc_logged_user_id()
        );
    }

    /**
     * Regenerate the normal/preview/thumbnail variants of a single resource
     * from its best available local source (preferring the original, then
     * the current normal, then the preview). No-op when none of those exist
     * locally or the resource isn't an image.
     *
     * Used by the "Regenerate images" admin action, run inline for every
     * resource on local installs and via the storage queue 'regenerate' job,
     * one resource at a time, on installs backed by a remote adapter.
     *
     * @param array $resource
     *
     * @return void
     */
    public static function regenerateResourceImages(array $resource): void
    {
        osc_run_hook('regenerate_image', $resource);
        if (strpos($resource['s_content_type'], 'image') === false) {
            return;
        }

        if (file_exists(osc_base_path() . $resource['s_path'] . $resource['pk_i_id'] . '_original.'
            . $resource['s_extension'])
        ) {
            $image_tmp    = osc_base_path() . $resource['s_path'] . $resource['pk_i_id'] . '_original.'
                . $resource['s_extension'];
            $use_original = true;
        } elseif (file_exists(osc_base_path() . $resource['s_path'] . $resource['pk_i_id'] . '.'
            . $resource['s_extension'])
        ) {
            $image_tmp    = osc_base_path() . $resource['s_path'] . $resource['pk_i_id'] . '.'
                . $resource['s_extension'];
            $use_original = false;
        } elseif (file_exists(osc_base_path() . $resource['s_path'] . $resource['pk_i_id'] . '_preview.'
            . $resource['s_extension'])
        ) {
            $image_tmp    = osc_base_path() . $resource['s_path'] . $resource['pk_i_id'] . '_preview.'
                . $resource['s_extension'];
            $use_original = false;
        } else {
            return;
        }

        // Create normal size
        $path        = osc_base_path() . $resource['s_path'] . $resource['pk_i_id'] . '.'
            . $resource['s_extension'];
        $path_normal = $path;
        $size        = explode('x', osc_normal_dimensions());
        $img         = ImageProcessing::fromFile($image_tmp)->resizeTo($size[0], $size[1]);
        if ($use_original) {
            if (osc_is_watermark_text()) {
                $img->doWatermarkText(osc_watermark_text(), osc_watermark_text_color());
            } elseif (osc_is_watermark_image()) {
                $img->doWatermarkImage();
            }
        }
        $img->saveToFile($path);

        // Create preview
        $path = osc_base_path() . $resource['s_path'] . $resource['pk_i_id'] . '_preview.'
            . $resource['s_extension'];
        $size = explode('x', osc_preview_dimensions());
        ImageProcessing::fromFile($path_normal)->resizeTo($size[0], $size[1])->saveToFile($path);

        // Create thumbnail
        $path = osc_base_path() . $resource['s_path'] . $resource['pk_i_id'] . '_thumbnail.'
            . $resource['s_extension'];
        $size = explode('x', osc_thumbnail_dimensions());
        ImageProcessing::fromFile($path_normal)->resizeTo($size[0], $size[1])->saveToFile($path);

        osc_run_hook(
            'regenerated_image',
            ItemResource::newInstance()->findByPrimaryKey($resource['pk_i_id'])
        );
    }

    /**
     * @return boolean
     */
    public function add()
    {
        $aItem       = $this->data;
        $aItem       = osc_apply_filter('item_add_prepare_data', $aItem);
        $is_spam     = 0;
        $enabled     = 1;
        $code        = osc_genRandomPassword();
        $flash_error = '';

        // Requires email validation?
        $has_to_validate = osc_moderate_items() !== -1;

        // Check status
        $active = $aItem['active'];

        // Sanitize
        foreach ($aItem['title'] as $key => $value) {
            $aItem['title'][$key] = $this->Sanitize->title($value);
        }

        if ($aItem['price'] !== null) {
            $aItem['price'] = $this->Sanitize->price($aItem['price']);
        }

        $aItem['contactName']       = trim($this->Sanitize->string($aItem['contactName']));
        $aItem['contactEmail']      = $this->Sanitize->email($aItem['contactEmail']);
        $aItem['contactPhone']      = $this->Sanitize->phone($aItem['contactPhone']);
        $aItem['cityArea'] = $aItem['cityArea']?osc_sanitize_name(strip_tags(trim($aItem['cityArea']))) : '';
        $aItem['address']  = $aItem['address']?osc_sanitize_name(strip_tags(trim($aItem['address']))) : '';

        // Anonymous
        $aItem['contactName'] = osc_validate_text($aItem['contactName'], 3) ? $aItem['contactName'] : __('Anonymous');

        // Validate
        $flash_error .= ((!osc_validate_max($aItem['contactName'], 35)) ? _m('Name too long.') . PHP_EOL : '');
        $flash_error .= ((!osc_validate_email($aItem['contactEmail'])) ? _m('Email invalid.') . PHP_EOL : '');

        $flash_error .= $this->validateCommonInput($flash_error, $aItem);

        $flash_error .= ((((time() - (int)Session::newInstance()->_get('last_submit_item')) < osc_items_wait_time())
            && !$this->is_admin)
            ? _m('Too fast. You should wait a little to publish your ad.')
            . PHP_EOL : '');

        // akismet check spam ...
        if ($this->akismetText($aItem['title'], $aItem['description'], $aItem['contactName'], $aItem['contactEmail'])) {
            $is_spam = 1;
        }
        $_meta = Field::newInstance()->findByCategory($aItem['catId']);
        $meta  = Params::getParam('meta');
        $this->handleMetaField($_meta, $meta, $flash_error);

        // hook pre add
        osc_run_hook('pre_item_add', $aItem, $flash_error);
        $flash_error = osc_apply_filter('pre_item_add_error', $flash_error, $aItem);

        // Handle error
        if ($flash_error) {
            $success = $flash_error;
        } else {
            if (empty($aItem['price'])) {
                $aItem['currency'] = null;
            }

            $this->manager->insert(array(
                'fk_i_user_id'       => $aItem['userId'],
                'dt_pub_date'        => date('Y-m-d H:i:s'),
                'fk_i_category_id'   => $aItem['catId'],
                'i_price'            => $aItem['price'],
                'fk_c_currency_code' => $aItem['currency'],
                's_contact_name'     => $aItem['contactName'],
                's_contact_email'    => $aItem['contactEmail'],
                's_contact_phone'    => $aItem['contactPhone'],
                's_secret'           => $code,
                'b_active'           => $active === 'ACTIVE' ? 1 : 0,
                'b_enabled'          => $enabled,
                'b_show_email'       => $aItem['showEmail'],
                'b_spam'             => $is_spam,
                's_ip'               => $aItem['s_ip']
            ));

            if (!$this->is_admin) {
                // Track spam delay: Session
                Session::newInstance()->_set('last_submit_item', time());
                // Track spam delay: Cookie
                Cookie::newInstance()->set_expires(osc_time_cookie());
                Cookie::newInstance()->push('last_submit_item', time());
                Cookie::newInstance()->set();
            }

            $itemId = $this->manager->dao->insertedId();
            Log::newInstance()->insertLog(
                'item',
                'add',
                $itemId,
                current(array_values($aItem['title'])),
                $this->is_admin ? 'admin' : 'user',
                $this->is_admin ? osc_logged_admin_id() : osc_logged_user_id()
            );

            Params::setParam('itemId', $itemId);

            // INSERT title and description locales
            $this->insertItemLocales('ADD', $aItem['title'], $aItem['description'], $itemId);

            $location = array(
                'fk_i_item_id'      => $itemId,
                'fk_c_country_code' => $aItem['countryId'],
                's_country'         => $aItem['countryName'],
                'fk_i_region_id'    => $aItem['regionId'],
                's_region'          => $aItem['regionName'],
                'fk_i_city_id'      => $aItem['cityId'],
                's_city'            => $aItem['cityName'],
                's_city_area'       => $aItem['cityArea'],
                's_address'         => $aItem['address'],
                'd_coord_lat'       => $aItem['d_coord_lat'],
                'd_coord_long'      => $aItem['d_coord_long'],
                's_zip'             => $aItem['s_zip']
            );
            $location = array_merge($location, $this->getItemCoordinates($location));

            $locationManager = ItemLocation::newInstance();
            $locationManager->insert($location);

            $this->uploadItemResources($aItem['photos'], $itemId);

            // update dt_expiration at t_item
            Item::newInstance()->updateExpirationDate($itemId, $aItem['dt_expiration']);

            /**
             * META FIELDS
             */
            if ($meta && count($meta) > 0) {
                $mField = Field::newInstance();
                foreach ($meta as $k => $v) {
                    // if dateinterval
                    if (is_array($v) && !isset($v['from']) && !isset($v['to'])) {
                        $v = implode(',', $v);
                    }
                    $mField->replace($itemId, $k, $v);
                }
            }

            // We need at least one record in t_item_stats
            $mStats = new ItemStats();
            $mStats->emptyRow($itemId);

            $item          = $this->manager->findByPrimaryKey($itemId);
            $aItem['item'] = $item;


            Session::newInstance()->_set('last_publish_time', time());
            if (!$this->is_admin) {
                $this->sendEmails($aItem);
            }

            if ($active === 'INACTIVE') {
                $success = 1;
            } else {
                $aAux = array(
                    'fk_i_user_id'      => $aItem['userId'],
                    'fk_i_category_id'  => $aItem['catId'],
                    'fk_c_country_code' => $location['fk_c_country_code'],
                    'fk_i_region_id'    => $location['fk_i_region_id'],
                    'fk_i_city_id'      => $location['fk_i_city_id']
                );
                // if is_spam not increase stats
                if ($is_spam == 0) {
                    $this->increaseStats($aAux);
                }
                $success = 2;
            }

            if (!$this->is_admin && osc_moderate_admin_post()) {
                $this->disable($item['pk_i_id']);
            }

            // THIS HOOK IS FINE, YAY!
            osc_run_hook('posted_item', $item);
        }

        return $success;
    }

    /**
     * @param $aResources
     *
     * @return bool
     */
    private function checkAllowedExt($aResources)
    {
        $success = true;
        require LIB_PATH . 'osclass/mimes.php';
        if (!empty($aResources)) {
            // get allowedExt
            $aMimesAllowed = array();
            $aExt          = explode(',', osc_allowed_extension());
            foreach ($aExt as $ext) {
                if (isset($mimes[$ext])) {
                    /** @var array $mimes */
                    $mime = $mimes[$ext];
                    if (is_array($mime)) {
                        foreach ($mime as $aux) {
                            if (!in_array($aux, $aMimesAllowed, false)) {
                                $aMimesAllowed[] = $aux;
                            }
                        }
                    } elseif (!in_array($mime, $aMimesAllowed, false)) {
                        $aMimesAllowed[] = $mime;
                    }
                }
            }
            foreach ($aResources['error'] as $key => $error) {
                $bool_img = false;
                if ($error == UPLOAD_ERR_OK) {
                    // check mime file
                    $fileMime = $aResources['type'][$key];
                    if (function_exists('getimagesize') && (stripos($fileMime, 'image/') !== false)) {
                        // check if it is a file
                        $filePath = $aResources['tmp_name'][$key];
                        $fileMime = '';
                        if (file_exists($filePath)) {
                            $imageInfo = getimagesize($filePath);
                            if (isset($imageInfo['mime'])) {
                                $fileMime = $imageInfo['mime'];
                                // check if it's in the allowed mimes
                                if (in_array($fileMime, $aMimesAllowed, false)) {
                                    $bool_img = true;
                                }
                            }
                        }
                    }
                    if (!$bool_img && $success) {
                        $success = false;
                    }
                }
            }

            if (!$success) {
                osc_add_flash_error_message(_m('The file you tried to upload does not have a valid extension'));
            }
        }

        return $success;
    }

    /**
     * @param $aResources
     *
     * @return bool
     */
    private function checkSize($aResources)
    {
        $success = true;

        if (!empty($aResources)) {
            // get allowedExt
            $maxSize = osc_max_size_kb() * 1024;
            foreach ($aResources['error'] as $key => $error) {
                if ($error == UPLOAD_ERR_OK) {
                    $size = $aResources['size'][$key];
                    if ($size >= $maxSize) {
                        $success = false;
                    }
                }
            }
            if (!$success) {
                osc_add_flash_error_message(_m('One of the files you tried to upload exceeds the maximum size'));
            }
        }

        return $success;
    }


    /**
     * @param array  $title
     * @param array  $description
     * @param string $author
     * @param string $email
     *
     * @return bool
     *
     */
    private function akismetText($title, $description, $author, $email)
    {
        $spam = false;
        if (osc_akismet_key()) {
            foreach ($title as $k => $_data) {
                $_title       = $_data;
                $_description = $description[$k];
                $content      = $_title . ' ' . $_description;

                $akismet = new Akismet(osc_base_url(), osc_akismet_key());

                $akismet->setCommentContent($content);
                $akismet->setCommentAuthor($author);
                $akismet->setCommentAuthorEmail($email);
                $akismet->setUserIP(get_ip());

                $status = '';
                try {
                    if ($akismet->isCommentSpam()) {
                        $status = 'SPAM';
                    }
                } catch (exception $e) {
                    trigger_error($e->getMessage(), E_USER_NOTICE);
                }
                if ($status === 'SPAM') {
                    $spam = true;
                    break;
                }
            }
        }

        return $spam;
    }

    /**
     * Validate common inputs while editing/publishing
     *
     * @param string $flash_error
     * @param        $aItem
     *
     * @return string
     */
    private function validateCommonInput(string $flash_error, $aItem)
    {
        if (!$this->checkAllowedExt($aItem['photos'])) {
            $flash_error .= _m('Image with an incorrect extension.') . PHP_EOL;
        }
        if (!$this->checkSize($aItem['photos'])) {
            $flash_error .= _m('Image is too big. Max. size') . osc_max_size_kb() . ' Kb' . PHP_EOL;
        }

        $title_message = '';
        foreach ($aItem['title'] as $key => $value) {
            if (osc_validate_text($value) && osc_validate_max($value, osc_max_characters_per_title())) {
                $title_message = '';
                break;
            }

            $title_message .= (!osc_validate_text($value) ? sprintf(_m('Title too short (%s).'), $key) . PHP_EOL : '');
            $title_message .= (!osc_validate_max($value, osc_max_characters_per_title())
                ? sprintf(_m('Title too long (%s).'), $key) . PHP_EOL : '');
        }
        $flash_error .= $title_message;

        $desc_message = '';
        foreach ($aItem['description'] as $key => $value) {
            if (osc_validate_text($value, 3) && osc_validate_max($value, osc_max_characters_per_description())) {
                $desc_message = '';
                break;
            }
            $desc_message .= (!osc_validate_text($value, 3) ? sprintf(_m('Description too short (%s).'), $key) . PHP_EOL
                : '');
            $desc_message .= (!osc_validate_max($value, osc_max_characters_per_description())
                ? sprintf(_m('Description too long (%s).'), $key) . PHP_EOL : '');
        }
        $flash_error .= $desc_message;

        $flash_error .= ((!osc_validate_category($aItem['catId'])) ? _m('Category invalid.') . PHP_EOL : '');
        $flash_error .= ((!osc_validate_number($aItem['price'])) ? _m('Price must be a number.') . PHP_EOL : '');
        if ($aItem['price'] !== null) {
            $flash_error .= ((!osc_validate_max(number_format($aItem['price'], 0, '', ''), 15))
            ? _m('Price too long.')
            . PHP_EOL : '');
        }
        $flash_error .= (($aItem['price'] !== null && (float)$aItem['price'] < 0)
            ? _m('Price must be positive number.') . PHP_EOL : '');
        $flash_error .= ((!osc_validate_text($aItem['countryName'], 3, false))
            ? _m('Country too short.') . PHP_EOL
            : '');
        $flash_error .= ((!osc_validate_max($aItem['countryName'], 50)) ? _m('Country too long.') . PHP_EOL : '');
        $flash_error .= ((!osc_validate_text($aItem['regionName'], 2, false))
            ? _m('Region too short.') . PHP_EOL
            : '');
        $flash_error .= ((!osc_validate_max($aItem['regionName'], 50)) ? _m('Region too long.') . PHP_EOL : '');
        $flash_error .= ((!osc_validate_text($aItem['cityName'], 2, false))
            ? _m('City too short.') . PHP_EOL : '');
        $flash_error .= ((!osc_validate_max($aItem['cityName'], 50)) ? _m('City too long.') . PHP_EOL : '');
        $flash_error .= ((!osc_validate_text($aItem['cityArea'], 3, false))
            ? _m('Municipality too short.')
            . PHP_EOL : '');
        $flash_error .= ((!osc_validate_max($aItem['cityArea'], 50)) ? _m('Municipality too long.') . PHP_EOL : '');
        $flash_error .= ((!osc_validate_text($aItem['address'], 3, false))
            ? _m('Address too short.') . PHP_EOL
            : '');
        $flash_error .= ((!osc_validate_max($aItem['address'], 100)) ? _m('Address too long.') . PHP_EOL : '');
        if (isset($aItem['s_contact_phone']) && (!osc_validate_phone($aItem['s_contact_phone'], 4))) {
            $flash_error .= (_m('Phone invalid.') . PHP_EOL);
        }

        return $flash_error;
    }

    /**
     * Validate Item meta field and check required fields are not empty
     *
     * @param array  $_meta
     * @param        $meta
     * @param string $flash_error
     */
    private function handleMetaField(array $_meta, &$meta, string &$flash_error)
    {
        if (!empty($_meta) && is_array($meta)) {
            $valid_id = array_column($_meta, 'pk_i_id');
            // special case for checkboxes
            foreach ($_meta as $value) {
                if (isset($value['e_type']) && $value['e_type'] === 'CHECKBOX') {
                    $meta[$value['pk_i_id']] = ($meta[$value['pk_i_id']] ?? 0);
                }
            }
            foreach ($meta as $k => $v) {
                if (!in_array($k, $valid_id, false)) {
                    unset($meta[$k]);
                } else {
                    $key = array_search($k, array_column($_meta, 'pk_i_id'), false);
                    // Sanitize by type
                    $meta[$k] = $this->sanitizeMetaField($_meta[$key]['e_type'], $v);
                }
                unset($k, $v);
            }
            list($meta, $flash_error) = $this->validateMetaFields($_meta, $meta, $flash_error);
        }
    }

    /**
     * @param       $e_type
     * @param       $metaValue
     *
     * @return array
     */
    private function sanitizeMetaField($e_type, $metaValue)
    {
        switch ($e_type) {
            case 'DATEINTERVAL':
                if (!empty($metaValue)) {
                    if ($metaValue['from']) {
                        $metaValue['from'] = (int)$metaValue['from'];
                    }
                    if ($metaValue['to']) {
                        $metaValue['to'] = (int)$metaValue['to'];
                    }
                }
                break;
            case 'DATE':
                if (!empty($metaValue)) {
                    $metaValue = (int)$metaValue;
                }
                break;
            case 'CHECKBOX':
                $metaValue = (int)$metaValue;
                break;
            case 'URL':
                $metaValue = $this->Sanitize->websiteUrl($metaValue);
                break;
            default:
                // sanitize string safe for html
                $metaValue = $this->Sanitize->html($metaValue);
                break;
        }

        return $metaValue;
    }

    /**
     * @param array  $_meta
     * @param array  $meta
     * @param string $flash_error
     *
     * @return array
     */
    private function validateMetaFields($_meta, $meta, $flash_error)
    {
        foreach ($_meta as $_m) {
            $isMetaRequired = $_m['b_required'];
            $isMetaValueSet = isset($meta[$_m['pk_i_id']]);
            $metaValue      = $meta[$_m['pk_i_id']] ?? null;
            switch ($_m['e_type']) {
                case 'DATEINTERVAL':
                    if ($isMetaValueSet && $metaValue) {
                        if ($metaValue['from'] && $metaValue['to']) {
                            if (!is_numeric($metaValue['from']) || !is_numeric($metaValue['to'])) {
                                $flash_error .= sprintf(_m('%s is invalid.'), $_m['s_name']) . PHP_EOL;
                            }
                        } elseif ($isMetaRequired) {
                            $flash_error .= sprintf(_m('%s is required.'), $_m['s_name']) . PHP_EOL;
                        }
                    } elseif ($isMetaRequired) {
                        $flash_error .= sprintf(_m('%s is required.'), $_m['s_name']) . PHP_EOL;
                    }
                    break;
                case 'CHECKBOX':
                case 'NUMBER':
                case 'DATE':
                    if ($isMetaValueSet && $metaValue > 0) {
                        if (!is_numeric($metaValue)) {
                            $flash_error .= sprintf(_m('%s is invalid.'), $_m['s_name']) . PHP_EOL;
                        }
                    } elseif ($isMetaRequired) {
                        $flash_error .= sprintf(_m('%s is required.'), $_m['s_name']) . PHP_EOL;
                    }
                    break;
                case 'RADIO':
                case 'DROPDOWN':
                    if ($isMetaValueSet && $metaValue) {
                        // check value exist in options csv
                        if (!in_array($metaValue, explode(',', $_m['s_options']), false)) {
                            $flash_error .= sprintf(_m('%s is invalid.'), $_m['s_name']) . PHP_EOL;
                        }
                    } elseif ($isMetaRequired) {
                        $flash_error .= sprintf(_m('%s is required.'), $_m['s_name']) . PHP_EOL;
                    }
                    break;
                case 'URL':
                    if ($isMetaValueSet && $metaValue) {
                        // first validate using filter_var than osc_validate_url
                        if (!filter_var($metaValue, FILTER_VALIDATE_URL)) {
                            $flash_error .= sprintf(_m('%s is invalid.'), $_m['s_name']) . PHP_EOL;
                        } elseif (!osc_validate_url($metaValue)) {
                            $flash_error .= sprintf(_m('%s is invalid.'), $_m['s_name']) . PHP_EOL;
                        }
                    } elseif ($isMetaRequired) {
                        $flash_error .= sprintf(_m('%s is required.'), $_m['s_name']) . PHP_EOL;
                    }
                    break;
                case 'TEXTAREA':
                case 'TEXT':
                default:
                    if ($isMetaRequired && (!$isMetaValueSet || !$metaValue)) {
                        $flash_error .= sprintf(_m('%s is required.'), $_m['s_name']) . PHP_EOL;
                    }
                    break;
            }
        }

        return array($meta, $flash_error);
    }

    /**
     * @param $type
     * @param $title
     * @param $description
     * @param $itemId
     */
    public function insertItemLocales($type, $title, $description, $itemId)
    {
        foreach ($title as $k => $_data) {
            $_title       = $_data;
            $_description = $description[$k];
            if ($type === 'ADD') {
                $this->manager->insertLocale($itemId, $k, $_title, $_description);
            } elseif ($type === 'EDIT') {
                $this->manager->updateLocaleForce($itemId, $k, $_title, $_description);
            }
        }
    }

    /**
     * Return item location array with geocoded coords if maps are enabled and coords data isn't already filled.
     *
     * @param array $location
     *
     * @return array
     */
    private function getItemCoordinates($location)
    {
        if ($location['d_coord_lat'] && $location['d_coord_long']) {
            return array();
        }
        if (!function_exists('osc_item_map_type') || !in_array(osc_item_map_type(), ['google', 'openstreet'])) {
            return array();
        }
        $mapType = osc_item_map_type();
        $address = sprintf('%s, %s, %s, %s', $location['s_address'], $location['s_city'], $location['s_region'], $location['s_country']);

        if ($mapType === 'google') {
            $res = json_decode(osc_file_get_contents(osc_google_maps_geocode_url($address)));
            if (isset($res->results[0]->geometry->location) && count($res->results[0]->geometry->location)) {
                $coords                   = $res->results[0]->geometry->location;
                $location['d_coord_lat']  = $coords->lat;
                $location['d_coord_long'] = $coords->lng;
            }
        } elseif ($mapType === 'openstreet') {
            $res = json_decode(osc_file_get_contents(osc_openstreet_geocode_url($address)));
            if (isset($res->results[0]->locations[0]->latLng) && count($res->results[0]->locations[0]->latLng)) {
                $coords                   = $res->results[0]->locations[0]->latLng;
                $location['d_coord_lat']  = $coords->lat;
                $location['d_coord_long'] = $coords->lng;
            }
        }

        return $location;
    }

    /**
     * @param $aResources
     * @param $itemId
     *
     * @return int
     */
    public function uploadItemResources($aResources, $itemId)
    {
        if (!empty($aResources)) {
            $itemResourceManager = ItemResource::newInstance();
            $folder              = osc_uploads_path() . floor($itemId / 100) . '/';

            $maxImagesPerItem = osc_max_images_per_item();
            $totalItemImages  = $itemResourceManager->countResources($itemId);
            foreach ($aResources['error'] as $key => $error) {
                if ($maxImagesPerItem == 0 || ($maxImagesPerItem > 0 && $totalItemImages < $maxImagesPerItem)) {
                    if ($error == UPLOAD_ERR_OK) {
                        $tmpName   = $aResources['tmp_name'][$key];
                        $imgres    = ImageProcessing::fromFile($tmpName);
                        $extension = osc_apply_filter('upload_image_extension', $imgres->getExt());
                        $mime      = osc_apply_filter('upload_image_mime', $imgres->getMime());

                        // Create normal size
                        $path        = $tmpName . '_normal';
                        $normal_path = $path;
                        $size        = explode('x', osc_normal_dimensions());
                        $img         = $imgres->autoRotate();

                        $img = $img->resizeTo($size[0], $size[1]);
                        if (osc_is_watermark_text()) {
                            $img->doWatermarkText(osc_watermark_text(), osc_watermark_text_color());
                        } elseif (osc_is_watermark_image()) {
                            $img->doWatermarkImage();
                        }
                        $img->saveToFile($path, $extension);
                        // Create preview
                        $path = $tmpName . '_preview';
                        $size = explode('x', osc_preview_dimensions());
                        ImageProcessing::fromFile($normal_path)->resizeTo($size[0], $size[1])
                            ->saveToFile($path, $extension);

                        // Create thumbnail
                        $path = $tmpName . '_thumbnail';
                        $size = explode('x', osc_thumbnail_dimensions());
                        ImageProcessing::fromFile($normal_path)->resizeTo($size[0], $size[1])
                            ->saveToFile($path, $extension);

                        $totalItemImages++;

                        $itemResourceManager->insert(array(
                            'fk_i_item_id' => $itemId
                        ));
                        $resourceId = $itemResourceManager->dao->insertedId();

                        if (!is_dir($folder) && !mkdir($folder, 0755, true) && !is_dir($folder)) {
                            return 3; // PATH CAN NOT BE CREATED
                        }
                        osc_copy($tmpName . '_normal', $folder . $resourceId . '.' . $extension);
                        osc_copy($tmpName . '_preview', $folder . $resourceId . '_preview.' . $extension);
                        osc_copy($tmpName . '_thumbnail', $folder . $resourceId . '_thumbnail.' . $extension);
                        if (osc_keep_original_image()) {
                            $path = $folder . $resourceId . '_original.' . $extension;
                            osc_copy($tmpName, $path);
                        }
                        unlink($tmpName . '_normal');
                        unlink($tmpName . '_preview');
                        unlink($tmpName . '_thumbnail');
                        unlink($tmpName);

                        $s_path = str_replace(osc_base_path(), '', $folder);
                        $itemResourceManager->update(
                            array(
                                's_path'         => $s_path,
                                's_name'         => osc_genRandomPassword(),
                                's_extension'    => $extension,
                                's_content_type' => $mime
                            ),
                            array(
                                'pk_i_id'      => $resourceId,
                                'fk_i_item_id' => $itemId
                            )
                        );
                        osc_run_hook('uploaded_file', ItemResource::newInstance()->findByPrimaryKey($resourceId));
                    }
                }
            }
            unset($itemResourceManager);
        }

        return 0; // NO PROBLEMS
    }

    /**
     * @param $aItem
     */
    public function sendEmails($aItem)
    {
        $item = $aItem['item'];
        View::newInstance()->_exportVariableToView('item', $item);

        $userId     = Session::newInstance()->_get('userId');
        $itemActive = $aItem['active'];
        /**
         * Send email to non-reg user requesting item activation
         */
        if ($itemActive === 'INACTIVE' && !$userId) {
            osc_run_hook('hook_email_item_validation_non_register_user', $item);
        } elseif ($itemActive === 'INACTIVE') { //  USER IS REGISTERED
            osc_run_hook('hook_email_item_validation', $item);
        } elseif (!$userId) { // USER IS NOT REGISTERED
            osc_run_hook('hook_email_new_item_non_register_user', $item);
        }

        /**
         * Send email to admin about the new item
         */
        if (osc_notify_new_item()) {
            osc_run_hook('hook_email_admin_new_item', $item);
        }
    }

    /**
     * Private function for increment stats.
     * tables: t_user/t_category_stats/t_country_stats/t_region_stats/t_city_stats
     *
     * @param array item
     *
     */
    private function increaseStats($item)
    {
        if ($item['fk_i_user_id'] !== null) {
            User::newInstance()->increaseNumItems($item['fk_i_user_id']);
        }
        if ($item['fk_i_category_id'] !== null && $item['fk_i_category_id'] !== '') {
            CategoryStats::newInstance()->increaseNumItems($item['fk_i_category_id']);
        }
        if ($item['fk_c_country_code'] !== null && $item['fk_c_country_code'] !== '') {
            CountryStats::newInstance()->increaseNumItems($item['fk_c_country_code']);
        }
        if ($item['fk_i_region_id'] !== null && $item['fk_i_region_id'] !== '') {
            RegionStats::newInstance()->increaseNumItems($item['fk_i_region_id']);
        }
        if ($item['fk_i_city_id'] !== null && $item['fk_i_city_id'] !== '') {
            CityStats::newInstance()->increaseNumItems($item['fk_i_city_id']);
        }
        osc_run_hook('item_increase_stat', $item);
    }

    /**
     * Disable an item.
     * Set s_enabled value to 0, for a given item id
     *
     * @param int $id
     *
     * @return bool
     */
    public function disable($id)
    {
        $result = $this->manager->update(
            array('b_enabled' => 0),
            array('pk_i_id' => $id)
        );

        // updated correctly
        if ($result == 1) {
            osc_run_hook('disable_item', $id);
            $item = $this->manager->findByPrimaryKey($id);
            if ($item['b_active'] == 1 && $item['b_spam'] == 0 && !osc_isExpired($item['dt_expiration'])) {
                $this->_decreaseStats($item);
            }

            return true;
        }

        return false;
    }

    /**
     * Private function for decrease stats.
     * tables: t_user/t_category_stats/t_country_stats/t_region_stats/t_city_stats
     *
     * @param array item
     *
     */
    private function _decreaseStats($item)
    {
        if ($item['fk_i_user_id'] != null) {
            User::newInstance()->decreaseNumItems($item['fk_i_user_id']);
        }
        CategoryStats::newInstance()->decreaseNumItems($item['fk_i_category_id']);
        CountryStats::newInstance()->decreaseNumItems($item['fk_c_country_code']);
        RegionStats::newInstance()->decreaseNumItems($item['fk_i_region_id']);
        CityStats::newInstance()->decreaseNumItems($item['fk_i_city_id']);
        osc_run_hook('item_decrease_stat', $item);
    }

    /**
     * @return bool|mixed
     */
    public function edit()
    {
        $aItem       = $this->data;
        $aItem       = osc_apply_filter('item_edit_prepare_data', $aItem);
        $flash_error = '';

        // Sanitize
        foreach ($aItem['title'] as $key => $value) {
            $aItem['title'][$key] = $this->Sanitize->title($value);
        }

        if ($aItem['price'] !== null) {
            $aItem['price'] = $this->Sanitize->price($aItem['price']);
        }
        $aItem['cityArea']     = osc_sanitize_name(strip_tags(trim($aItem['cityArea'])));
        $aItem['address']      = osc_sanitize_name(strip_tags(trim($aItem['address'])));
        $aItem['contactPhone'] = $this->Sanitize->phone($aItem['contactPhone']);

        // Validate
        $flash_error .= $this->validateCommonInput($flash_error, $aItem);

        $_meta = Field::newInstance()->findByCategory($aItem['catId']);
        $meta  = Params::getParam('meta');
        $this->handleMetaField($_meta, $meta, $flash_error);

        // hook pre edit
        osc_run_hook('pre_item_edit', $aItem, $flash_error);
        $flash_error = osc_apply_filter('pre_item_edit_error', $flash_error, $aItem);

        // Handle error
        if ($flash_error) {
            $success = $flash_error;
        } else {
            $location = array(
                'fk_c_country_code' => $aItem['countryId'],
                's_country'         => $aItem['countryName'],
                'fk_i_region_id'    => $aItem['regionId'],
                's_region'          => $aItem['regionName'],
                'fk_i_city_id'      => $aItem['cityId'],
                's_city'            => $aItem['cityName'],
                's_city_area'       => $aItem['cityArea'],
                's_address'         => $aItem['address'],
                'd_coord_lat'       => $aItem['d_coord_lat'],
                'd_coord_long'      => $aItem['d_coord_long'],
                's_zip'             => $aItem['s_zip']
            );
            $location = array_merge($location, $this->getItemCoordinates($location));

            $locationManager   = ItemLocation::newInstance();
            $old_item_location = $locationManager->findByPrimaryKey($aItem['idItem']);

            $locationManager->update($location, array('fk_i_item_id' => $aItem['idItem']));

            $old_item = $this->manager->findByPrimaryKey($aItem['idItem']);

            if ($aItem['userId']) {
                $user                  = User::newInstance()->findByPrimaryKey($aItem['userId']);
                $aItem['contactName']  = $user['s_name'];
                $aItem['contactEmail'] = $user['s_email'];
            } else {
                $aItem['userId'] = null;
            }

            if (empty($aItem['price'])) {
                $aItem['currency'] = null;
            }

            $aUpdate = array(
                'dt_mod_date'        => date('Y-m-d H:i:s'),
                'fk_i_category_id'   => $aItem['catId'],
                'i_price'            => $aItem['price'],
                'fk_c_currency_code' => $aItem['currency'],
                'b_show_email'       => $aItem['showEmail'],
                's_contact_phone'    => $aItem['contactPhone'],
            );

            // only can change the user if you're an admin
            if ($this->is_admin) {
                $aUpdate['fk_i_user_id']    = $aItem['userId'];
                $aUpdate['s_contact_name']  = $aItem['contactName'];
                $aUpdate['s_contact_email'] = $aItem['contactEmail'];
            } else {
                $aUpdate['s_ip'] = $aItem['s_ip'];
            }

            $result = $this->manager->update($aUpdate, array(
                'pk_i_id'  => $aItem['idItem'],
                's_secret' => $aItem['secret']
            ));
            // UPDATE title and description locales
            $this->insertItemLocales('EDIT', $aItem['title'], $aItem['description'], $aItem['idItem']);
            // UPLOAD item resources
            $this->uploadItemResources($aItem['photos'], $aItem['idItem']);

            Log::newInstance()->insertLog(
                'item',
                'edit',
                $aItem['idItem'],
                current(array_values($aItem['title'])),
                $this->is_admin ? 'admin' : 'user',
                $this->is_admin ? osc_logged_admin_id() : osc_logged_user_id()
            );
            /**
             * META FIELDS
             */
            if ($meta && count($meta) > 0) {
                $mField = Field::newInstance();
                foreach ($meta as $k => $v) {
                    // if dateinterval
                    if (is_array($v) && !isset($v['from']) && !isset($v['to'])) {
                        $v = implode(',', $v);
                    }
                    $mField->replace($aItem['idItem'], $k, $v);
                }
            }

            $oldIsExpired  = osc_isExpired($old_item['dt_expiration']);
            $dt_expiration = Item::newInstance()
                ->updateExpirationDate($aItem['idItem'], $aItem['dt_expiration'], false);
            if ($dt_expiration === false) {
                $dt_expiration          = $old_item['dt_expiration'];
                $aItem['dt_expiration'] = $old_item['dt_expiration'];
            }
            $newIsExpired = osc_isExpired($dt_expiration);

            // Recalculate stats related with items
            $this->updateStats(
                $result,
                $old_item,
                $oldIsExpired,
                $old_item_location,
                $aItem,
                $newIsExpired,
                $location
            );

            unset($old_item);

            if (!$this->is_admin && osc_moderate_admin_edit()) {
                $this->disable($aItem['idItem']);
            }

            // THIS HOOK IS FINE, YAY!
            osc_run_hook('edited_item', Item::newInstance()->findByPrimaryKey($aItem['idItem']));
            $success = $result;
        }

        return $success;
    }

    /**
     * Increment or decrement stats related with items.
     *
     * User item stats, Category item stats,
     *  country item stats, region item stats, city item stats
     *
     * @param bool | array $result
     * @param array        $old_item
     * @param bool         $oldIsExpired
     * @param array        $old_item_location
     * @param array        $aItem
     * @param bool         $newIsExpired
     * @param array        $location
     *
     */
    private function updateStats(
        $result,
        $old_item,
        $oldIsExpired,
        $old_item_location,
        $aItem,
        $newIsExpired,
        $location
    ) {
        if ($result == 1 && $old_item['b_enabled'] == 1 && $old_item['b_active'] == 1 && $old_item['b_spam'] == 0) {
            // if old item is expired and new item is not expired.
            if ($oldIsExpired && !$newIsExpired) {
                // increment new item stats (user, category, location_stats)
                if (is_numeric($aItem['userId'])) {
                    User::newInstance()->increaseNumItems($aItem['userId']);
                }
                CategoryStats::newInstance()->increaseNumItems($aItem['catId']);
                CountryStats::newInstance()->increaseNumItems($location['fk_c_country_code']);
                RegionStats::newInstance()->increaseNumItems($location['fk_i_region_id']);
                CityStats::newInstance()->increaseNumItems($location['fk_i_city_id']);
            }
            // if old is not expired and new is expired
            if (!$oldIsExpired && $newIsExpired) {
                // decrement new item stats (user, category, location_stats)
                if (is_numeric($old_item['fk_i_user_id'])) {
                    User::newInstance()->decreaseNumItems($old_item['fk_i_user_id']);
                }
                CategoryStats::newInstance()->decreaseNumItems($aItem['catId']);
                CountryStats::newInstance()->decreaseNumItems($location['fk_c_country_code']);
                RegionStats::newInstance()->decreaseNumItems($location['fk_i_region_id']);
                CityStats::newInstance()->decreaseNumItems($location['fk_i_city_id']);
            }
            // if old item is not expired and new item is not expired
            if (!$oldIsExpired && !$newIsExpired) {
                // Update user stats - if old user diferent to actual user, update user stats
                if ($old_item['fk_i_user_id'] != $aItem['userId']) {
                    if (is_numeric($old_item['fk_i_user_id'])) {
                        User::newInstance()->decreaseNumItems($old_item['fk_i_user_id']);
                    }
                    if (is_numeric($aItem['userId'])) {
                        User::newInstance()->increaseNumItems($aItem['userId']);
                    }
                }
                // Update category numbers
                if ($old_item['fk_i_category_id'] != $aItem['catId']) {
                    CategoryStats::newInstance()->increaseNumItems($aItem['catId']);
                    CategoryStats::newInstance()->decreaseNumItems($old_item['fk_i_category_id']);
                }
                // Update location stats
                if ($old_item_location['fk_c_country_code'] != $location['fk_c_country_code']) {
                    CountryStats::newInstance()->decreaseNumItems($old_item_location['fk_c_country_code']);
                    CountryStats::newInstance()->increaseNumItems($location['fk_c_country_code']);
                }
                if ($old_item_location['fk_i_region_id'] != $location['fk_i_region_id']) {
                    RegionStats::newInstance()->decreaseNumItems($old_item_location['fk_i_region_id']);
                    RegionStats::newInstance()->increaseNumItems($location['fk_i_region_id']);
                }
                if ($old_item_location['fk_i_city_id'] != $location['fk_i_city_id']) {
                    CityStats::newInstance()->decreaseNumItems($old_item_location['fk_i_city_id']);
                    CityStats::newInstance()->increaseNumItems($location['fk_i_city_id']);
                }
            }
            // if old and new items are expired [nothing to do]
            // if($oldIsExpired && $newIsExpired) { }
        }
    }

    /**
     * Activates an item.
     * Set s_enabled value to 1, for a given item id
     *
     * @param int           $id
     *
     * @param string | null $secret
     *
     * @return bool
     */
    public function activate($id, $secret = null)
    {
        if ($secret === null) {
            $item[0] = $this->manager->findByPrimaryKey($id);
            $aWhere  = array('pk_i_id' => $id);
        } else {
            $item   = $this->manager->listWhere('i.s_secret = %s AND i.pk_i_id = %d ', $secret, (int)$id);
            $aWhere = array('s_secret' => $secret, 'pk_i_id' => $id);
        }

        if (
            isset($item[0]['b_enabled'], $item[0]['b_active']) && $item[0]['b_enabled'] == 1
            && $item[0]['b_active'] == 0
        ) {
            $result = $this->manager->update(
                array('b_active' => 1),
                $aWhere
            );

            // updated correctly
            if ($result == 1) {
                osc_run_hook('activate_item', $id);
                // b_enabled == 1 && b_active == 1
                if ($item[0]['b_spam'] == 0 && !osc_isExpired($item[0]['dt_expiration'])) {
                    $this->increaseStats($item[0]);
                }

                return true;
            }

            return false;
        }

        return -1;
    }

    /**
     * Deactivates an item
     * Set s_active value to 0, for a given item id
     *
     * @param int $id
     *
     * @return bool
     */
    public function deactivate($id)
    {
        $result = $this->manager->update(
            array('b_active' => 0),
            array('pk_i_id' => $id)
        );

        // updated correctly
        if ($result == 1) {
            osc_run_hook('deactivate_item', $id);
            $item = $this->manager->findByPrimaryKey($id);
            if ($item['b_enabled'] == 1 && $item['b_spam'] == 0 && !osc_isExpired($item['dt_expiration'])) {
                $this->_decreaseStats($item);
            }

            return true;
        }

        return false;
    }

    /**
     * Enable an item
     * Set s_enabled value to 1, for a given item id
     *
     * @param int $id
     *
     * @return bool
     */
    public function enable($id)
    {
        $result = $this->manager->update(
            array('b_enabled' => 1),
            array('pk_i_id' => $id)
        );

        // updated correctly
        if ($result == 1) {
            osc_run_hook('enable_item', $id);
            $item = $this->manager->findByPrimaryKey($id);
            if ($item['b_active'] == 1 && $item['b_spam'] == 0 && !osc_isExpired($item['dt_expiration'])) {
                $this->increaseStats($item);
            }

            return true;
        }

        return false;
    }

    /**
     * Set premium value depending on $on, for a given item id
     *
     * @param int  $id
     * @param bool $on
     *
     * @return bool
     */
    public function premium($id, $on = true)
    {
        $value = 0;
        if ($on) {
            $value = 1;
        }

        $result = $this->manager->update(
            array('b_premium' => $value),
            array('pk_i_id' => $id)
        );
        // updated correctly
        if ($result == 1) {
            if ($on) {
                osc_run_hook('item_premium_on', $id);
            } else {
                osc_run_hook('item_premium_off', $id);
            }

            return true;
        }

        return false;
    }

    /**
     * Set spam value depending on $on, for a given item id
     *
     * @param int  $id
     * @param bool $on
     *
     * @return bool
     */
    public function spam($id, $on = true)
    {
        $item = $this->manager->findByPrimaryKey($id);
        if ($on) {
            $result = $this->manager->update(
                array('b_spam' => '1'),
                array('pk_i_id' => $id)
            );
        } else {
            $result = $this->manager->update(
                array('b_spam' => '0'),
                array('pk_i_id' => $id)
            );
        }

        // updated corretcly
        if ($result == 1) {
            if ($on) {
                osc_run_hook('item_spam_on', $id);
            } else {
                osc_run_hook('item_spam_off', $id);
            }

            $b_active  = $item['b_active'];
            $b_enabled = $item['b_enabled'];
            $b_spam    = $item['b_spam'];
            $isExpired = osc_isExpired($item['dt_expiration']);


            if (
                $b_active == 1 && $b_enabled == 1 && $b_spam == 0
                && !$isExpired
            ) {
                $this->_decreaseStats($item);
            } elseif (
                $b_active == 1 && $b_enabled == 1 && $b_spam == 1
                && !$isExpired
            ) {
                $this->increaseStats($item);
            }

            return true;
        }

        return false;
    }

    /**
     * Delete an item, given s_secret and item id.
     *
     * @param string $secret
     * @param int    $itemId
     *
     * @return bool
     */
    public function delete($secret, $itemId)
    {
        $item = $this->manager->findByPrimaryKey($itemId);

        osc_run_hook('before_delete_item', $itemId);

        if ($item['s_secret'] == $secret) {
            Log::newInstance()
                ->insertLog(
                    'item',
                    'delete',
                    $itemId,
                    $item['s_title'],
                    $this->is_admin ? 'admin' : 'user',
                    $this->is_admin ? osc_logged_admin_id() : osc_logged_user_id()
                );
            $result = $this->manager->deleteByPrimaryKey($itemId);
            if ($result !== false) {
                osc_run_hook('after_delete_item', $itemId, $item);
            }

            return $result;
        }

        return false;
    }

    /**
     * Mark an item
     *
     * @param int    $id
     * @param string $as
     */
    public function mark($id, $as)
    {
        switch ($as) {
            case 'spam':
                $column = 'i_num_spam';
                break;
            case 'badcat':
                $column = 'i_num_bad_classified';
                break;
            case 'offensive':
                $column = 'i_num_offensive';
                break;
            case 'repeated':
                $column = 'i_num_repeated';
                break;
            case 'expired':
                $column = 'i_num_expired';
                break;
        }

        if (isset($column)) {
            // Gate the mark before it counts: a listener returns false to block it (per-reporter
            // dedup, rate-limit, captcha) — core applies no such control of its own. Only when it
            // passes does the counter move and the after-hook fire for the reaction (threshold
            // auto-block, logging, notifications).
            if (osc_apply_filter('item_mark', true, $id, $as) === false) {
                return;
            }
            if (ItemStats::newInstance()->increase($column, $id) !== false) {
                osc_run_hook('item_marked', $id, $as);
            }
        }
    }

    /**
     * Send listed item details to friend
     *
     * @return bool
     */
    public function send_friend()
    {
        // get data for this function
        $aItem = $this->prepareDataForFunction('send_friend');

        $item = $aItem['item'];
        View::newInstance()->_exportVariableToView('item', $item);

        osc_run_hook('hook_email_send_friend', $aItem);
        $item_url = osc_item_url();
        $item_url = '<a href="' . $item_url . '" >' . $item_url . '</a>';
        Params::setParam('item_url', $item_url);
        osc_add_flash_ok_message(sprintf(_m('We just sent your message to %s'), $aItem['friendName']));

        return true;
    }

    /**
     * Return an array with all data necessary for do the action
     *
     * @param string $action
     *
     * @return array
     */
    private function prepareDataForFunction($action)
    {
        $aItem = array();

        switch ($action) {
            case 'send_friend':
                $item = $this->manager->findByPrimaryKey(Params::getParam('id'));
                if ($item === false || !is_array($item) || count($item) == 0) {
                    break;
                }

                $aItem['item'] = $item;
                View::newInstance()->_exportVariableToView('item', $aItem['item']);
                $aItem['yourName']  = Params::getParam('yourName');
                $aItem['yourEmail'] = Params::getParam('yourEmail');

                $aItem['friendName']  = Params::getParam('friendName');
                $aItem['friendEmail'] = Params::getParam('friendEmail');

                $aItem['s_title'] = $item['s_title'];
                $aItem['message'] = Params::getParam('message');
                break;
            case 'contact':
                $item = $this->manager->findByPrimaryKey(Params::getParam('id'));
                if ($item === false || !is_array($item) || count($item) == 0) {
                    break;
                }

                $aItem['item'] = $item;
                View::newInstance()->_exportVariableToView('item', $aItem['item']);
                $aItem['id']          = Params::getParam('id');
                $aItem['yourEmail']   = Params::getParam('yourEmail');
                $aItem['yourName']    = Params::getParam('yourName');
                $aItem['message']     = Params::getParam('message');
                $aItem['phoneNumber'] = Params::getParam('phoneNumber');
                break;
            case 'add_comment':
                $item = $this->manager->findByPrimaryKey(Params::getParam('id'));
                if ($item === false || !is_array($item) || count($item) == 0) {
                    break;
                }

                $aItem['item'] = $item;
                View::newInstance()->_exportVariableToView('item', $aItem['item']);
                $aItem['authorName']  = Params::getParam('authorName');
                $aItem['authorEmail'] = Params::getParam('authorEmail');
                $aItem['body']        = Params::getParam('body');
                $aItem['title']       = Params::getParam('title');
                $aItem['id']          = Params::getParam('id');
                $aItem['userId']      = Session::newInstance()->_get('userId');
                if ($aItem['userId'] == '') {
                    $aItem['userId'] = null;
                }

                break;
            default:
        }

        return $aItem;
    }

    /**
     * @return string
     */
    public function contact()
    {
        $aItem       = $this->prepareDataForFunction('contact');
        $flash_error = '';
        // check parameters
        if (!osc_validate_text($aItem['yourName'])) {
            $flash_error = __('Your name: this field is required') . PHP_EOL;
        }
        if (!osc_validate_email($aItem['yourEmail'])) {
            $flash_error .= __('Invalid email address' . $aItem['yourEmail']) . PHP_EOL;
        }
        if (!osc_validate_text($aItem['message'])) {
            $flash_error .= __('Message: this field is required') . PHP_EOL;
        }


        if (!empty($flash_error)) {
            return $flash_error;
        }

        osc_run_hook('hook_email_item_inquiry', $aItem);
    }

    /**
     * @return int
     */
    public function add_comment()
    {
        if (!osc_comments_enabled()) {
            return 7;
        }

        $aItem = $this->prepareDataForFunction('add_comment');


        $authorName  = trim(strip_tags($aItem['authorName']));
        $authorEmail = trim(strip_tags($aItem['authorEmail']));
        $body        = trim(strip_tags($aItem['body']));
        $title       = trim(strip_tags($aItem['title']));
        $itemId      = $aItem['id'];
        $userId      = $aItem['userId'];

        $banned = osc_is_banned(trim(strip_tags($aItem['authorEmail'])));
        if ($banned === 1 || $banned === 2) {
            Session::newInstance()->_setForm('commentAuthorName', $authorName);
            Session::newInstance()->_setForm('commentTitle', $title);
            Session::newInstance()->_setForm('commentBody', $body);
            Session::newInstance()->_setForm('commentAuthorEmail', $authorEmail);

            return 5;
        }

        $item = $this->manager->findByPrimaryKey($itemId);
        View::newInstance()->_exportVariableToView('item', $item);
        $itemURL = osc_item_url();
        $itemURL = '<a href="' . $itemURL . '" >' . $itemURL . '</a>';

        Params::setParam('itemURL', $itemURL);

        if (osc_reg_user_post_comments() && !osc_is_web_user_logged_in()) {
            Session::newInstance()->_setForm('commentAuthorName', $authorName);
            Session::newInstance()->_setForm('commentTitle', $title);
            Session::newInstance()->_setForm('commentBody', $body);

            return 6;
        }

        if (!preg_match('|^.*?@.{2,}\..{2,3}$|', $authorEmail)) {
            Session::newInstance()->_setForm('commentAuthorName', $authorName);
            Session::newInstance()->_setForm('commentTitle', $title);
            Session::newInstance()->_setForm('commentBody', $body);

            return 3;
        }

        if ($body == '') {
            Session::newInstance()->_setForm('commentAuthorName', $authorName);
            Session::newInstance()->_setForm('commentAuthorEmail', $authorEmail);
            Session::newInstance()->_setForm('commentTitle', $title);

            return 4;
        }

        $num_moderate_comments = osc_moderate_comments();
        if ($userId == null) {
            $num_comments = 0;
        } else {
            $user         = User::newInstance()->findByPrimaryKey($userId);
            $num_comments = $user['i_comments'];
        }

        if (
            $num_moderate_comments == -1
            || ($num_moderate_comments != 0
                && $num_comments >= $num_moderate_comments)
        ) {
            $status     = 'ACTIVE';
            $status_num = 2;
        } else {
            $status     = 'INACTIVE';
            $status_num = 1;
        }

        if (osc_akismet_key()) {
            $akismet = new Akismet(osc_base_url(), osc_akismet_key());
            $akismet->setCommentAuthor($authorName);
            $akismet->setCommentAuthorEmail($authorEmail);
            $akismet->setCommentContent($body);
            $akismet->setPermalink($itemURL);

            $status = $akismet->isCommentSpam() ? 'SPAM' : $status;
            if ($status === 'SPAM') {
                $status_num = 5;
            }
        }

        $mComments = ItemComment::newInstance();
        $aComment  = array(
            'dt_pub_date'    => date('Y-m-d H:i:s'),
            'fk_i_item_id'   => $itemId,
            's_author_name'  => $authorName,
            's_author_email' => $authorEmail,
            's_title'        => $title,
            's_body'         => $body,
            'b_active'       => $status === 'ACTIVE' ? 1 : 0,
            'b_enabled'      => 1,
            'fk_i_user_id'   => $userId
        );

        osc_run_hook('before_add_comment', $aComment);

        if ($mComments->insert($aComment)) {
            $commentID = $mComments->dao->insertedId();
            if ($status_num == 2 && $userId != null) { // COMMENT IS ACTIVE
                $user = User::newInstance()->findByPrimaryKey($userId);
                if ($user) {
                    User::newInstance()->update(
                        array('i_comments' => $user['i_comments'] + 1),
                        array('pk_i_id' => $user['pk_i_id'])
                    );
                }
                //Notify user (only if comment is active)
                if (osc_notify_new_comment_user()) {
                    osc_run_hook('hook_email_new_comment_user', $aItem);
                }
            }

            //Notify admin
            if (osc_notify_new_comment()) {
                osc_run_hook('hook_email_new_comment_admin', $aItem);
            }

            osc_run_hook('add_comment', $commentID);

            return $status_num;
        }

        return -1;
    }

    /**
     * Return an array with all data necessary for do the action (ADD OR EDIT)
     *
     * @param bool $is_add
     *
     * @return void
     */
    public function prepareData($is_add)
    {
        $aItem = array();
        $data  = array();

        $userId = null;
        if ($this->is_admin) {
            // user
            $data = User::newInstance()->findByEmail(Params::getParam('contactEmail'));
            if (isset($data['pk_i_id']) && is_numeric($data['pk_i_id'])) {
                $userId = $data['pk_i_id'];
            }
        } else {
            $userId = Session::newInstance()->_get('userId');
            if ($userId == '') {
                $userId = null;
            } elseif ($userId != null) {
                $data = User::newInstance()->findByPrimaryKey($userId);
            }
        }

        if ($userId != null) {
            $aItem['contactName']  = $data['s_name'];
            $aItem['contactEmail'] = $data['s_email'];
            Params::setParam('contactName', $data['s_name']);
            Params::setParam('contactEmail', $data['s_email']);
        } else {
            $aItem['contactName']  = Params::getParam('contactName');
            $aItem['contactEmail'] = Params::getParam('contactEmail');
        }
        $aItem['userId'] = $userId;

        if ($is_add) {   // ADD
            if ($this->is_admin) {
                $active = 'ACTIVE';
            } elseif (osc_moderate_items() > 0) { // HAS TO VALIDATE
                if (!osc_is_web_user_logged_in()) { // NO USER IS LOGGED, VALIDATE
                    $active = 'INACTIVE';
                } elseif (osc_logged_user_item_validation()) { //USER IS LOGGED, BUT NO NEED TO VALIDATE
                    $active = 'ACTIVE';
                } else { // USER IS LOGGED, NEED TO VALIDATE, CHECK NUMBER OF PREVIOUS ITEMS
                    $user = User::newInstance()->findByPrimaryKey(osc_logged_user_id());
                    if ($user['i_items'] < osc_moderate_items()) {
                        $active = 'INACTIVE';
                    } else {
                        $active = 'ACTIVE';
                    }
                }
            } elseif (osc_moderate_items() == 0) {
                if (osc_is_web_user_logged_in() && osc_logged_user_item_validation()) {
                    $active = 'ACTIVE';
                } else {
                    $active = 'INACTIVE';
                }
            } else {
                $active = 'ACTIVE';
            }
            $aItem['active'] = $active;
        } else {          // EDIT
            $aItem['secret'] = Params::getParam('secret');
            $aItem['idItem'] = Params::getParam('id');
        }

        // get params
        $aItem['catId']        = Params::getParam('catId');
        $aItem['countryId']    = Params::getParam('countryId');
        $aItem['country']      = Params::getParam('country');
        $aItem['region']       = Params::getParam('region');
        $aItem['regionId']     = Params::getParam('regionId');
        $aItem['city']         = Params::getParam('city');
        $aItem['cityId']       = Params::getParam('cityId');
        $aItem['price']        = Params::getParam('price') ?: null;
        $aItem['cityArea']     = Params::getParam('cityArea');
        $aItem['address']      = Params::getParam('address');
        $aItem['currency']     = Params::getParam('currency');
        $aItem['showEmail']    = Params::getParam('showEmail') ? 1 : 0;
        $aItem['title']        = Params::getParam('title');
        $aItem['description']  =
            (osc_tinymce_frontend() || (defined('OC_ADMIN') && OC_ADMIN)) ? Params::getParam('description', false, false) : Params::getParam('description');
        $aItem['photos']       = Params::getFiles('photos');
        $ajax_photos           = Params::getParam('ajax_photos');
        $aItem['s_ip']         = get_ip();
        $aItem['d_coord_lat']  = Params::getParam('d_coord_lat') ?: null;
        $aItem['d_coord_long'] = Params::getParam('d_coord_long') ?: null;
        $aItem['s_zip']        = Params::getParam('zip') ?: null;
        $aItem['contactPhone'] = Params::getParam('contactPhone');

        // $ajax_photos is an array of filenames of the photos uploaded by ajax to a temporary folder
        // fake insert them into the array of the form-uploaded photos
        if (is_array($ajax_photos) && !empty($ajax_photos)) {
            foreach ($ajax_photos as $photo) {
                if (file_exists(osc_content_path() . 'uploads/temp/' . $photo)) {
                    $aItem['photos']['name'][]     = $photo;
                    $aItem['photos']['type'][]     = 'image/*';
                    $aItem['photos']['tmp_name'][] = osc_content_path() . 'uploads/temp/' . $photo;
                    $aItem['photos']['error'][]    = UPLOAD_ERR_OK;
                    $aItem['photos']['size'][]     = 0;
                }
            }
        }

        if ($is_add || $this->is_admin) {
            $dt_expiration = Params::getParam('dt_expiration');
            if ($dt_expiration == -1) {
                $aItem['dt_expiration'] = '';
            } elseif (
                $dt_expiration != ''
                && (
                    ctype_digit($dt_expiration)
                    || preg_match(
                        '|^([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})$|',
                        $dt_expiration,
                        $match
                    )
                    || preg_match('|^([0-9]{4})-([0-9]{2})-([0-9]{2})$|', $dt_expiration, $match)
                )
            ) {
                $aItem['dt_expiration'] = $dt_expiration;
                $_category              = Category::newInstance()->findByPrimaryKey($aItem['catId']);
                if (ctype_digit($dt_expiration)) {
                    if (!$this->is_admin && $dt_expiration > $_category['i_expiration_days']) {
                        $aItem['dt_expiration'] = $_category['i_expiration_days'];
                    }
                } else {
                    if (preg_match('|^([0-9]{4})-([0-9]{2})-([0-9]{2})$|', $dt_expiration, $match)) {
                        $aItem['dt_expiration'] .= ' 23:59:59';
                    }
                    if (
                        !$this->is_admin
                        && strtotime($dt_expiration) > (time() + $_category['i_expiration_days'] * 24 * 3600)
                    ) {
                        $aItem['dt_expiration'] = $_category['i_expiration_days'];
                    }
                }
            } else {
                $_category              = Category::newInstance()->findByPrimaryKey($aItem['catId']);
                $aItem['dt_expiration'] = $_category['i_expiration_days'] ?? null;
            }
            unset($dt_expiration);
        } else {
            $aItem['dt_expiration'] = '';
        }

        // check params
        $country = Country::newInstance()->findByCode($aItem['countryId']);
        if (count($country) > 0) {
            $countryId   = $country['pk_c_code'];
            $countryName = $country['s_name'];
        } else {
            $countryId   = null;
            $countryName = $aItem['country'];
        }
        $aItem['countryId']   = $countryId;
        $aItem['countryName'] = $countryName;

        if ($aItem['regionId'] != '') {
            if ((int)$aItem['regionId']) {
                $region = Region::newInstance()->findByPrimaryKey($aItem['regionId']);
                if (count($region) > 0) {
                    $regionId   = $region['pk_i_id'];
                    $regionName = $region['s_name'];
                }
            }
        } else {
            $regionId   = null;
            $regionName = $aItem['region'];
            if ($aItem['countryId'] != '') {
                $auxRegion = Region::newInstance()->findByName($aItem['region'], $aItem['countryId']);
                if ($auxRegion) {
                    $regionId   = $auxRegion['pk_i_id'];
                    $regionName = $auxRegion['s_name'];
                }
            }
        }

        if (isset($regionId)) {
            $aItem['regionId'] = $regionId;
        } else {
            $aItem['regionId'] = null;
        }

        if (isset($regionName)) {
            $aItem['regionName'] = $regionName;
        }

        if ($aItem['cityId'] != '') {
            if ((int)$aItem['cityId']) {
                $city = City::newInstance()->findByPrimaryKey($aItem['cityId']);
                if (count($city) > 0) {
                    $cityId   = $city['pk_i_id'];
                    $cityName = $city['s_name'];
                }
            }
        } else {
            $cityId   = null;
            $cityName = $aItem['city'];
            if ($aItem['countryId'] != '') {
                $auxCity = City::newInstance()->findByName($aItem['city'], $aItem['regionId']);
                if ($auxCity) {
                    $cityId   = $auxCity['pk_i_id'];
                    $cityName = $auxCity['s_name'];
                }
            }
        }

        if (isset($cityId)) {
            $aItem['cityId'] = $cityId;
        } else {
            $aItem['cityId'] = null;
        }

        if (isset($cityName)) {
            $aItem['cityName'] = $cityName;
        }

        if ($aItem['cityArea'] == '') {
            $aItem['cityArea'] = null;
        }

        if ($aItem['address'] == '') {
            $aItem['address'] = null;
        }

        if ($aItem['price'] !== null) {
            $price          = str_replace(
                array(osc_locale_thousands_sep(), osc_locale_dec_point()),
                array('', '.'),
                trim($aItem['price'])
            );
            $aItem['price'] = $price * 1000000;
            //$aItem['price'] = (float) $aItem['price'];
        }

        if ($aItem['catId'] == '') {
            $aItem['catId'] = 0;
        }

        if ($aItem['currency'] == '') {
            $aItem['currency'] = null;
        }

        $aItem      = osc_apply_filter('item_prepare_data', $aItem);
        $this->data = $aItem;
    }
}
