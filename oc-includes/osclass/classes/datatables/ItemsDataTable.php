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
 * ItemsDataTable class
 *
 * @since      3.1
 * @package    Shopclass
 * @subpackage classes
 * @author     Shopclass
 */
class ItemsDataTable extends DataTable
{
    /**
     * @var Search
     */
    private $mSearch;
    private $withFilters = false;

    public function __construct()
    {
        parent::__construct();
        osc_add_filter('datatable_listing_class', array(&$this, 'row_class'));
    }

    /**
     * @param $params
     *
     * @return array
     */
    public function table($params)
    {
        $this->addTableHeader();
        $this->mSearch = new Search(true);
        $this->getDBParams($params);

        osc_run_hook('manage_item_search_conditions', $this->mSearch);

        $this->processData(Item::newInstance()->extendCategoryName($this->mSearch->doSearch()));
        $this->totalFiltered = $this->mSearch->count();
        $this->total         = $this->mSearch->countAll();

        return $this->getData();
    }

    private function addTableHeader()
    {

        $arg_date = '&sort=date';
        if ((Params::getParam('sort') === 'date') && Params::getParam('direction') === 'desc') {
            $arg_date .= '&direction=asc';
        }
        $arg_expiration = '&sort=expiration';
        if ((Params::getParam('sort') === 'expiration') && Params::getParam('direction') === 'desc') {
            $arg_expiration .= '&direction=asc';
        }

        Rewrite::newInstance()->init();
        $page = (int)Params::getParam('iPage');
        if ($page == 0) {
            $page = 1;
        }
        Params::setParam('iPage', $page);
        $url_base = preg_replace(
            '|&direction=([^&]*)|',
            '',
            preg_replace('|&sort=([^&]*)|', '', osc_base_url() . Rewrite::newInstance()->get_raw_request_uri())
        );

        $this->addColumn('status-border', '');
        $this->addColumn('status', __('Status'));
        $this->addColumn('bulkactions', '<input id="check_all" type="checkbox" />');
        $this->addColumn('image', __('Image'));
        $this->addColumn('title', __('Title'));
        $this->addColumn('user', __('User'));
        $this->addColumn('category', __('Category'));
        $this->addColumn('location', __('Location'));
        $this->addColumn('date', '<a href="' . osc_esc_html($url_base . $arg_date) . '">' . __('Date') . '</a>');
        $this->addColumn(
            'expiration',
            '<a href="' . osc_esc_html($url_base . $arg_expiration) . '">' . __('Expiration date') . '</a>'
        );

        $dummy = &$this;
        osc_run_hook('admin_items_table', $dummy);
    }

