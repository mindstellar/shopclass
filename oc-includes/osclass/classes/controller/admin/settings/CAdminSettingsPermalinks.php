<?php

if (!defined('ABS_PATH')) {
    exit('ABS_PATH is not loaded. Direct access is not allowed.');
}

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

/**
 * Class CAdminSettingsPermalinks
 */
class CAdminSettingsPermalinks extends AdminSecBaseModel
{
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_settings_permalinks');
    }

    //Business Layer...
    public function doModel()
    {
        switch ($this->action) {
            case ('permalinks'):
                // calling the permalinks view
                $htaccess = Params::getParam('htaccess_status');
                $file     = Params::getParam('file_status');

                $this->_exportVariableToView('htaccess', $htaccess);
                $this->_exportVariableToView('file', $file);

                $this->doView('settings/permalinks.php');
                break;
            case ('permalinks_post'):
                // updating permalinks option
                osc_csrf_check();
                $htaccess_file  = osc_base_path() . '.htaccess';
                $rewriteEnabled = (Params::getParam('rewrite_enabled') ? true : false);

                $rewrite_base = REL_WEB_URL;
                $htaccess     = <<<HTACCESS
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase {$rewrite_base}
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . {$rewrite_base}index.php [L]
</IfModule>
<IfModule mod_mime.c>
AddType text/xsl .xsl
</IfModule>
HTACCESS;

                if ($rewriteEnabled) {
                    osc_set_preference('rewriteEnabled', '1');

                    // 1. OK (ok)
                    // 2. OK no apache module detected (warning)
                    // 3. No se puede crear + apache
                    // 4. No se puede crear + no apache
                    // 5. .htaccess exists, no overwrite
                    $status = 3;
                    if (file_exists($htaccess_file)) {
                        $status = 5;
                    } elseif (is_writable(osc_base_path()) && file_put_contents($htaccess_file, $htaccess)) {
                        $status = 1;
                    }

                    if (!@apache_mod_loaded('mod_rewrite')) {
                        $status++;
                    }

                    $errors   = 0;
                    $item_url = substr(str_replace('//', '/', Params::getParam('rewrite_item_url') . '/'), 0, -1);
                    if (!osc_validate_text($item_url)) {
                        ++$errors;
                        // Fall back to the last valid structure — $item_url feeds the item
                        // rewrite rule built further down, and an empty value there generates a
                        // greedy catch-all (^.*/.*$) that hijacks every URL, admin included.
                        $item_url = osc_get_preference('rewrite_item_url');
                    } elseif (stripos($item_url, '{ITEM_ID}') === false) {
                        // The router resolves a listing by the id captured from its URL (the
                        // {ITEM_ID} -> ([0-9]+) rule built further down), so a structure without
                        // it makes every listing 404. Reject it, keep the working value, and use
                        // that value for the rule below so it stays anchored on ([0-9]+). (#355)
                        ++$errors;
                        osc_add_flash_error_message(
                            _m('The listing permalink structure must include {ITEM_ID}; it was left unchanged.'),
                            'admin'
                        );
                        $item_url = osc_get_preference('rewrite_item_url');
                    } else {
                        osc_set_preference('rewrite_item_url', $item_url);
                    }
                    $page_url = substr(str_replace('//', '/', Params::getParam('rewrite_page_url') . '/'), 0, -1);
                    if (!osc_validate_text($page_url)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_page_url', $page_url);
                    }
                    $cat_url = substr(str_replace('//', '/', Params::getParam('rewrite_cat_url') . '/'), 0, -1);
                    // DEPRECATED: backward compatibility, remove in 3.4
                    $cat_url = str_replace('{CATEGORY_SLUG}', '{CATEGORY_NAME}', $cat_url);
                    if (!osc_validate_text($cat_url)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_cat_url', $cat_url);
                    }
                    $search_url = substr(str_replace('//', '/', Params::getParam('rewrite_search_url') . '/'), 0, -1);
                    if (!osc_validate_text($search_url)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_search_url', $search_url);
                    }

                    if (!osc_validate_text(Params::getParam('rewrite_search_country'))) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_search_country', Params::getParam('rewrite_search_country'));
                    }
                    if (!osc_validate_text(Params::getParam('rewrite_search_region'))) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_search_region', Params::getParam('rewrite_search_region'));
                    }
                    if (!osc_validate_text(Params::getParam('rewrite_search_city'))) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_search_city', Params::getParam('rewrite_search_city'));
                    }
                    if (!osc_validate_text(Params::getParam('rewrite_search_city_area'))) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_search_city_area', Params::getParam('rewrite_search_city_area'));
                    }
                    if (!osc_validate_text(Params::getParam('rewrite_search_category'))) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_search_category', Params::getParam('rewrite_search_category'));
                    }
                    if (!osc_validate_text(Params::getParam('rewrite_search_user'))) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_search_user', Params::getParam('rewrite_search_user'));
                    }
                    if (!osc_validate_text(Params::getParam('rewrite_search_pattern'))) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_search_pattern', Params::getParam('rewrite_search_pattern'));
                    }

                    $rewrite_contact = substr(str_replace('//', '/', Params::getParam('rewrite_contact') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_contact)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_contact', $rewrite_contact);
                    }
                    $rewrite_feed = substr(str_replace('//', '/', Params::getParam('rewrite_feed') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_feed)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_feed', $rewrite_feed);
                    }
                    $rewrite_language =
                        substr(str_replace('//', '/', Params::getParam('rewrite_language') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_language)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_language', $rewrite_language);
                    }
                    $rewrite_item_mark =
                        substr(str_replace('//', '/', Params::getParam('rewrite_item_mark') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_item_mark)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_item_mark', $rewrite_item_mark);
                    }
                    $rewrite_item_send_friend =
                        substr(str_replace('//', '/', Params::getParam('rewrite_item_send_friend') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_item_send_friend)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_item_send_friend', $rewrite_item_send_friend);
                    }
                    $rewrite_item_contact =
                        substr(str_replace('//', '/', Params::getParam('rewrite_item_contact') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_item_contact)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_item_contact', $rewrite_item_contact);
                    }
                    $rewrite_item_new =
                        substr(str_replace('//', '/', Params::getParam('rewrite_item_new') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_item_new)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_item_new', $rewrite_item_new);
                    }
                    $rewrite_item_activate =
                        substr(str_replace('//', '/', Params::getParam('rewrite_item_activate') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_item_activate)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_item_activate', $rewrite_item_activate);
                    }
                    $rewrite_item_edit =
                        substr(str_replace('//', '/', Params::getParam('rewrite_item_edit') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_item_edit)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_item_edit', $rewrite_item_edit);
                    }
                    $rewrite_item_delete =
                        substr(str_replace('//', '/', Params::getParam('rewrite_item_delete') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_item_delete)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_item_delete', $rewrite_item_delete);
                    }
                    $rewrite_item_resource_delete =
                        substr(str_replace('//', '/', Params::getParam('rewrite_item_resource_delete') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_item_resource_delete)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_item_resource_delete', $rewrite_item_resource_delete);
                    }
                    $rewrite_user_login =
                        substr(str_replace('//', '/', Params::getParam('rewrite_user_login') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_user_login)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_user_login', $rewrite_user_login);
                    }
                    $rewrite_user_dashboard =
                        substr(str_replace('//', '/', Params::getParam('rewrite_user_dashboard') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_user_dashboard)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_user_dashboard', $rewrite_user_dashboard);
                    }
                    $rewrite_user_logout =
                        substr(str_replace('//', '/', Params::getParam('rewrite_user_logout') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_user_logout)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_user_logout', $rewrite_user_logout);
                    }
                    $rewrite_user_register =
                        substr(str_replace('//', '/', Params::getParam('rewrite_user_register') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_user_register)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_user_register', $rewrite_user_register);
                    }
                    $rewrite_user_activate =
                        substr(str_replace('//', '/', Params::getParam('rewrite_user_activate') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_user_activate)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_user_activate', $rewrite_user_activate);
                    }
                    $rewrite_user_activate_alert =
                        substr(str_replace('//', '/', Params::getParam('rewrite_user_activate_alert') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_user_activate_alert)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_user_activate_alert', $rewrite_user_activate_alert);
                    }
                    $rewrite_user_profile =
                        substr(str_replace('//', '/', Params::getParam('rewrite_user_profile') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_user_profile)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_user_profile', $rewrite_user_profile);
                    }
                    $rewrite_user_items =
                        substr(str_replace('//', '/', Params::getParam('rewrite_user_items') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_user_items)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_user_items', $rewrite_user_items);
                    }
                    $rewrite_user_alerts =
                        substr(str_replace('//', '/', Params::getParam('rewrite_user_alerts') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_user_alerts)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_user_alerts', $rewrite_user_alerts);
                    }
                    $rewrite_user_recover =
                        substr(str_replace('//', '/', Params::getParam('rewrite_user_recover') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_user_recover)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_user_recover', $rewrite_user_recover);
                    }
                    $rewrite_user_forgot =
                        substr(str_replace('//', '/', Params::getParam('rewrite_user_forgot') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_user_forgot)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_user_forgot', $rewrite_user_forgot);
                    }
                    $rewrite_user_change_password =
                        substr(str_replace('//', '/', Params::getParam('rewrite_user_change_password') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_user_change_password)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_user_change_password', $rewrite_user_change_password);
                    }
                    $rewrite_user_change_email =
                        substr(str_replace('//', '/', Params::getParam('rewrite_user_change_email') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_user_change_email)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_user_change_email', $rewrite_user_change_email);
                    }
                    $rewrite_user_change_username =
                        substr(str_replace('//', '/', Params::getParam('rewrite_user_change_username') . '/'), 0, -1);
                    if (!osc_validate_text($rewrite_user_change_username)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_user_change_username', $rewrite_user_change_username);
                    }
                    $rewrite_user_change_email_confirm =
                        substr(
                            str_replace('//', '/', Params::getParam('rewrite_user_change_email_confirm') . '/'),
                            0,
                            -1
                        );
                    if (!osc_validate_text($rewrite_user_change_email_confirm)) {
                        ++$errors;
                    } else {
                        osc_set_preference('rewrite_user_change_email_confirm', $rewrite_user_change_email_confirm);
                    }

                    osc_reset_preferences();

                    // Rebuild the cached rule table from the just-saved permalink
                    // preferences. buildRules() fires the before/after_rewrite_rules
                    // hooks and stamps the cache version, so live requests pick the
                    // new structure up immediately.
                    Rewrite::newInstance()->rebuildAndPersistRules();

                    osc_set_preference('seo_url_search_prefix', rtrim(Params::getParam('seo_url_search_prefix'), '/'));

                    $msg_error =
                        '<br/>' . _m('All fields are required.') . ' ' . sprintf(_mn(
                            'One field was not updated',
                            '%s fields were not updated',
                            $errors
                        ), $errors);
                    switch ($status) {
                        case 1:
                            $msg = _m('Permalinks structure updated');
                            if ($errors > 0) {
                                $msg .= $msg_error;
                                osc_add_flash_warning_message($msg, 'admin');
                            } else {
                                osc_add_flash_ok_message($msg, 'admin');
                            }
                            break;
                        case 2:
                            $msg = _m('Permalinks structure updated.');
                            $msg .= ' ';
                            $msg .= _m("However, we can't check if Apache module <b>mod_rewrite</b> is loaded. If you experience some problems with the URLs, you should deactivate <em>Friendly URLs</em>");
                            if ($errors > 0) {
                                $msg .= $msg_error;
                            }
                            osc_add_flash_warning_message($msg, 'admin');
                            break;
                        case 3:
                            $msg = _m("File <b>.htaccess</b> couldn't be filled out with the right content.");
                            $msg .= ' ';
                            $msg .= _m("Here's the content you have to add to the <b>.htaccess</b> file. If you can't create the file, please deactivate the <em>Friendly URLs</em> option.");
                            $msg .= '</p><pre>' . htmlentities($htaccess, ENT_COMPAT, 'UTF-8') . '</pre><p>';
                            if ($errors > 0) {
                                $msg .= $msg_error;
                            }
                            osc_add_flash_error_message($msg, 'admin');
                            break;
                        case 4:
                            $msg = _m("File <b>.htaccess</b> couldn't be filled out with the right content.");
                            $msg .= ' ';
                            $msg .= _m("Here's the content you have to add to the <b>.htaccess</b> file. If you can't create the file or experience some problems with the URLs, please deactivate the <em>Friendly URLs</em> option.");
                            $msg .= '</p><pre>' . htmlentities($htaccess, ENT_COMPAT, 'UTF-8') . '</pre><p>';
                            if ($errors > 0) {
                                $msg .= $msg_error;
                            }
                            osc_add_flash_error_message($msg, 'admin');
                            break;
                        case 5:
                            $warning = false;
                            if (file_exists($htaccess_file)) {
                                $htaccess_content = file_get_contents($htaccess_file);
                                if ($htaccess_content != $htaccess) {
                                    $msg     = _m('File <b>.htaccess</b> already exists and was not modified.');
                                    $msg     .= ' ';
                                    $msg     .= _m("Here's the content you have to add to the <b>.htaccess</b> file. If you can't modify the file or experience some problems with the URLs, please deactivate the <em>Friendly URLs</em> option.");
                                    $msg     .= '</p><pre>' . htmlentities($htaccess, ENT_COMPAT, 'UTF-8')
                                        . '</pre><p>';
                                    $warning = true;
                                } else {
                                    $msg = _m('Permalinks structure updated');
                                }
                            }
                            if ($errors > 0) {
                                $msg .= $msg_error;
                            }
                            if ($errors > 0 || $warning) {
                                osc_add_flash_warning_message($msg, 'admin');
                            } else {
                                osc_add_flash_ok_message($msg, 'admin');
                            }
                            break;
                    }
                } else {
                    osc_set_preference('rewriteEnabled', 0);
                    osc_set_preference('mod_rewrite_loaded', 0);

                    $deleted = true;
                    if (file_exists($htaccess_file)) {
                        $htaccess_content = file_get_contents($htaccess_file);
                        if ($htaccess_content == $htaccess) {
                            $deleted      = @unlink($htaccess_file);
                            $same_content = true;
                        } else {
                            $deleted      = false;
                            $same_content = false;
                        }
                    }
                    if ($deleted) {
                        osc_add_flash_ok_message(_m('Friendly URLs successfully deactivated'), 'admin');
                    } elseif ($same_content) {
                        osc_add_flash_warning_message(
                            _m('Friendly URLs deactivated, but .htaccess file could not be deleted. Please, remove it manually'),
                            'admin'
                        );
                    } else {
                        osc_add_flash_warning_message(
                            _m('Friendly URLs deactivated, but .htaccess file was modified outside Shopclass and was not deleted'),
                            'admin'
                        );
                    }
                }

                $this->redirectTo(osc_admin_base_url(true) . '?page=settings&action=permalinks');
                break;
        }
    }
}

// EOF: ./oc-admin/controller/settings/CAdminSettingsPermalinks.php
