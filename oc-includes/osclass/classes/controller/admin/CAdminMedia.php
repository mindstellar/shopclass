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
 * Class CAdminMedia
 */
class CAdminMedia extends AdminSecBaseModel
{
    private $resourcesManager;

    public function __construct()
    {
        parent::__construct();

        //specific things for this class
        $this->resourcesManager = ItemResource::newInstance();
        osc_run_hook('init_admin_media');
    }

    //Business Layer...
    public function doModel()
    {
        parent::doModel();

        //specific things for this class
        switch ($this->action) {
            case ('bulk_actions'):
                osc_csrf_check();
                switch (Params::getParam('bulk_actions')) {
                    case 'delete':
                        $ids = Params::getParam('id');
                        if (is_array($ids)) {
                            foreach ($ids as $id) {
                                osc_deleteResource($id, true);
                            }
                            $log_ids = substr(implode(',', $ids), 0, 250);
                            Log::newInstance()
                                ->insertLog('media', 'delete bulk', $log_ids, $log_ids, 'admin', osc_logged_admin_id());
                            $this->resourcesManager->deleteResourcesIds($ids);
                        }
                        osc_add_flash_ok_message(_m('Resource deleted'), 'admin');
                        break;
                    default:
                        if (Params::getParam('bulk_actions') != '') {
                            osc_run_hook('media_bulk_' . Params::getParam('bulk_actions'), Params::getParam('id'));
                        }

                        break;
                }
                $this->redirectTo(osc_admin_base_url(true) . '?page=media');
                break;
            case ('delete'):
                osc_csrf_check();
                $ids = Params::getParam('id');
                if (is_array($ids)) {
                    foreach ($ids as $id) {
                        osc_deleteResource($id, true);
                    }
                    $log_ids = substr(implode(',', $ids), 0, 250);
                    Log::newInstance()
                        ->insertLog('media', 'delete', $log_ids, $log_ids, 'admin', osc_logged_admin_id());
                    $this->resourcesManager->deleteResourcesIds($ids);
                }
                osc_add_flash_ok_message(_m('Resource deleted'), 'admin');
                $this->redirectTo(osc_admin_base_url(true) . '?page=media');
                break;
            default:
                require_once osc_lib_path() . 'osclass/classes/datatables/MediaDataTable.php';

                // set default iDisplayLength
                if (Params::getParam('iDisplayLength') != '') {
                    Cookie::newInstance()->push('listing_iDisplayLength', Params::getParam('iDisplayLength'));
                    Cookie::newInstance()->set();
                } elseif (Cookie::newInstance()->get_value('listing_iDisplayLength') != '') {
                    Params::setParam('iDisplayLength', Cookie::newInstance()->get_value('listing_iDisplayLength'));
                } else {
                    Params::setParam('iDisplayLength', 10);
                }
                $this->_exportVariableToView('iDisplayLength', Params::getParam('iDisplayLength'));

                // Table header order by related
                if (Params::getParam('sort') == '') {
                    Params::setParam('sort', 'date');
                }
                if (Params::getParam('direction') == '') {
                    Params::setParam('direction', 'desc');
                }

                $page = (int)Params::getParam('iPage');
                if ($page == 0) {
                    $page = 1;
                }
                Params::setParam('iPage', $page);

                $params = Params::getParamsAsArray();

                $mediaDataTable = new MediaDataTable();
                $mediaDataTable->table($params);
                $aData = $mediaDataTable->getData();

                if (count($aData['aRows']) == 0 && $page != 1) {
                    $total   = (int)$aData['iTotalDisplayRecords'];
                    $maxPage = ceil($total / (int)$aData['iDisplayLength']);

                    $url = osc_admin_base_url(true) . '?' . Params::getServerParam('QUERY_STRING', false, false);

                    if ($maxPage == 0) {
                        $url = preg_replace('/&iPage=(\d)+/', '&iPage=1', $url);
                        $this->redirectTo($url);
                    }

                    if ($page > 1) {
                        $url = preg_replace('/&iPage=(\d)+/', '&iPage=' . $maxPage, $url);
                        $this->redirectTo($url);
                    }
                }


                $this->_exportVariableToView('aData', $aData);
                $this->_exportVariableToView('aRawRows', $mediaDataTable->rawRows());

                $bulk_options = array(
                    array('value' => '', 'data-dialog-content' => '', 'label' => __('Bulk actions')),
                    array(
                        'value'               => 'delete',
                        'data-dialog-content' => sprintf(
                            __('Are you sure you want to %s the selected media files?'),
                            strtolower(__('Delete'))
                        ),
                        'label'               => __('Delete')
                    )
                );
                $bulk_options = osc_apply_filter('media_bulk_filter', $bulk_options);
                $this->_exportVariableToView('bulk_options', $bulk_options);

                $this->doView('media/index.php');
                break;
        }
    }

    //hopefully generic...

}

/* file end: ./oc-admin/CAdminSettingsMedia.php */
