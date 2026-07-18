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
 * BanRulesDataTable class
 *
 * @since      3.1
 * @package    Shopclass
 * @subpackage classes
 * @author     Shopclass
 */
class BanRulesDataTable extends DataTable
{

    private $order_by;
    private $column_names;
    private $userId;
    /**
     * @var bool
     */
    private $withUserId;
    private $search;


    /**
     * @param $params
     *
     * @return array
     */
    public function table($params)
    {

        $this->addTableHeader();
        $this->getDBParams($params);

        $list_rules = BanRule::newInstance()->search(
            $this->start,
            $this->limit,
            $this->order_by['column_name'],
            $this->order_by['type']
        );

        $this->processData($list_rules['rules']);
        $this->totalFiltered = $list_rules['total_results'];
        $this->total         = $list_rules['rows'];

        return $this->getData();
    }

    private function addTableHeader()
    {

        $this->addColumn('bulkactions', '<input id="check_all" type="checkbox" />');
        $this->addColumn('name', __('Ban name / Reason'));
        $this->addColumn('ip', __('IP rule'));
        $this->addColumn('email', __('E-mail rule'));

        $dummy = &$this;
        osc_run_hook('admin_rules_table', $dummy);
    }

    /**
     * @param $_get
     */
    private function getDBParams($_get)
    {

        if (!isset($_get['iDisplayStart'])) {
            $_get['iDisplayStart'] = 0;
        }
        $p_iPage = 1;
        if (!is_numeric(Params::getParam('iPage')) || Params::getParam('iPage') < 1) {
            Params::setParam('iPage', $p_iPage);
            $this->iPage = $p_iPage;
        } else {
            $this->iPage = Params::getParam('iPage');
        }

        $this->order_by['column_name'] = 'pk_i_id';
        $this->order_by['type']        = 'DESC';
        foreach ($_get as $k => $v) {
            if ($k === 'user') {
                $this->search = $v;
            }
            if ($k === 'userId' && $v != '') {
                $this->withUserId = true;
                $this->userId     = $v;
            }

            /* for sorting */
            if ($k === 'iSortCol_0') {
                $this->order_by['column_name'] = $this->column_names[$v];
            }
            if ($k === 'sSortDir_0') {
                $this->order_by['type'] = $v;
            }
        }
        // set start and limit using iPage param
        $start = ($this->iPage - 1) * $_get['iDisplayLength'];

        $this->start = (int)$start;
        $this->limit = (int)$_get['iDisplayLength'];
    }

    /**
     * @param $rules
     */
    private function processData($rules)
    {
        if (!empty($rules)) {
            $csrf_token_url = osc_csrf_token_url();
            foreach ($rules as $aRow) {
                $row          = array();
                $options      = array();
                $options_more = array();
                // first column

                $options[] = '<a href="' . osc_admin_base_url(true) . '?page=users&action=edit_ban_rule&amp;id='
                    . $aRow['pk_i_id'] . '">' . __('Edit') . '</a>';
                $options[] =
                    '<a onclick="return delete_dialog(\'' . $aRow['pk_i_id'] . '\');" href="' . osc_admin_base_url(true)
                    . '?page=users&action=delete_ban_rule&amp;id[]=' . $aRow['pk_i_id'] . '">' . __('Delete') . '</a>';

                $options_more = osc_apply_filter('more_actions_manage_rules', $options_more, $aRow);
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

                $options = osc_apply_filter('actions_manage_rules', $options, $aRow);
                // create list of actions
                $auxOptions = '<ul>' . PHP_EOL;
                foreach ($options as $actual) {
                    $auxOptions .= '<li>' . $actual . '</li>' . PHP_EOL;
                }
                // Ban rules have no default extra actions, so the trigger only appears
                // when a plugin adds them via more_actions_manage_rules — otherwise it
                // would open an empty menu.
                if (!empty($options_more)) {
                    $auxOptions .= $moreOptions;
                }
                $auxOptions .= '</ul>' . PHP_EOL;

                $actions = '<div class="actions">' . $auxOptions . '</div>' . PHP_EOL;

                $row['bulkactions'] = '<input type="checkbox" name="id[]" value="' . $aRow['pk_i_id'] . '" /></div>';
                $row['name']        = osc_esc_html($aRow['s_name']) . $actions;
                $row['ip']          = osc_esc_html($aRow['s_ip']);
                $row['email']       = osc_esc_html($aRow['s_email']);

                $row = osc_apply_filter('rules_processing_row', $row, $aRow);

                $this->addRow($row);
                $this->rawRows[] = $aRow;
            }
        }
    }
}