    /**
     * @param $_get
     *
     */
    private function getDBParams($_get)
    {

        if (!isset($_get['iDisplayStart'])) {
            $_get['iDisplayStart'] = 0;
        }
        if (!isset($_get['iDisplayLength'])) {
            $_get['iDisplayLength'] = 10;
        }

        if (!is_numeric($_get['iPage']) || $_get['iPage'] < 1) {
            Params::setParam('iPage', 1);
            $this->iPage = 1;
        } else {
            $this->iPage = $_get['iPage'];
        }

        $withUserId    = false;
        $no_user_email = '';
        $sanitizer     = new Sanitize();
        // get & set values
        foreach ($_get as $k => $v) {
            if ($k === 'sSearch' && $v != '') {
                $this->mSearch->addPattern($v);
                $this->withFilters = true;
            }

            if ($k === 'userId' && $v != '') {
                $this->mSearch->fromUser($sanitizer->int($v));
                $this->withFilters = true;
                $withUserId        = true;
            }
            if ($k === 'itemId' && $v != '') {
                $this->mSearch->addItemId($sanitizer->int($v));
                $this->withFilters = true;
            }
            if ($k === 'countryId' && $v != '') {
                $this->mSearch->addCountry($v);
                $this->withFilters = true;
            }
            if ($k === 'regionId' && $v != '') {
                $this->mSearch->addRegion($v);
                $this->withFilters = true;
            }
            if ($k === 'cityId' && $v != '') {
                $this->mSearch->addCity($v);
                $this->withFilters = true;
            }
            if ($k === 'country' && $v != '') {
                $this->mSearch->addCountry($v);
                $this->withFilters = true;
            }
            if ($k === 'region' && $v != '') {
                $this->mSearch->addRegion($v);
                $this->withFilters = true;
            }

            if ($k === 'city' && $v != '') {
                $this->mSearch->addCity($v);
                $this->withFilters = true;
            }
            if ($k === 'catId' && $v != '') {
                $this->mSearch->addCategory($v);
                $this->withFilters = true;
            }
            if ($k === 'b_premium' && $v != '') {
                $this->mSearch->addItemConditions(DB_TABLE_PREFIX . 't_item.b_premium = ' . $sanitizer->int($v));
                $this->withFilters = true;
            }
            if ($k === 'b_active' && $v != '') {
                $this->mSearch->addItemConditions(DB_TABLE_PREFIX . 't_item.b_active = ' . $sanitizer->int($v));
                $this->withFilters = true;
            }
            if ($k === 'b_enabled' && $v != '') {
                $this->mSearch->addItemConditions(DB_TABLE_PREFIX . 't_item.b_enabled = ' . $sanitizer->int($v));
                $this->withFilters = true;
            }
            if ($k === 'b_spam' && $v != '') {
                $this->mSearch->addItemConditions(DB_TABLE_PREFIX . 't_item.b_spam = ' . $sanitizer->int($v));
                $this->withFilters = true;
            }
            if ($k === 'user' && $v != '') {
                $no_user_email = $v;
            }
        }

        // add no registered user email if userId == '' and $no_user_email != ''
        if ($no_user_email != '' && !$withUserId) {
            $this->mSearch->addContactEmail($no_user_email);
            $this->withFilters = true;
        }

        // set start and limit using iPage param
        $start = ($this->iPage - 1) * $_get['iDisplayLength'];

        $this->start = (int)$start;
        $this->limit = (int)$_get['iDisplayLength'];
        $this->mSearch->limit($this->start, $this->limit);

        $direction      = $_get['direction'];
        $arrayDirection = array('desc', 'asc');
        if (!in_array($direction, $arrayDirection)) {
            Params::setParam('direction', 'desc');
            $direction = 'desc';
        }

        // column sort
        $sort             = $_get['sort'];
        $arraySortColumns = array('date' => 'dt_pub_date', 'expiration' => 'dt_expiration');
        if (!array_key_exists($sort, $arraySortColumns)) {
            $sort = 'dt_pub_date';
        } else {
            $sort = $arraySortColumns[$sort];
        }
        // only some fields can be ordered
        $this->mSearch->order($sort, $direction);
    }

