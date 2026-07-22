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
            case ('delete'):
                osc_csrf_check();
                $this->deleteMedia(Params::getParam('src'), (int) Params::getParam('id'));
                osc_add_flash_ok_message(_m('Resource deleted'), 'admin');
                $this->redirectTo($this->libraryUrl(Params::getParam('type')));
                break;
            default:
                $type    = $this->resolveType(Params::getParam('type'));
                $perPage = 24;
                $iPage   = max(1, (int) Params::getParam('iPage'));
                $data    = osc_media_library_query($type, $iPage, $perPage);

                // Snap a too-high page back to the last one with results.
                $maxPage = max(1, (int) ceil($data['total'] / $perPage));
                if ($iPage > $maxPage) {
                    $this->redirectTo($this->libraryUrl($type) . '&iPage=' . $maxPage);
                }

                $this->_exportVariableToView('mediaType', $type);
                $this->_exportVariableToView('mediaFilters', $this->getFilters());
                $this->_exportVariableToView('mediaRows', $data['rows']);
                $this->_exportVariableToView('mediaTotal', $data['total']);
                $this->_exportVariableToView('mediaPerPage', $perPage);
                $this->_exportVariableToView('mediaPage', $iPage);
                $this->doView('media/index.php');
                break;
        }
    }

    //hopefully generic...

    /**
     * URL of the media library, preserving the active type filter.
     */
    private function libraryUrl($type)
    {
        $url = osc_admin_base_url(true) . '?page=media';
        if ($type !== '' && $type !== null) {
            $url .= '&type=' . urlencode((string) $type);
        }

        return $url;
    }

    /**
     * The requested filter, or 'all' when it is not a known source. Valid values
     * are 'all', 'item' (listings) and each owner type present in t_resource.
     */
    private function resolveType($type)
    {
        $type  = (string) $type;
        $valid = array_merge(array("all", "item"), osc_media_owner_types());

        return in_array($type, $valid, true) ? $type : 'all';
    }

    /**
     * Filter pills for the library: All, Listings (item), then a pill per owner
     * type in t_resource (Users, Pages, or a plugin-defined type).
     *
     * @return array<int,array{type:string,label:string}>
     */
    private function getFilters()
    {
        $filters = array(
            array('type' => 'all', 'label' => __('All')),
            array('type' => 'item', 'label' => __('Listings')),
        );
        $labels = array('user' => __('Users'), 'page' => __('Pages'));
        foreach (osc_media_owner_types() as $ownerType) {
            $filters[] = array(
                'type'  => $ownerType,
                'label' => $labels[$ownerType] ?? ucfirst($ownerType),
            );
        }

        return $filters;
    }

    /**
     * Delete one media file through the right pipeline for its source, so files
     * (local or offloaded) and rows are both cleaned up: item images via the
     * legacy item-resource path, everything else via ResourceUploader.
     */
    private function deleteMedia($src, $id)
    {
        if ($id <= 0) {
            return;
        }

        if ($src === 'item') {
            osc_deleteResource($id, true);
            $this->resourcesManager->deleteResourcesIds(array($id));
            Log::newInstance()->insertLog('media', 'delete', (string) $id, (string) $id, 'admin', osc_logged_admin_id());
        } elseif ($src === 'resource') {
            $row = \mindstellar\model\Resource::newInstance()->findByPrimaryKey($id);
            if ($row !== null) {
                (new \mindstellar\storage\ResourceUploader())->delete($row);
                Log::newInstance()
                    ->insertLog('media', 'delete', (string) $id, (string) $id, 'admin', osc_logged_admin_id());
            }
        }
    }

}

/* file end: ./oc-admin/CAdminSettingsMedia.php */
