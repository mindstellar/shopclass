<?php if (!defined('ABS_PATH')) {
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
 * Class CAdminTools
 */
class CAdminTools extends AdminSecBaseModel
{
    public function __construct()
    {
        parent::__construct();
        osc_run_hook('init_admin_tools');
    }

    //Business Layer...
    public function doModel()
    {
        parent::doModel();

        switch ($this->action) {
            case ('import'):         // calling import view
                $this->doView('tools/import.php');
                break;
            case ('import_post'):
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m('This action cannot be done because it is a demo site'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=import');
                }
                // calling
                osc_csrf_check();
                $sql = Params::getFiles('sql');
                if (isset($sql['size']) && $sql['size'] != 0) {
                    $content_file = file_get_contents($sql['tmp_name']);

                    $conn = DBConnectionClass::newInstance();
                    $c_db = $conn->getOsclassDb();
                    $comm = new DBCommandClass($c_db);
                    if ($comm->importSQL($content_file)) {
                        osc_calculate_location_slug(osc_subdomain_type());
                        osc_add_flash_ok_message(_m('Import complete'), 'admin');
                    } else {
                        osc_add_flash_error_message(_m('There was a problem importing data to the database'), 'admin');
                    }
                } else {
                    osc_add_flash_warning_message(_m('No file was uploaded'), 'admin');
                }
                @unlink($sql['tmp_name']);
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=import');
                break;
            case ('category'):
                $this->doView('tools/category.php');
                break;
            case ('category_post'):
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m('This action cannot be done because it is a demo site'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=category');
                }
                osc_update_cat_stats();
                osc_add_flash_ok_message(_m('Recount category stats has been successful'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=category');
                break;
            case ('locations'):
                $this->doView('tools/locations.php');
                break;
            case ('locations_post'):
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m('This action cannot be done because it is a demo site'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=locations');
                }

                osc_update_location_stats(true);

                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=locations');
                break;
            case ('upgrade'):
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m('This action cannot be done because it is a demo site'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true));
                }
                $this->doView('tools/upgrade.php');
                break;
            case 'version':
                $this->doView('tools/version.php');
                break;
            case ('backup'):
            case ('backup_post'):
                $this->doView('tools/backup.php');
                break;
            case ('backup-sql'):
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m('This action cannot be done because it is a demo site'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=backup');
                }
                osc_csrf_check();
                //databasse dump...
                if (Params::getParam('bck_dir') != '') {
                    $path = trim(Params::getParam('bck_dir'));
                    if (substr($path, -1, 1) !== '/') {
                        $path .= '/';
                    }
                } else {
                    $path = osc_base_path();
                }
                $filename = 'Osclass_mysqlbackup.' . date('YmdHis') . '.sql';

                switch (osc_dbdump($path, $filename)) {
                    case (-1):
                        $msg = _m('Path is empty');
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    case (-2):
                        $msg = sprintf(_m('Could not connect with the database'));
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    case (-3):
                        $msg = _m('There are no tables to back up');
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    case (-4):
                        $msg = _m('The folder is not writable');
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    default:
                        $msg = _m('Backup completed successfully');
                        osc_add_flash_ok_message($msg, 'admin');
                        break;
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=backup');
                break;
            case ('backup-sql_file'):
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m('This action cannot be done because it is a demo site'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=backup');
                }
                //databasse dump...

                $filename = 'Osclass_mysqlbackup.' . date('YmdHis') . '.sql';
                $path     = sys_get_temp_dir() . '/';

                switch (osc_dbdump($path, $filename)) {
                    case (-1):
                        $msg = _m('Path is empty');
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    case (-2):
                        $msg = sprintf(_m('Could not connect with the database. Error: %s'), DBConnectionClass::newInstance()
                                                                                                              ->getOsclassDb()->connect_error);
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    case (-3):
                        $msg = sprintf(_m('Could not select the database. Error: %s'), DBConnectionClass::newInstance()
                                                                                                        ->getOsclassDb()->error);
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    case (-4):
                        $msg = _m('There are no tables to back up');
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    case (-5):
                        $msg = _m('The folder is not writable');
                        osc_add_flash_error_message($msg, 'admin');
                        break;
                    default:
                        $msg = _m('Backup completed successfully');
                        osc_add_flash_ok_message($msg, 'admin');
                        header('Content-Description: File Transfer');
                        header('Content-Type: application/octet-stream');
                        header('Content-Disposition: attachment; filename=' . basename($filename));
                        header('Content-Transfer-Encoding: binary');
                        header('Expires: 0');
                        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                        header('Pragma: public');
                        header('Content-Length: ' . filesize($path . $filename));
                        flush();
                        readfile($path . $filename);
                        exit;
                        break;
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=backup');
                break;
            case ('backup-zip_file'):
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m('This action cannot be done because it is a demo site'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=backup');
                }
                $filename = 'Osclass_backup.' . date('YmdHis') . '.zip';
                $path     = sys_get_temp_dir() . '/';

                if (osc_zip_folder(osc_base_path(), $path . $filename)) {
                    $msg = _m('Archived successfully!');
                    osc_add_flash_ok_message($msg, 'admin');
                    header('Content-Description: File Transfer');
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename=' . basename($filename));
                    header('Content-Transfer-Encoding: binary');
                    header('Expires: 0');
                    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                    header('Pragma: public');
                    header('Content-Length: ' . filesize($path . $filename));
                    flush();
                    readfile($path . $filename);
                    exit;
                }

                $msg = _m('Error, the zip file was not created in the specified directory');
                osc_add_flash_error_message($msg, 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=backup');
                break;
            case ('backup-zip'):
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m('This action cannot be done because it is a demo site'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=backup');
                }
                //zip of the code just to back it up
                osc_csrf_check();
                if (Params::getParam('bck_dir')) {
                    $archive_name = trim(Params::getParam('bck_dir'));
                    if (substr(trim($archive_name), -1, 1) !== '/') {
                        $archive_name .= '/';
                    }
                    $archive_name .= '/Osclass_backup.' . date('YmdHis') . '.zip';
                } else {
                    $archive_name = osc_base_path() . 'Osclass_backup.' . date('YmdHis') . '.zip';
                }
                $archive_folder = osc_base_path();

                if (osc_zip_folder($archive_folder, $archive_name)) {
                    $msg = _m('Archived successfully!');
                    osc_add_flash_ok_message($msg, 'admin');
                } else {
                    $msg = _m('Error, the zip file was not created in the specified directory');
                    osc_add_flash_error_message($msg, 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=backup');
                break;
            case ('maintenance'):
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m('This action cannot be done because it is a demo site'), 'admin');
                    $this->doView('tools/maintenance.php');
                    break;
                }
                $mode = Params::getParam('mode');
                if ($mode === 'on') {
                    osc_csrf_check();
                    $maintenance_file = osc_base_path() . '.maintenance';
                    $fileHandler      = @fopen($maintenance_file, 'wb');
                    if ($fileHandler) {
                        osc_add_flash_ok_message(_m('Maintenance mode is ON'), 'admin');
                    } else {
                        osc_add_flash_error_message(
                            _m('There was an error creating the .maintenance file, please create it manually at the root folder'),
                            'admin'
                        );
                    }
                    fclose($fileHandler);
                    $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=maintenance');
                } elseif ($mode === 'off') {
                    osc_csrf_check();
                    $deleted = @unlink(osc_base_path() . '.maintenance');
                    if ($deleted) {
                        osc_add_flash_ok_message(_m('Maintenance mode is OFF'), 'admin');
                    } else {
                        osc_add_flash_error_message(
                            _m('There was an error removing the .maintenance file, please remove it manually from the root folder'),
                            'admin'
                        );
                    }
                    $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=maintenance');
                }
                $this->doView('tools/maintenance.php');
                break;
            case 'cleanup':
                $this->doView('tools/cleanup.php');
                break;
            case 'cleanup_post':
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m('This action cannot be done because it is a demo site'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=cleanup');
                }
                osc_csrf_check();
                $limit = (int)Params::getParam('batch_limit');
                osc_set_preference('batch_limit', $limit > 0 ? $limit : 250, 'cleanup', 'INTEGER');
                foreach (Cleanup::RULES as $rule) {
                    osc_set_preference('enabled_' . $rule, Params::getParam('enabled_' . $rule) ? '1' : '0', 'cleanup', 'BOOLEAN');
                    if ($rule !== 'reported') {
                        $days = (int)Params::getParam('days_' . $rule);
                        osc_set_preference('days_' . $rule, $days > 0 ? $days : 30, 'cleanup', 'INTEGER');
                    }
                }
                osc_reset_preferences();
                osc_add_flash_ok_message(_m('Cleanup settings saved'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=cleanup');
                break;
            case 'cleanup_run':
                if (defined('DEMO')) {
                    osc_add_flash_warning_message(_m('This action cannot be done because it is a demo site'), 'admin');
                    $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=cleanup');
                }
                osc_csrf_check();
                $total = osc_run_cleanup();
                if ($total > 0) {
                    osc_add_flash_ok_message(sprintf(_m('Cleanup removed %d item(s). Run again to clear any remaining backlog.'), $total), 'admin');
                } else {
                    osc_add_flash_warning_message(_m('Cleanup ran, but nothing matched the enabled rules.'), 'admin');
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=tools&action=cleanup');
                break;
            case 'system_info':
            default:
                $this->doView('tools/system-info.php');
                break;
        }
    }

    //hopefully generic...
}

/* file end: ./oc-admin/CAdminTools.php */