    /**
     * @param $items
     */
    private function processData($items)
    {
        if (!empty($items)) {
            $csrf_token_url = osc_csrf_token_url();
            foreach ($items as $aRow) {
                View::newInstance()->_exportVariableToView('item', $aRow);
                $row     = array();
                $options = array();
                // -- prepare data --
                // prepare item title
                $title = mb_substr($aRow['s_title'], 0, 30, 'UTF-8');
                if ($title != $aRow['s_title']) {
                    $title .= '...';
                }
                // Escape the user-controlled title before the trusted icon markup is
                // appended, so the span below is not escaped along with it.
                $title = osc_esc_html($title);

                // Decorative "opens in a new tab" cue; hidden from assistive tech.
                $title .= '<span class="icon-new-window" aria-hidden="true"></span>';

                // Options of each row
                $options_more = array();
                if ($aRow['b_active']) {
                    $options_more[] = '<a href="' . osc_admin_base_url(true) . '?page=items&amp;action=status&amp;id='
                        . $aRow['pk_i_id'] . '&amp;' . $csrf_token_url . '&amp;value=INACTIVE">' . __('Deactivate')
                        . '</a>';
                } else {
                    $options_more[] = '<a href="' . osc_admin_base_url(true) . '?page=items&amp;action=status&amp;id='
                        . $aRow['pk_i_id'] . '&amp;' . $csrf_token_url . '&amp;value=ACTIVE">' . __('Activate')
                        . '</a>';
                }
                if ($aRow['b_enabled']) {
                    $options_more[] = '<a href="' . osc_admin_base_url(true) . '?page=items&amp;action=status&amp;id='
                        . $aRow['pk_i_id'] . '&amp;' . $csrf_token_url . '&amp;value=DISABLE">' . __('Block') . '</a>';
                } else {
                    $options_more[] = '<a href="' . osc_admin_base_url(true) . '?page=items&amp;action=status&amp;id='
                        . $aRow['pk_i_id'] . '&amp;' . $csrf_token_url . '&amp;value=ENABLE">' . __('Unblock') . '</a>';
                }
                if ($aRow['b_premium']) {
                    $options_more[] =
                        '<a href="' . osc_admin_base_url(true) . '?page=items&amp;action=status_premium&amp;id='
                        . $aRow['pk_i_id'] . '&amp;' . $csrf_token_url . '&amp;value=0">' . __('Unmark as premium')
                        . '</a>';
                } else {
                    $options_more[] =
                        '<a href="' . osc_admin_base_url(true) . '?page=items&amp;action=status_premium&amp;id='
                        . $aRow['pk_i_id'] . '&amp;' . $csrf_token_url . '&amp;value=1">' . __('Mark as premium')
                        . '</a>';
                }
                if ($aRow['b_spam']) {
                    $options_more[] =
                        '<a href="' . osc_admin_base_url(true) . '?page=items&amp;action=status_spam&amp;id='
                        . $aRow['pk_i_id'] . '&amp;' . $csrf_token_url . '&amp;value=0">' . __('Unmark as spam')
                        . '</a>';
                } else {
                    $options_more[] =
                        '<a href="' . osc_admin_base_url(true) . '?page=items&amp;action=status_spam&amp;id='
                        . $aRow['pk_i_id'] . '&amp;' . $csrf_token_url . '&amp;value=1">' . __('Mark as spam') . '</a>';
                }

                // general options
                $options[] = '<a href="' . osc_admin_base_url(true) . '?page=items&amp;action=item_edit&amp;id='
                    . $aRow['pk_i_id'] . '">' . __('Edit') . '</a>';
                $options[] =
                    '<a onclick="return delete_dialog(\'' . $aRow['pk_i_id'] . '\');" href="' . osc_admin_base_url(true)
                    . '?page=items&amp;action=delete&amp;id[]=' . $aRow['pk_i_id'] . '">' . __('Delete') . '</a>';

                // only show if there are data
                if (ItemComment::newInstance()->totalComments($aRow['pk_i_id']) > 0) {
                    $options[] = '<a href="' . osc_admin_base_url(true) . '?page=comments&amp;action=list&amp;id='
                        . $aRow['pk_i_id'] . '">' . __('View comments') . '</a>';
                }
                if (ItemResource::newInstance()->countResources($aRow['pk_i_id']) > 0) {
                    $options[] = '<a href="' . osc_admin_base_url(true) . '?page=media&amp;action=list&amp;resourceId='
                        . $aRow['pk_i_id'] . '">' . __('View media') . '</a>';
                }

                $options_more = osc_apply_filter('more_actions_manage_items', $options_more, $aRow);
                // more actions
                $moreOptions =
                    '<li class="show-more">' . PHP_EOL
                    . '<a href="#" class="show-more-trigger" aria-label="' . osc_esc_html(__('More actions'))
                    . '" title="' . osc_esc_html(__('More actions')) . '">'
                    . '<span class="show-more-icon" aria-hidden="true"></span></a>' . PHP_EOL . '<ul>' . PHP_EOL;
                foreach ($options_more as $actual) {
                    $moreOptions .= '<li>' . $actual . '</li>' . PHP_EOL;
                }
                $moreOptions .= '</ul>' . PHP_EOL . '</li>' . PHP_EOL;

                $options = osc_apply_filter('actions_manage_items', $options, $aRow);
                // create list of actions
                $auxOptions = '<ul>' . PHP_EOL;
                foreach ($options as $actual) {
                    $auxOptions .= '<li>' . $actual . '</li>' . PHP_EOL;
                }
                if (!empty($options_more)) {
                    $auxOptions .= $moreOptions;
                }
                $auxOptions .= '</ul>' . PHP_EOL;

                $actions = '<div class="actions">' . $auxOptions . '</div>' . PHP_EOL;

                // fill a row
                $row['bulkactions']   =
                    '<input type="checkbox" name="id[]" value="' . $aRow['pk_i_id'] . '" active="' . $aRow['b_active']
                    . '" blocked="' . $aRow['b_enabled'] . '"/>';
                $status               = $this->get_row_status();
                $row['status-border'] = '';
                $row['status']        = $status['text'];

                // A spam-quarantined listing has no user reports behind it (that is the
                // reported-listings screen's job) — this is its only "why" surface, so
                // the lookup only runs for the rows that actually need it.
                $moderationBadge = '';
                if ($aRow['b_spam']) {
                    $modLog = ItemModerationLog::newInstance()->latestForItem((int) $aRow['pk_i_id']);
                    if ($modLog !== null) {
                        $moderationBadge = ' <span class="badge bg-warning text-dark" title="'
                            . osc_esc_html(osc_format_date($modLog['dt_date'], osc_date_format() . ' ' . osc_time_format()))
                            . '">' . osc_esc_html($this->moderationReasonLabel($modLog)) . '</span>';
                    }
                }
                $itemUrl    = osc_esc_html(osc_item_url());

                // Dedicated image column: the listing's first photo (storage-aware) or a
                // placeholder icon, linking through to the listing.
                $thumbUrl     = $this->listingThumb((int) $aRow['pk_i_id']);
                $thumbInner   = $thumbUrl !== ''
                    ? '<img src="' . osc_esc_html($thumbUrl) . '" loading="lazy" alt=""/>'
                    : '<i class="bi bi-image" aria-hidden="true"></i>';
                $row['image'] = '<a class="listing-thumb' . ($thumbUrl === '' ? ' listing-thumb--empty' : '')
                    . '" href="' . $itemUrl . '" target="_blank" rel="noopener">' . $thumbInner . '</a>';

                // Title cell: the title link + row actions, with the moderation/spam
                // keyword badge dropped to its own line below everything.
                $row['title'] = '<a href="' . $itemUrl . '" target="_blank">' . $title . '</a>'
                    . $actions
                    . ($moderationBadge !== '' ? '<div class="listing-keyword">' . $moderationBadge . '</div>' : '');

                if ($aRow['fk_i_user_id'] != null) {
                    $row['user'] =
                        '<a href="' . osc_admin_base_url(true) . '?page=users&action=edit&id=' . $aRow['fk_i_user_id']
                        . '" target="_blank">' . osc_esc_html($aRow['s_user_name']) . '</a>';
                } else {
                    $row['user'] = osc_esc_html($aRow['s_user_name']);
                }
                $row['category']   = osc_esc_html($aRow['s_category_name']);
                $row['location']   = $this->get_row_location();
                $row['date']       = osc_format_date($aRow['dt_pub_date'], osc_date_format() . ' ' . osc_time_format());
                $row['expiration'] =
                    ($aRow['dt_expiration'] !== '9999-12-31 23:59:59') ? osc_format_date(
                        $aRow['dt_expiration'],
                        osc_date_format() . ' ' . osc_time_format()
                    ) : __('Never expires');

                $row = osc_apply_filter('items_processing_row', $row, $aRow);

                $this->addRow($row);
                $this->rawRows[] = $aRow;
            }
        }
    }

