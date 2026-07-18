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

/**
 * UsersDataTable class
 *
 * @since      3.1
 * @package    Shopclass
 * @subpackage classes
 * @author     Shopclass
 */
class UsersDataTable extends DataTable
{

    private $withUserId;
    private $search;
    private $column_names;
    public $order_by;
    public $conditions;
    public $withFilters = false;

    public function __construct()
    {
        parent::__construct();
        osc_add_filter('datatable_user_class', array(&$this, 'row_class'));
    }

    /**
     * @param $params
     *
     * @return array
     */
    public function table($params)
    {

        $this->withUserId = false;
        $this->search     = '';
        $this->addTableHeader();
        $this->getDBParams($params);

        $dummy = &$this;
        osc_run_hook('manage_user_search_conditions', $dummy);

        $list_users = User::newInstance()->search(
            $this->start,
            $this->limit,
            $this->order_by['column_name'],
            $this->order_by['type'],
            $this->conditions
        );

        $this->processData($list_users['users']);
        $this->totalFiltered = $list_users['total_results'];
        $this->total         = $list_users['rows'];

        return $this->getData();
    }

    private function addTableHeader()
    {

        $this->addColumn('status-border', '');
        $this->addColumn('status', __('Status'));
        $this->addColumn('bulkactions', '<input id="check_all" type="checkbox" />');
        $this->addColumn('email', __('E-mail'));
        $this->addColumn('username', __('Username'));
        $this->addColumn('name', __('Name'));
        $this->addColumn('date', __('Date'));
        $this->addColumn('items', __('Items'));
        $this->addColumn('update_date', __('Update date'));

        $dummy = &$this;
        osc_run_hook('admin_users_table', $dummy);
    }

    /**
     * @param $_get
     */
    private function getDBParams($_get)
    {
        # Display length
        if (!isset($_get['iDisplayStart']) || !is_numeric($_get['iDisplayStart']) || $_get['iDisplayStart'] < 0) {
            $_get['iDisplayStart'] = 0;
        }
        # Paging
        $p_iPage = 1;
        if (!is_numeric(Params::getParam('iPage')) || Params::getParam('iPage') < 1) {
            Params::setParam('iPage', $p_iPage);
            $this->iPage = $p_iPage;
        } else {
            $this->iPage = Params::getParam('iPage');
        }
        # sorting column
        if (!isset($_get['iSortCol_0']) || $_get['iSortCol_0'] == '') {
            $this->order_by['column_name'] = 'pk_i_id';
        } else {
            $this->order_by['column_name'] = $this->column_names[$_get['iSortCol_0']];
        }
        # Sorting order
        if (!isset($_get['sSortDir_0']) || $_get['sSortDir_0'] == '') {
            $this->order_by['type'] = 'DESC';
        } else {
            $this->order_by['type'] = $_get['sSortDir_0'];
        }

        $this->conditions = array();
        # condition for userId
        if (isset($_get['userId']) && is_numeric($_get['userId'])) {
            $this->conditions['pk_i_id'] = str_replace('*', '%', $_get['userId']);
            $this->withFilters           = true;
        }
        # condition for s_email
        if (isset($_get['s_email']) && $_get['s_email']) {
            // escape value
            $esc_email                                             =
                User::newInstance()->dao->escape(str_replace('*', '%', $_get['s_email']));
            $this->conditions["s_email LIKE " . $esc_email ] = null;
            $this->withFilters                                     = true;
        }
        # condition for s_name
        if (isset($_get['s_name']) && $_get['s_name']) {
            $this->conditions['s_name'] = str_replace('*', '%', $_get['s_name']);
            $this->withFilters          = true;
        } elseif (isset($_get['user']) && $_get['user']) {
            if (!isset($_get['userId']) || $_get['userId'] == '') {
                // escape value
                $esc_user                                                                                =
                    User::newInstance()->dao->escape(str_replace('*', '%', $_get['user']));
                $this->conditions["s_email LIKE " . $esc_user . " OR s_name LIKE " . $esc_user ] = null;
            } else {
                $this->conditions['s_name'] = str_replace('*', '%', $_get['user']);
            }
            $this->withFilters = true;
        }
        # condition for s_username
        if (isset($_get['s_username']) && $_get['s_username'] !='') {
            $this->conditions['s_username'] = str_replace('*', '%', $_get['s_username']);
            $this->withFilters              = true;
        }
        # condition for countryId
        if (isset($_get['countryId']) && $_get['countryId'] != '') {
            $this->conditions['fk_c_country_code'] = $_get['countryId'];
            $this->withFilters                     = true;
        } elseif (isset($_get['countryName']) && $_get['countryName'] != '') {
            $this->conditions['s_country'] = $_get['countryName'];
            $this->withFilters             = true;
        }
        # condition for regionId
        if (isset($_get['regionId']) && $_get['regionId'] != '') {
            $this->conditions['fk_i_region_id'] = $_get['regionId'];
            $this->withFilters                  = true;
        } elseif (isset($_get['region']) && $_get['region'] != '') {
            $this->conditions['s_region'] = $_get['region'];
            $this->withFilters            = true;
        }
        # condition for cityId
        if (isset($_get['cityId']) && $_get['cityId'] != '') {
            $this->conditions['fk_i_city_id'] = $_get['cityId'];
            $this->withFilters                = true;
        } elseif (isset($_get['city']) && $_get['city'] != '') {
            $this->conditions['s_city'] = $_get['city'];
            $this->withFilters          = true;
        }

        # condition enabled
        if (isset($_get['b_enabled']) && $_get['b_enabled'] != '') {
            $this->conditions['b_enabled'] = $_get['b_enabled'];
            $this->withFilters             = true;
        }
        # condition active
        if (isset($_get['b_active']) && $_get['b_active'] != '') {
            $this->conditions['b_active'] = $_get['b_active'];
            $this->withFilters            = true;
        }


        // set start and limit using iPage param
        $start = ($this->iPage - 1) * $_get['iDisplayLength'];

        $this->start = (int)$start;
        $this->limit = (int)$_get['iDisplayLength'];
    }

