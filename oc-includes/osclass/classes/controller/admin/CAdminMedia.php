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
                $data    = $this->getLibraryData($type, $iPage, $perPage);

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
        $valid = array_merge(array('all', 'item'), $this->getResourceOwnerTypes());

        return in_array($type, $valid, true) ? $type : 'all';
    }

    /**
     * The distinct, well-formed owner types currently stored in t_resource.
     *
     * @return string[]
     */
    private function getResourceOwnerTypes()
    {
        $rows = osc_db_select(
            'SELECT DISTINCT s_owner_type FROM ' . DB_TABLE_PREFIX . 't_resource ORDER BY s_owner_type'
        );
        $out = array();
        foreach ($rows as $row) {
            if (\mindstellar\model\Resource::isValidOwnerType((string) $row['s_owner_type'])) {
                $out[] = (string) $row['s_owner_type'];
            }
        }

        return $out;
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
        foreach ($this->getResourceOwnerTypes() as $ownerType) {
            $filters[] = array(
                'type'  => $ownerType,
                'label' => $labels[$ownerType] ?? ucfirst($ownerType),
            );
        }

        return $filters;
    }

    /**
     * A page of normalised media rows plus the total for the filter. Item images
     * (t_item_resource) and every other resource (t_resource) are projected onto
     * one shape so the library shows them side by side. t_item_resource is left
     * as the source of truth for listing images — this only unifies the view.
     *
     * @return array{rows:array<int,array>,total:int}
     */
    private function getLibraryData($type, $iPage, $perPage)
    {
        $itemT  = DB_TABLE_PREFIX . 't_item_resource';
        $resT   = DB_TABLE_PREFIX . 't_resource';
        $offset = ($iPage - 1) * $perPage;

        $itemSel = "SELECT 'item' AS src, pk_i_id AS id, fk_i_item_id AS owner_id, 'item' AS owner_type,"
            . " s_name, s_extension, s_content_type, s_path, s_storage, NULL AS dt FROM $itemT";
        $resSel  = "SELECT 'resource' AS src, pk_i_id AS id, i_owner_id AS owner_id, s_owner_type AS owner_type,"
            . " s_name, s_extension, s_content_type, s_path, s_storage, dt_created AS dt FROM $resT";

        $params = array();
        if ($type === 'item') {
            $base = $itemSel;
        } elseif ($type === 'all') {
            $base = "($itemSel) UNION ALL ($resSel)";
        } else {
            $base     = $resSel . ' WHERE s_owner_type = ?';
            $params[] = $type;
        }

        $total = (int) osc_db_scalar("SELECT COUNT(*) FROM ($base) AS m", $params);
        $rows  = osc_db_select(
            "SELECT * FROM ($base) AS m ORDER BY (dt IS NULL), dt DESC, id DESC"
            . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset,
            $params
        );

        return array('rows' => $rows, 'total' => $total);
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