    /**
     * Storage-aware thumbnail URL for a listing's first image, or '' if it has none.
     *
     * @param int $itemId
     *
     * @return string
     */
    private function listingThumb($itemId)
    {
        $rows = osc_db_select(
            'SELECT pk_i_id, s_path, s_extension, s_content_type, s_storage FROM '
            . DB_TABLE_PREFIX . "t_item_resource WHERE fk_i_item_id = ? AND s_content_type LIKE 'image/%' "
            . 'ORDER BY pk_i_id ASC LIMIT 1',
            array((int) $itemId)
        );
        if (empty($rows)) {
            return '';
        }
        $r = (array) $rows[0];

        return (string) osc_get_resource_url(array(
            'pk_i_id'        => $r['pk_i_id'],
            's_path'         => $r['s_path'],
            's_extension'    => $r['s_extension'],
            's_storage'      => $r['s_storage'] ?? 'local',
            's_content_type' => $r['s_content_type'] ?? '',
            's_owner_type'   => 'item',
            'i_owner_id'     => $itemId,
        ), 'thumbnail');
    }

    /**
     * Get the status of the row. There are five status:
     *     - spam
     *     - blocked
     *     - inactive
     *     - premium
     *     - active
     *     - expired
     *
     * @return array Array with the class and text of the status of the listing in this row. Example:
     *     array(
     *         'class' => '',
     *         'text'  => ''
     *     )
     * @since 3.2 -> 3.4.x
     *
     */
    private function get_row_status()
    {
        if (osc_item_is_spam()) {
            return array(
                'class' => 'status-spam',
                'text'  => __('Spam')
            );
        }

        if (!osc_item_is_enabled()) {
            return array(
                'class' => 'status-blocked',
                'text'  => __('Blocked')
            );
        }

        if (!osc_item_is_active()) {
            return array(
                'class' => 'status-inactive',
                'text'  => __('Inactive')
            );
        }

        if (osc_item_is_premium()) {
            return array(
                'class' => 'status-premium',
                'text'  => __('Premium')
            );
        }

        if (osc_item_is_expired()) {
            return array(
                'class' => 'status-expired',
                'text'  => __('Expired')
            );
        }

        return array(
            'class' => 'status-active',
            'text'  => __('Active')
        );
    }

