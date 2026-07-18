<?php if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

/*
 * This file is part of Osclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Class CAdminSettingsMedia
 */
class CAdminSettingsMedia extends AdminSecBaseModel
{
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_media');
    }

    //Business Layer...
    public function doModel()
    {
        switch ($this->action) {
            case ('media'):
                // calling the media view
                $max_upload   = $this->_sizeToKB(ini_get('upload_max_filesize'));
                $max_post     = $this->_sizeToKB(ini_get('post_max_size'));
                $memory_limit = $this->_sizeToKB(ini_get('memory_limit'));
                $upload_mb    = min($max_upload, $max_post, $memory_limit);

                $this->_exportVariableToView('max_size_upload', $upload_mb);
                $this->doView('settings/media.php');
                break;
            case ('media_post'):
                // updating the media config
                osc_csrf_check();
                $status = 'ok';
                $error  = '';

                $iUpdated               = 0;
                $maxSizeKb              = Params::getParam('maxSizeKb');
                $dimThumbnail           = strtolower(Params::getParam('dimThumbnail'));
                $dimPreview             = strtolower(Params::getParam('dimPreview'));
                $dimNormal              = strtolower(Params::getParam('dimNormal'));
                $keepOriginalImage      = Params::getParam('keep_original_image');
                $forceAspectImage       = Params::getParam('force_aspect_image');
                $forceJPEG              = Params::getParam('force_jpeg');
                $use_imagick            = Params::getParam('use_imagick');
                $type_watermark         = Params::getParam('watermark_type');
                $watermark_color        = Params::getParam('watermark_text_color');
                $watermark_text         = Params::getParam('watermark_text');
                $watermark_text_options = array(
                    'watermark_width'  => (int)Params::getParam(('watermark_width')),
                    'watermark_height' => (int)Params::getParam(('watermark_height')),
                    'text_offset_x'    => (int)Params::getParam(('text_offset_x')),
                    'text_offset_y'    => (int)Params::getParam(('text_offset_y')),
                    'text_angle'       => (int)Params::getParam(('text_angle')),
                    'background_color' => Params::getParam(('background_color'))
                );

                switch ($type_watermark) {
                    case 'none':
                        $iUpdated += osc_set_preference('watermark_text_color', '');
                        $iUpdated += osc_set_preference('watermark_text', '');
                        $iUpdated += osc_set_preference('watermark_image', '');
                        break;
                    case 'text':
                        $iUpdated += osc_set_preference('watermark_text_color', $watermark_color);
                        $iUpdated += osc_set_preference('watermark_text', $watermark_text);
                        $iUpdated += osc_set_preference('watermark_image', '');
                        $iUpdated += osc_set_preference('watermark_place', Params::getParam('watermark_text_place'));
                        osc_set_preference('watermark_text_options', json_encode($watermark_text_options));
                        break;
                    case 'image':
                        // upload image & move to path
                        $watermark_file = Params::getFiles('watermark_image');
                        if ($watermark_file['tmp_name'] != '' && $watermark_file['size'] > 0) {
                            if ($watermark_file['error'] == UPLOAD_ERR_OK) {
                                $tmpName   = $watermark_file['tmp_name'];
                                $imageInfo = @getimagesize($tmpName);
                                if ($imageInfo !== false && $imageInfo['mime'] === 'image/png') {
                                    $path = osc_uploads_path() . '/watermark.png';
                                    if (move_uploaded_file($tmpName, $path)) {
                                        $iUpdated += osc_set_preference('watermark_image', $path);
                                    } else {
                                        $status = 'error';
                                        $error  .= _m('There was a problem uploading the watermark image') . '<br />';
                                    }
                                } else {
                                    $status = 'error';
                                    $error  .= _m('The watermark image has to be a .PNG file') . '<br />';
                                }
                            } else {
                                $status = 'error';
                                $error  .= _m('There was a problem uploading the watermark image') . '<br />';
                            }
                        }
                        $iUpdated += osc_set_preference('watermark_text_color', '');
                        $iUpdated += osc_set_preference('watermark_text', '');
                        $iUpdated += osc_set_preference('watermark_place', Params::getParam('watermark_image_place'));
                        break;
                    default:
                        break;
                }

                // format parameters
                $maxSizeKb         = trim(strip_tags($maxSizeKb));
                $dimThumbnail      = trim(strip_tags($dimThumbnail));
                $dimPreview        = trim(strip_tags($dimPreview));
                $dimNormal         = trim(strip_tags($dimNormal));
                $keepOriginalImage = ($keepOriginalImage != '');
                $forceAspectImage  = ($forceAspectImage != '');
                $forceJPEG         = ($forceJPEG != '');
                $use_imagick       = ($use_imagick != '');

                if (!preg_match('|([0-9]+)x([0-9]+)|', $dimThumbnail, $match)) {
                    $dimThumbnail = is_numeric($dimThumbnail) ? $dimThumbnail . 'x' . $dimThumbnail : '100x100';
                }
                if (!preg_match('|([0-9]+)x([0-9]+)|', $dimPreview, $match)) {
                    $dimPreview = is_numeric($dimPreview) ? $dimPreview . 'x' . $dimPreview : '100x100';
                }
                if (!preg_match('|([0-9]+)x([0-9]+)|', $dimNormal, $match)) {
                    $dimNormal = is_numeric($dimNormal) ? $dimNormal . 'x' . $dimNormal : '100x100';
                }

                // is imagick extension loaded?
                if (!@extension_loaded('imagick')) {
                    $use_imagick = false;
                }

                // max size allowed by PHP configuration?
                $max_upload   = (int)(ini_get('upload_max_filesize'));
                $max_post     = (int)(ini_get('post_max_size'));
                $memory_limit = (int)(ini_get('memory_limit'));
                $upload_mb    = min($max_upload, $max_post, $memory_limit) * 1024;

                // set maxSizeKB equals to PHP configuration if it's bigger
                if ($maxSizeKb > $upload_mb) {
                    $status    = 'warning';
                    $maxSizeKb = $upload_mb;
                    // flash message text warning
                    $error .= sprintf(
                        _m('You cannot set a maximum file size higher than the one allowed in the PHP configuration: <b>%d KB</b>'),
                        $upload_mb
                    );
                }

                $iUpdated += osc_set_preference('maxSizeKb', $maxSizeKb);
                $iUpdated += osc_set_preference('dimThumbnail', $dimThumbnail);
                $iUpdated += osc_set_preference('dimPreview', $dimPreview);
                $iUpdated += osc_set_preference('dimNormal', $dimNormal);
                $iUpdated += osc_set_preference('keep_original_image', $keepOriginalImage);
                $iUpdated += osc_set_preference('force_aspect_image', $forceAspectImage);
                $iUpdated += osc_set_preference('force_jpeg', $forceJPEG);
                $iUpdated += osc_set_preference('use_imagick', $use_imagick);

                if ($error != '') {
                    switch ($status) {
                        case ('error'):
                            osc_add_flash_error_message($error, 'admin');
                            break;
                        case ('warning'):
                            osc_add_flash_warning_message($error, 'admin');
                            break;
                        default:
                            osc_add_flash_ok_message($error, 'admin');
                            break;
                    }
                } else {
                    osc_add_flash_ok_message(_m('Media config has been updated'), 'admin');
                }

                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=media');
                break;
            case ('images_post'):
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m("This action can't be done because it's a demo site"), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=media');
                }
                osc_csrf_check();

                $aResources = ItemResource::newInstance()->getAllResources();
                foreach ($aResources as $resource) {
                    osc_run_hook('regenerate_image', $resource);
                    if (strpos($resource['s_content_type'], 'image') !== false) {
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
                            $use_original = false;
                            continue;
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
                }

                osc_add_flash_ok_message(_m('Re-generation complete'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=media');
                break;
        }
    }

    /**
     * @param $sSize
     *
     * @return int
     */
    public function _sizeToKB($sSize)
    {
        $sSuffix = strtoupper(substr($sSize, -1));
        if (!in_array($sSuffix, array('P', 'T', 'G', 'M', 'K'))) {
            return (int)$sSize;
        }
        $iValue = substr($sSize, 0, -1);
        switch ($sSuffix) {
            case 'P':
                $iValue *= 1024;
            case 'T':
                $iValue *= 1024;
            case 'G':
                $iValue *= 1024;
            case 'M':
                $iValue *= 1024;
                break;
        }

        return (int)$iValue;
    }
}

// EOF: ./oc-admin/controller/settings/CAdminSettingsMedia.php