    /**
     * @param $users
     */
    private function processData($users)
    {
        if (!empty($users)) {
            $csrf_token_url = osc_csrf_token_url();
            foreach ($users as $aRow) {
                $row          = array();
                $options      = array();
                $options_more = array();
                // first column

                $options[] =
                    '<a href="' . osc_admin_base_url(true) . '?page=users&action=edit&amp;id=' . $aRow['pk_i_id'] . '">'
                    . __('Edit') . '</a>';
                $options[] =
                    '<a onclick="return delete_dialog(\'' . $aRow['pk_i_id'] . '\');" href="' . osc_admin_base_url(true)
                    . '?page=users&action=delete&amp;id[]=' . $aRow['pk_i_id'] . '">' . __('Delete') . '</a>';
                $options[] = '<a href="' . osc_user_public_profile_url($aRow['pk_i_id']) . '" target="_blank">'
                    . __('Public profile') . '</a>';

                $options[] = '<a href="' . osc_admin_base_url(true) . '?page=users&action=user_login&id='
                    . $aRow['pk_i_id'] . '&' . $csrf_token_url . '" target="_blank">' . __('Login') . '</a>';

                if ($aRow['b_active'] == 1) {
                    $options_more[] = '<a href="' . osc_admin_base_url(true) . '?page=users&action=deactivate&amp;id[]='
                        . $aRow['pk_i_id'] . '&amp;' . $csrf_token_url . '">' . __('Deactivate') . '</a>';
                } else {
                    $options_more[] = '<a href="' . osc_admin_base_url(true) . '?page=users&action=activate&amp;id[]='
                        . $aRow['pk_i_id'] . '&amp;' . $csrf_token_url . '">' . __('Activate') . '</a>';
                }
                if ($aRow['b_enabled'] == 1) {
                    $options_more[] = '<a href="' . osc_admin_base_url(true) . '?page=users&action=disable&amp;id[]='
                        . $aRow['pk_i_id'] . '&amp;' . $csrf_token_url . '">' . __('Block') . '</a>';
                } else {
                    $options_more[] = '<a href="' . osc_admin_base_url(true) . '?page=users&action=enable&amp;id[]='
                        . $aRow['pk_i_id'] . '&amp;' . $csrf_token_url . '">' . __('Unblock') . '</a>';
                }
                if (osc_user_validation_enabled() && ($aRow['b_active'] == 0)) {
                    $options_more[] =
                        '<a href="' . osc_admin_base_url(true) . '?page=users&action=resend_activation&amp;id[]='
                        . $aRow['pk_i_id'] . '&amp;' . $csrf_token_url . '">' . __('Re-send activation email') . '</a>';
                }

                $options_more = osc_apply_filter('more_actions_manage_users', $options_more, $aRow);
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

                $options = osc_apply_filter('actions_manage_users', $options, $aRow);
                // create list of actions
                $auxOptions = '<ul>' . PHP_EOL;
                foreach ($options as $actual) {
                    $auxOptions .= '<li>' . $actual . '</li>' . PHP_EOL;
                }
                $auxOptions .= $moreOptions;
                $auxOptions .= '</ul>' . PHP_EOL;

                $actions = '<div class="actions">' . $auxOptions . '</div>' . PHP_EOL;

                $status               = $this->get_row_status($aRow);
                $row['status-border'] = '';
                $row['status']        = $status['text'];
                $row['bulkactions']   = '<input type="checkbox" name="id[]" value="' . $aRow['pk_i_id'] . '" /></div>';
                $row['email']         =
                    '<a href="' . osc_admin_base_url(true) . '?page=items&userId=' . $aRow['pk_i_id'] . '&user='
                    . rawurlencode($aRow['s_name']) . '">' . osc_esc_html($aRow['s_email']) . '</a>' . $actions;
                $row['username']      = osc_esc_html($aRow['s_username']);
                $row['name']          = osc_esc_html($aRow['s_name']);
                $row['date']          =
                    osc_format_date($aRow['dt_reg_date'], osc_date_format() . ' ' . osc_time_format());
                $row['items']         = $aRow['i_items'];
                $row['update_date']   = ($aRow['dt_mod_date'] != null) ? osc_format_date(
                    $aRow['dt_mod_date'],
                    osc_date_format() . ' ' . osc_time_format()
                ) : '';

                $row = osc_apply_filter('users_processing_row', $row, $aRow);

                $this->addRow($row);
                $this->rawRows[] = $aRow;
            }
        }
    }

    /**
     * Get the status of the row. There are three status:
     *     - blocked
     *     - inactive
     *     - active
     *
     * @param $user
     *
     * @return array Array with the class and text of the status of the listing in this row. Example:
     *     array(
     *         'class' => '',
     *         'text'  => ''
     *     )
     * @since 3.3
     *
     */
    private function get_row_status($user)
    {

        if ($user['b_enabled'] == 0) {
            return array(
                'class' => 'status-blocked',
                'text'  => __('Blocked')
            );
        }

        if ($user['b_active'] == 0) {
            return array(
                'class' => 'status-inactive',
                'text'  => __('Inactive')
            );
        }

        return array(
            'class' => 'status-active',
            'text'  => __('Active')
        );
    }

    /**
     * @return bool
     */
    public function withFilters()
    {
        return osc_apply_filter('manage_user_search_with_filters', $this->withFilters);
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
        $status  = $this->get_row_status($rawRow);
        $class[] = $status['class'];

        return $class;
    }
}
