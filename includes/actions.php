<?php
/*
 * Shopclass - Free and open source theme for Osclass with premium features
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/shopclass
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

	use shopclass\includes\cacheModal\tfcCache;
	use shopclass\includes\classes\tfcAdminDashboard;
	use shopclass\includes\classes\tfcImageManager;
	use shopclass\includes\classes\tfcUserAvatar;
	use shopclass\includes\SocialLogin\tfc_socialData;

	/**
     *
     */
    function theme_shopclass_actions_admin ()
    {

        switch (Params::getParam ( 'action_specific' )) {
            case ('header-settings'):
                tfc_setPref ( 'header_logo_text' , Params::getParam ( 'header_logo_text' ) );
                tfc_setPref ( 'header_logo_icon' , Params::getParam ( 'header_logo_icon' ) );

                osc_add_flash_ok_message ( 'Header settings updated correctly' , 'admin' );
                osc_redirect_to ( tfcAdminDashboard::newInstance ()->getRouteAdminURL ( 'logo-uploader-shopclass' ) );
                break;

            case ('osclass_file_editor'):
                // Theme PHP file editor processing
                $editfilename = Params::getParam ( 'theme_editor_filename' );
                $editdirectory = urldecode ( Params::getParam ( 'theme_editor_directory' , false , false ) );
                $headphpfile = $editdirectory . $editfilename;
                $headphpcontent = Params::getParam ( 'tfc-edit' , false , false );

                $status = 0;
                //head.php
                if ( file_exists ( $headphpfile ) ) {
                    if ( is_writable ( $headphpfile ) && file_put_contents ( $headphpfile , $headphpcontent ) ) {
                        $status = 1;
                    }
                }
                if ( $status == 1 ) {
                    osc_add_flash_ok_message ( $headphpfile . ' updated successfully' , 'admin' );
                } else {
                    osc_add_flash_error_message ( 'There were a problem updating ' . $headphpfile , 'admin' );
                }
                osc_redirect_to ( tfcAdminDashboard::newInstance ()->getRouteAdminURL ( 'osclass-editor-shopclass' , array ( 'editdirectory' => urlencode ( $editdirectory ) , 'editfilename' => $editfilename ) ) );

                break;

            case ('slider_settings'):
                $enableslider = Params::getParam ( 'enable_slider' );
                tfc_setPref ( 'enable_slider' , ($enableslider ? '1' : '0') );

                osc_add_flash_ok_message ( 'Slider settings saved successfully' , 'admin' );
                osc_redirect_to ( tfcAdminDashboard::newInstance ()->getRouteAdminURL ( 'slider-shopclass' ) );
                break;

            case ('upload_logo'):
                //Logo upload processing

                $package = Params::getFiles ( 'logo' );
                if ( $package[ 'error' ] == UPLOAD_ERR_OK ) {
                    if ( move_uploaded_file ( $package[ 'tmp_name' ] , WebThemes::newInstance ()->getCurrentThemePath () . "assets/images/logo.png" ) ) {
                        osc_add_flash_ok_message ( 'The logo image has been uploaded correctly'  , 'admin' );
                    } else {
                        osc_add_flash_error_message ( 'An error has occurred, please try again'  , 'admin' );
                    }
                } else {
                    osc_add_flash_error_message ( 'An error has occurred, please try again' , 'admin' );
                }
                osc_redirect_to ( tfcAdminDashboard::newInstance ()->getRouteAdminURL ( 'logo-uploader-shopclass' ) );

                break;

            case ('remove'):
                if ( file_exists ( WebThemes::newInstance ()->getCurrentThemePath () . "assets/images/logo.png" ) ) {
                    @unlink ( WebThemes::newInstance ()->getCurrentThemePath () . "assets/images/logo.png" );
                    osc_add_flash_ok_message ( 'The logo image has been removed' , 'admin' );
                } else {
                    osc_add_flash_error_message ( 'Image not found'  , 'admin' );
                }
                osc_redirect_to ( tfcAdminDashboard::newInstance ()->getRouteAdminURL ( 'logo-uploader-shopclass' ) );

                break;

            case ('tfc_upload_image'):
                //Logo upload processing
                $package = Params::getFiles ( 'tfcimage' );
                $upload_path = osc_uploads_path() . 'shopclass_slider/';
                $desired_name = 'background_' . uniqid () . '.jpg';

                if ( $package[ 'error' ] == UPLOAD_ERR_OK ) {

                    if ( move_uploaded_file ( $package[ 'tmp_name' ] , $upload_path . $desired_name ) ) {
                        osc_add_flash_ok_message ( 'Image has been uploaded correctly' , 'admin' );
                    } else {
                        osc_add_flash_error_message ( 'An error has occurred, please try again'  , 'admin' );
                    }
                } else {
                    osc_add_flash_error_message ( 'An error has occurred, please try again' , 'admin' );
                }
                osc_redirect_to ( tfcAdminDashboard::newInstance ()->getRouteAdminURL ( 'slider-shopclass' ) );

                break;

            case ('tfc_delete_image'):
                $image_name = Params::getParam ( 'image_name' );
                $image_path = osc_uploads_path() . 'shopclass_slider/' . $image_name;
	            if ( strpos( $image_name , 'home-try' ) === false ) {
		            @unlink( $image_path );
		            osc_add_flash_ok_message ( 'Image removed successfully.'  , 'admin' );
	            }
	            else {
		            osc_add_flash_warning_message ( 'You cannot remove this image, do it manually.'  , 'admin' );
	            }

                osc_redirect_to ( tfcAdminDashboard::newInstance ()->getRouteAdminURL ( 'slider-shopclass' ) );
                break;

            case ('tfc_sitemap'):
                //Sitemap configuration form processing
                $enabledcat = Params::getParam ( 'sitemap_categories' );
                $enabledcountries = Params::getParam ( 'sitemap_countries' );
                $enabledregions = Params::getParam ( 'sitemap_regions' );
                $enabledcities = Params::getParam ( 'sitemap_cities' );
                $enabledcatregion = Params::getParam ( 'sitemap_cat_regions' );
                $enabledcatcity = Params::getParam ( 'sitemap_cat_city' );

                osc_set_preference ( 'sitemap_categories' , ($enabledcat ? '1' : '0') , 'shopclass_theme' , 'STRING' );
                osc_set_preference ( 'sitemap_countries' , ($enabledcountries ? '1' : '0') , 'shopclass_theme' , 'STRING' );
                osc_set_preference ( 'sitemap_regions' , ($enabledregions ? '1' : '0') , 'shopclass_theme' , 'STRING' );
                osc_set_preference ( 'sitemap_cities' , ($enabledcities ? '1' : '0') , 'shopclass_theme' , 'STRING' );
                osc_set_preference ( 'sitemap_cat_regions' , ($enabledcatregion ? '1' : '0') , 'shopclass_theme' , 'STRING' );
                osc_set_preference ( 'sitemap_cat_city' , ($enabledcatcity ? '1' : '0') , 'shopclass_theme' , 'STRING' );
                osc_set_preference ( 'sitemap_number' , Params::getParam ( 'sitemap_number' ) , 'shopclass_theme' , 'STRING' );
                osc_set_preference ( 'sitemap_pages' , Params::getParam ( 'sitemap_pages' ) , 'shopclass_theme' , 'STRING' );

                osc_add_flash_ok_message ( 'Sitemap settings updated correctly' , 'admin' );
                osc_redirect_to ( tfcAdminDashboard::newInstance ()->getRouteAdminURL ( 'sitemap-shopclass' ) );
                break;

            case ('tfc_add_url_sitemap'):

                $lastmod = Params::getParam ( 'lastmod' );

                $url_array              = array ();
                $url_array[ 'url' ]     = Params::getParam ( 'sitemap_url' );
                $url_array[ 'freq' ]    = Params::getParam ( 'frequency' );
                $url_array[ 'lastmod' ] = $lastmod;


                if ( osc_get_preference ( 'custom_urls' , 'shopclass_theme' ) ) {
                    $custom_urls = json_decode ( osc_get_preference ( 'custom_urls' , 'shopclass_theme' ) , true );
                }
                $custom_urls[] = $url_array;

                osc_set_preference ( 'custom_urls' , json_encode ( $custom_urls ) , 'shopclass_theme' , 'STRING' );
                osc_add_flash_ok_message ( 'Your URL Added successfully' );
                osc_redirect_to ( tfcAdminDashboard::newInstance ()->getRouteAdminURL ( 'sitemap-shopclass' ) );
                break;

            case('robot_updater'):
                $robot_file = osc_base_path () . 'robots.txt';
                $edit_robot = Params::getParam ( 'edit_robot' , false , false );
                $status = false;
                if ( file_exists ( $robot_file ) ) {
                    if ( is_writable ( $robot_file ) && file_put_contents ( $robot_file , $edit_robot ) ) {
                        $status = 1;
                    }
                } else {
                    if ( is_writable ( osc_base_path () ) && file_put_contents ( $robot_file , $edit_robot ) ) {
                        $status = 1;
                    }
                }
                if ( $status == 1 ) {
                    osc_add_flash_ok_message ( __ ( 'Robots.txt updated successfully' , 'shopclass' ) , 'admin' );
                } else {
                    osc_add_flash_error_message ( __ ( 'We have problem updating robots.txt' , 'shopclass' ) , 'admin' );
                }
                osc_redirect_to ( tfcAdminDashboard::newInstance ()->getRouteAdminURL ( 'sitemap-shopclass' ) );
                exit;
                break;

            case ('security-settings'):

                $enabledrecaptcha = Params::getParam ( 'enable_recaptcha' );
                $sitekeyrecaptcha = Params::getParam ( 'tfc_recaptcha_site_key' );
                $secretkeyrecaptcha = Params::getParam ( 'tfc_recaptcha_secret_key' );

                $enabledhoneypot = Params::getParam ( 'enable_honeypot' );

                $duplicatehelper = Params::getParam ( 'duplicate_helper' );
                $duplicatemethod = Params::getParam ( 'duplicate_method' );


                tfc_setPref ( 'enable_recaptcha' , ($enabledrecaptcha ? '1' : '0') );
                tfc_setPref ( 'tfc_recaptcha_site_key' , $sitekeyrecaptcha );
                tfc_setPref ( 'tfc_recaptcha_secret_key' , $secretkeyrecaptcha );

                tfc_setPref ( 'enable_honeypot' , ($enabledhoneypot ? '1' : '0') );

                tfc_setPref ( 'duplicate_helper' , ($duplicatehelper ? '1' : '0') );
                tfc_setPref ( 'duplicate_method' , $duplicatemethod );


                osc_add_flash_ok_message ( __ ( 'Your settings saved successfully' , 'shopclass' ) , 'admin' );
                osc_redirect_to ( tfcAdminDashboard::newInstance ()->getRouteAdminURL ( 'spam-security-shopclass' ) );
                exit;

            case ('tfc_category_image'):

                $categoryId = Params::getParam ( 'image_category' );
                $upload_path = osc_uploads_path() . "categorypics/";
                $desired_name = $categoryId . '.jpg';

                $imgData = $_FILES[ 'tfcimagecat' ][ 'tmp_name' ]; // Get the File Data).

                // Check Path is writable.
                if ( !is_writable ( $upload_path ) ) {
                    osc_add_flash_warning_message ( __ ( 'Directory is not writable.' , 'shopclass' ) , 'admin' );
                    osc_redirect_to ( tfcAdminDashboard::newInstance ()->getRouteAdminURL ( 'category-image-shopclass' ) );
                    break;
                }

                $tfcImg = new tfcImageManager();
                $tfcImg->setImageData ( $imgData );
                $tfcImg->setMaxSize ();
                $tfcImg->setImageHeight ( '375' );
                $tfcImg->setImageWidth ( '500' );
                $tfcImg->setUploadName ( $desired_name );
                $tfcImg->setUploadPath ( $upload_path );
                $upload = $tfcImg->processUpload (true);


                // Upload the file to your specified path.
                if ( $upload ) {
                    //Upload Successfull now updating database.
                    osc_add_flash_ok_message ( __ ( 'Image Uploaded Successfully.' , 'shopclass' ) , 'admin' );
                    osc_redirect_to ( tfcAdminDashboard::newInstance ()->getRouteAdminURL ( 'category-image-shopclass' ) );
                    break;

                } else {
                    osc_redirect_to ( tfcAdminDashboard::newInstance ()->getRouteAdminURL ( 'category-image-shopclass' ) );
                    break;
                }

            case ('tfc_flush_all_cache'):
                $flushCache = new tfcCache();
                $flushCache->flush ();
                osc_add_flash_ok_message ( 'All Cache is flushed.' , 'admin' );
                osc_redirect_to ( tfcAdminDashboard::newInstance ()->getRouteAdminURL ( 'settings-shopclass' ) );
                break;
        }

    }


    function theme_shopclass_actions ()
    {
        if (osc_is_web_user_logged_in ()) {
            switch (Params::getParam ( 'shopclass_specific' )) {
                case ('upload_avatar'):
                    $userId = osc_logged_user_id ();

                    if ( Params::getParam ( 'social_upload' ) ){
                        $authorizer = Params::getParam ( 'social_upload' );
                        $socialId = tfc_socialData::newInstance ()->is_user_connected (osc_logged_user_id (),$authorizer);
                        if ($socialId) {
                            $picUrl = tfc_socialData::newInstance ()->get_social_pic ( $socialId , $authorizer );
                            if ( $picUrl ) {
                                $tempImgData = tfc_curl_get_content ( $picUrl );
                                $tempFileName = osc_uploads_path () . '/user_avatar/avatar_' . $userId . md5 ( osc_logged_user_email () ) . '.jpg';
                                file_put_contents ( $tempFileName , $tempImgData );
                                $imgData = $tempFileName;
                            } else {
                                osc_add_flash_info_message ( __ ( 'No Picture Found. Please reconnect your Social Profile.' , 'shopclass' ) );
                                osc_redirect_to ( osc_user_profile_url () );
                                break;

                            }
                        }

                    }

                    else {
                        $imgData = $_FILES[ 'useravatar' ][ 'tmp_name' ]; // Get the File.
                    }
                    if ( isset( $imgData ) ) {
                        tfc_user_avatar_process ( $imgData , $userId );
                        if (isset($tempFileName)){
                            @unlink($tempFileName);
                        }
                        osc_redirect_to ( osc_user_profile_url () );
                    }
                    break;
                case ('delete_avatar'): // Delete Avatar
                    $user_id = osc_logged_user_id ();
                    $result = tfcUserAvatar::newInstance ()->is_user_has_avatar ( $user_id );
                    @unlink ( tfc_avatar_upload_path () . $result[ 'avatar_ext' ] );
                    tfcUserAvatar::newInstance ()->delete_user_avatar ( $user_id );
                    osc_add_flash_info_message ( __ ( 'Avatar Deleted Successfully.' , 'shopclass' ) );
                    osc_redirect_to ( osc_user_profile_url () );
                    break;
            }
        }
    }


    osc_add_hook ( 'init_admin' , 'theme_shopclass_actions_admin' );
    osc_add_hook ( 'init' , 'theme_shopclass_actions' );