    /**
     * Get the location separated by commas of a row
     *
     * @return string Location separated by commas
     * @since 3.2
     *
     */
    private function get_row_location()
    {
        $location = array();
        if (osc_item_city() !== '') {
            $location[] = osc_item_city();
        }
        if (osc_item_region() !== '') {
            $location[] = osc_item_region();
        }
        if (osc_item_country() !== '') {
            $location[] = osc_item_country();
        }

        return implode(', ', $location);
    }

    /**
     * @param $params
     *
     * @return array
     */
    public function tableReported($params)
    {
        $this->addTableHeaderReported();
        $this->mSearch = new Search(true);
        $this->getDBParams($params);
        // only some fields can be ordered
        $direction      = Params::getParam('direction');
        $arrayDirection = array('desc', 'asc');
        if (!in_array($direction, $arrayDirection)) {
            Params::setParam('direction', 'desc');
            $direction = 'desc';
        }

        $sort             = Params::getParam('sort');
        $arraySortColumns = array(
            'spam'       => 'i_num_spam',
            'bad'        => 'i_num_bad_classified',
            'rep'        => 'i_num_repeated',
            'off'        => 'i_num_offensive',
            'exp'        => 'i_num_expired',
            'date'       => 'dt_pub_date',
            'expiration' => 'dt_expiration'
        );
        // column sort
        if (!array_key_exists($sort, $arraySortColumns)) {
            $sort = 'dt_pub_date';
            $this->mSearch->addHaving('i_num_spam > 0 OR i_num_bad_classified > 0 OR i_num_repeated > 0 OR i_num_offensive > 0 OR i_num_expired > 0');
        } else {
            $sort = $arraySortColumns[$sort];
            if ($sort !== 'dt_pub_date') {
                $this->mSearch->addHaving($sort . ' > 0');
            } else {
                $this->mSearch->addHaving('i_num_spam > 0 OR i_num_bad_classified > 0 OR i_num_repeated > 0 OR i_num_offensive > 0 OR i_num_expired > 0');
            }
        }

        $this->mSearch->order($sort, $direction);

        $this->mSearch->addTable(sprintf('%st_item_stats s', DB_TABLE_PREFIX));
        $this->mSearch->addField('SUM(s.`i_num_spam`) as i_num_spam');
        $this->mSearch->addField('SUM(s.`i_num_bad_classified`) as i_num_bad_classified');
        $this->mSearch->addField('SUM(s.`i_num_repeated`) as i_num_repeated');
        $this->mSearch->addField('SUM(s.`i_num_offensive`) as i_num_offensive');
        $this->mSearch->addField('SUM(s.`i_num_expired`) as i_num_expired');

        // having
        
        // Faster for large tables (tested with 1.5 million rows)
        // if indexes for all i_num_* columns are created
        $this->mSearch->addConditions(sprintf(' 
            %st_item.pk_i_id IN ( 
            SELECT s.fk_i_item_id 
            FROM %st_item_stats s 
            WHERE s.i_num_spam > 0 OR s.i_num_bad_classified > 0 OR s.i_num_repeated > 0 OR s.i_num_offensive > 0 OR s.i_num_expired > 0 
            )', DB_TABLE_PREFIX, DB_TABLE_PREFIX));
        $this->mSearch->addConditions(sprintf(' %st_item.pk_i_id ', DB_TABLE_PREFIX));
        $this->mSearch->addConditions(sprintf(' %st_item.pk_i_id = s.fk_i_item_id', DB_TABLE_PREFIX));
        $this->mSearch->addGroupBy(sprintf(' %st_item.pk_i_id ', DB_TABLE_PREFIX));
        // do Search
        $this->processDataReported(Item::newInstance()->extendCategoryName($this->mSearch->doSearch()));
        $this->totalFiltered = $this->mSearch->count();
        $this->total         = $this->mSearch->count();

        return $this->getData();
    }

    private function addTableHeaderReported()
    {

        Rewrite::newInstance()->init();
        $page = (int)Params::getParam('iPage');
        if ($page == 0) {
            $page = 1;
        }
        Params::setParam('iPage', $page);
        $url_base       = preg_replace(
            '|&direction=([^&]*)|',
            '',
            preg_replace('|&sort=([^&]*)|', '', osc_base_url() . Rewrite::newInstance()->get_raw_request_uri())
        );
        $arg_spam       = '&sort=spam';
        $arg_bad        = '&sort=bad';
        $arg_rep        = '&sort=rep';
        $arg_off        = '&sort=off';
        $arg_exp        = '&sort=exp';
        $arg_date       = '&sort=date';
        $arg_expiration = '&sort=expiration';
        $sort           = Params::getParam('sort');
        $direction      = Params::getParam('direction');

        switch ($sort) {
            case ('spam'):
                if ($direction === 'desc' || $direction == '') {
                    $arg_spam .= '&direction=asc';
                }
                break;
            case ('bad'):
                if ($direction === 'desc' || $direction == '') {
                    $arg_bad .= '&direction=asc';
                }
                break;
            case ('rep'):
                if ($direction === 'desc' || $direction == '') {
                    $arg_rep .= '&direction=asc';
                }
                break;
            case ('off'):
                if ($direction === 'desc' || $direction == '') {
                    $arg_off .= '&direction=asc';
                }
                break;
            case ('exp'):
                if ($direction === 'desc' || $direction == '') {
                    $arg_exp .= '&direction=asc';
                }
                break;
            case ('date'):
                if ($direction === 'desc' || $direction == '') {
                    $arg_date .= '&direction=asc';
                }
                break;
            case ('expiration'):
                if ($direction === 'desc' || $direction == '') {
                    $arg_expiration .= '&direction=asc';
                }
                break;
            default:
                break;
        }

        $url_spam       = $url_base . $arg_spam;
        $url_bad        = $url_base . $arg_bad;
        $url_rep        = $url_base . $arg_rep;
        $url_off        = $url_base . $arg_off;
        $url_exp        = $url_base . $arg_exp;
        $url_date       = $url_base . $arg_date;
        $url_expiration = $url_base . $arg_expiration;

        $this->addColumn('bulkactions', '<input id="check_all" type="checkbox" />');
        $this->addColumn('title', __('Title'));
        $this->addColumn('user', __('User'));
        // Distinct, deduplicated reporters (one vote per person) and the recorded
        // "why hidden" reason — both new in the item-moderation log, both plainly
        // distinct from the raw, un-deduplicated i_num_* columns to their right.
        $this->addColumn('reporters', __('Unique reporters'));
        $this->addColumn('reason', __('Why hidden'));
        $this->addColumn('spam', '<a id="order_spam" href="' . osc_esc_html($url_spam) . '">' . __('Spam') . '</a>');
        $this->addColumn(
            'bad',
            '<a id="order_bad" href="' . osc_esc_html($url_bad) . '">' . __('Misclassified') . '</a>'
        );
        $this->addColumn('rep', '<a id="order_rep" href="' . osc_esc_html($url_rep) . '">' . __('Duplicated') . '</a>');
        $this->addColumn('exp', '<a id="order_exp" href="' . osc_esc_html($url_exp) . '">' . __('Expired') . '</a>');
        $this->addColumn('off', '<a id="order_off" href="' . osc_esc_html($url_off) . '">' . __('Offensive') . '</a>');
        $this->addColumn('date', '<a id="order_date" href="' . osc_esc_html($url_date) . '">' . __('Date') . '</a>');
        $this->addColumn(
            'expiration',
            '<a id="order_expiration" href="' . osc_esc_html($url_expiration) . '">' . __('Expiration date') . '</a>'
        );

        $dummy = &$this;
        osc_run_hook('admin_items_reported_table', $dummy);
    }

    /**
     * @param $items
     *
     */
    private function processDataReported($items)
    {
        if (!empty($items)) {
            $csrf_token_url = osc_csrf_token_url();
            foreach ($items as $aRow) {
                View::newInstance()->_exportVariableToView('item', $aRow);
                $row     = array();
                $options = array();
                // -- prepare data --
                // prepare item title
                $title = mb_substr($aRow['s_title'], 0, 30, 'UTF-8');
                if ($title != $aRow['s_title']) {
                    $title .= '...';
                }

                $options[] = '<a href="' . osc_admin_base_url(true) . '?page=items&amp;action=clear_reports&amp;id='
                    . $aRow['pk_i_id'] . '&amp;' . $csrf_token_url . '">' . __('Clear reports') . '</a>';
                $options[] = '<a href="' . osc_admin_base_url(true) . '?page=items&amp;action=clear_stat&amp;id='
                    . $aRow['pk_i_id'] . '&amp;' . $csrf_token_url . '&amp;stat=all">' . __('Clear All') . '</a>';
                if ($aRow['i_num_spam'] > 0) {
                    $options[] = '<a href="' . osc_admin_base_url(true) . '?page=items&amp;action=clear_stat&amp;id='
                        . $aRow['pk_i_id'] . '&amp;' . $csrf_token_url . '&amp;stat=spam">' . __('Clear Spam') . '</a>';
                }
                if ($aRow['i_num_bad_classified'] > 0) {
                    $options[] = '<a href="' . osc_admin_base_url(true) . '?page=items&amp;action=clear_stat&amp;id='
                        . $aRow['pk_i_id'] . '&amp;' . $csrf_token_url . '&amp;stat=bad">' . __('Clear Misclassified')
                        . '</a>';
                }
                if ($aRow['i_num_repeated'] > 0) {
                    $options[] = '<a href="' . osc_admin_base_url(true) . '?page=items&amp;action=clear_stat&amp;id='
                        . $aRow['pk_i_id'] . '&amp;' . $csrf_token_url . '&amp;stat=duplicated">'
                        . __('Clear Duplicated') . '</a>';
                }
                if ($aRow['i_num_offensive'] > 0) {
                    $options[] = '<a href="' . osc_admin_base_url(true) . '?page=items&amp;action=clear_stat&amp;id='
                        . $aRow['pk_i_id'] . '&amp;' . $csrf_token_url . '&amp;stat=offensive">' . __('Clear Offensive')
                        . '</a>';
                }
                if ($aRow['i_num_expired'] > 0) {
                    $options[] = '<a href="' . osc_admin_base_url(true) . '?page=items&amp;action=clear_stat&amp;id='
                        . $aRow['pk_i_id'] . '&amp;' . $csrf_token_url . '&amp;stat=expired">' . __('Clear Expired')
                        . '</a>';
                }
                if (count($options) > 0) {
                    $options[] = '<a href="' . osc_admin_base_url(true) . '?page=items&amp;action=item_edit&amp;id='
                        . $aRow['pk_i_id'] . '">' . __('Edit') . '</a>';
                    $options[] = '<a onclick="return delete_dialog(\'' . $aRow['pk_i_id'] . '\');" href="'
                        . osc_admin_base_url(true) . '?page=items&amp;action=delete&amp;id[]=' . $aRow['pk_i_id']
                        . '&amp;' . $csrf_token_url . '">' . __('Delete') . '</a>';
                }

                // create list of actions
                $auxOptions = '<ul>' . PHP_EOL;
                foreach ($options as $actual) {
                    $auxOptions .= '<li>' . $actual . '</li>' . PHP_EOL;
                }
                $auxOptions .= '</ul>' . PHP_EOL;

                $actions = '<div class="actions">' . $auxOptions . '</div>' . PHP_EOL;

                // fill a row
                $row['bulkactions'] =
                    '<input type="checkbox" name="id[]" value="' . $aRow['pk_i_id'] . '" active="' . $aRow['b_active']
                    . '" blocked="' . $aRow['b_enabled'] . '"/>';
                $row['title']       = '<a href="' . osc_esc_html(osc_item_url()) . '" target="_blank">' . osc_esc_html($title) . '</a>' . $actions;
                $row['user']        = osc_esc_html($aRow['s_user_name']);
                $row['reporters']   = $this->reportersCell((int) $aRow['pk_i_id']);
                $row['reason']      = $this->reasonCell((int) $aRow['pk_i_id']);
                $row['spam']        = $aRow['i_num_spam'];
                $row['bad']         = $aRow['i_num_bad_classified'];
                $row['rep']         = $aRow['i_num_repeated'];
                $row['exp']         = $aRow['i_num_expired'];
                $row['off']         = $aRow['i_num_offensive'];
                $row['date']        =
                    osc_format_date($aRow['dt_pub_date'], osc_date_format() . ' ' . osc_time_format());
                $row['expiration']  =
                    ($aRow['dt_expiration'] !== '9999-12-31 23:59:59') ? osc_format_date(
                        $aRow['dt_expiration'],
                        osc_date_format() . ' ' . osc_time_format()
                    ) : __('Never expires');

                $row = osc_apply_filter('items_processing_reported_row', $row, $aRow);

                $this->addRow($row);
                $this->rawRows[] = $aRow;
            }
        }
    }

    /**
     * The "Unique reporters" cell: the deduplicated, one-vote-per-person count
     * from t_item_report_log, with the per-reason breakdown on hover. Deliberately
     * distinct from the raw i_num_* columns, which count every report including
     * repeats from the same person.
     *
     * @param int $itemId
     *
     * @return string
     */
    private function reportersCell($itemId)
    {
        $count = ItemReport::newInstance()->countReporters($itemId);
        if ($count === 0) {
            return '<span class="text-muted">' . osc_esc_html(__('None')) . '</span>';
        }

        $parts = array();
        foreach (ItemReport::newInstance()->reasonBreakdown($itemId) as $reason => $reasonCount) {
            $parts[] = osc_esc_html($reason) . ': ' . (int) $reasonCount;
        }

        return '<span class="badge bg-secondary" title="' . osc_esc_html(implode(', ', $parts)) . '">'
            . (int) $count . '</span>';
    }

    /**
     * The "Why hidden" cell: the latest t_item_moderation_log entry for this
     * item, if any — the durable record of what quarantined or auto-blocked it.
     *
     * @param int $itemId
     *
     * @return string
     */
    private function reasonCell($itemId)
    {
        $modLog = ItemModerationLog::newInstance()->latestForItem($itemId);
        if ($modLog === null) {
            return '';
        }

        return '<span class="badge bg-warning text-dark" title="'
            . osc_esc_html(osc_format_date($modLog['dt_date'], osc_date_format() . ' ' . osc_time_format())) . '">'
            . osc_esc_html($this->moderationReasonLabel($modLog)) . '</span>';
    }

    /**
     * Render a short "why hidden" label from a moderation-log row —
     * `keyword: "viagra"` for a keyword hit, `reports: 5` for a report-threshold
     * auto-block. The caller is responsible for escaping; this returns raw text.
     *
     * @param array $modLog a t_item_moderation_log row
     *
     * @return string
     */
    private function moderationReasonLabel($modLog)
    {
        if ($modLog['s_source'] === 'keyword') {
            return sprintf('%s: "%s"', __('keyword'), $modLog['s_reason']);
        }
        if ($modLog['s_source'] === 'report_threshold') {
            // Stored as "reports:5" (osc_item_report_record()) — add the space back.
            return str_replace(':', ': ', (string) $modLog['s_reason']);
        }

        return (string) $modLog['s_reason'];
    }

    /**
     * @return bool
     */
    public function withFilters()
    {
        return osc_apply_filter('manage_item_search_with_filters', $this->withFilters);
    }

    /**
     * @param $class
     * @param $rawRow
     * @param $row
     *
     * @return array
     */
    public function row_class($class, $rawRow, $row)
    {
        View::newInstance()->_exportVariableToView('item', $rawRow);
        $status  = $this->get_row_status();
        $class[] = $status['class'];
        View::newInstance()->_erase('item');

        return $class;
    }
}
