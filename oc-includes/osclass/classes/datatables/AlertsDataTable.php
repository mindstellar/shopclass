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
 * AlertsDataTable class
 *
 * @since      3.1
 * @package    Shopclass
 * @subpackage classes
 * @author     Shopclass
 */
class AlertsDataTable extends DataTable
{
    private $search;
    private $order_by;

    /**
     * Header column id => the t_alerts column it sorts by. The alert column renders a
     * stored search rather than a plain value, so it is not offered.
     *
     * @var array<string,string>
     */
    private $sortable = array(
        'email' => 's_email',
        'date'  => 'dt_date',
    );
    private $total_filtered;

    /**
     * Builds the saved-searches (alerts) listing for the admin datatable.
     *
     * @param array<string,mixed> $params Datatable request params (iPage, iDisplayLength, sSearch, sort, direction)
     *
     * @return array<string,mixed> The getData() payload
     */
    public function table($params)
    {

        $this->addTableHeader();
        $this->getDBParams($params);

        $alerts = Alerts::newInstance()
            ->search(
                $this->start,
                $this->limit,
                $this->order_by['column_name'],
                $this->order_by['type'],
                $this->search
            );
        $this->processData($alerts);
        $this->total          = $alerts['rows'];
        $this->total_filtered = $alerts['total_results'];
        $this->totalFiltered  = $alerts['total_results'];

        return $this->getData();
    }

    /**
     * Registers the alert columns and lets plugins extend them via admin_alerts_table.
     *
     * @return void
     */
    private function addTableHeader()
    {

        $this->addColumn('bulkactions', '<input id="check_all" type="checkbox" />');
        $this->addColumn('email', __('E-mail'));
        $this->addColumn('alert', __('Alert'));
        $this->addColumn('date', __('Date'));

        $dummy = &$this;
        osc_run_hook('admin_alerts_table', $dummy);
    }

    /**
     * Derives page, start, limit, search term and ordering from the request params.
     *
     * @param array<string,mixed> $_get
     *
     * @return void
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

        $this->order_by = $this->resolveOrder($_get, $this->sortable, 'dt_date');
        foreach ($_get as $k => $v) {
            if ($k === 'sSearch') {
                $this->search = $v;
            }
        }
        // set start and limit using iPage param
        $start = ($this->iPage - 1) * $_get['iDisplayLength'];

        $this->start = (int)$start;
        $this->limit = (int)$_get['iDisplayLength'];
    }

    /**
     * Formats each alert into table cells and keeps the raw row.
     *
     * @param array{alerts?:array<int,array<string,mixed>>,rows?:int,total_results?:int} $alerts Result set from Alerts::search()
     *
     * @return void
     */
    private function processData($alerts)
    {
        if (!empty($alerts) && !empty($alerts['alerts'])) {
            $csrf_token_url = osc_csrf_token_url();
            foreach ($alerts['alerts'] as $aRow) {
                $row     = array();
                $options = array();
                // first column
                $row['bulkactions'] =
                    '<input type="checkbox" name="alert_id[]" value="' . $aRow['pk_i_id'] . '" /></div>';

                $options[] =
                    '<a onclick="return delete_alert(\'' . $aRow['pk_i_id'] . '\');" href="#">' . __('Delete') . '</a>';

                if ($aRow['b_active'] == 1) {
                    $options[] =
                        '<a href="' . osc_admin_base_url(true) . '?page=users&action=status_alerts&amp;alert_id[]='
                        . $aRow['pk_i_id'] . '&amp;' . $csrf_token_url . '&amp;status=0" >' . __('Deactivate') . '</a>';
                } else {
                    $options[] =
                        '<a href="' . osc_admin_base_url(true) . '?page=users&action=status_alerts&amp;alert_id[]='
                        . $aRow['pk_i_id'] . '&amp;' . $csrf_token_url . '&amp;status=1" >' . __('Activate') . '</a>';
                }

                $options = osc_apply_filter('actions_manage_alerts', $options, $aRow);
                // create list of actions
                $auxOptions = '<ul>' . PHP_EOL;
                foreach ($options as $actual) {
                    $auxOptions .= '<li>' . $actual . '</li>' . PHP_EOL;
                }
                $auxOptions .= '</ul>' . PHP_EOL;

                $actions = '<div class="actions">' . $auxOptions . '</div>' . PHP_EOL;
                // second column
                $row['email'] =
                    '<a href="' . osc_admin_base_url(true) . '?page=items&userId=">' . osc_esc_html($aRow['s_email']) . '</a>'
                    . $actions;

                // third row

                $pieces     = array();
                $conditions = osc_get_raw_search((array)json_decode($aRow['s_search'], true));
                if (isset($conditions['sPattern']) && $conditions['sPattern'] != '') {
                    $pieces[] = sprintf(__('<b>Pattern:</b> %s'), osc_esc_html($conditions['sPattern']));
                }
                if (isset($conditions['aCategories']) && !empty($conditions['aCategories'])) {
                    $l         = min(count($conditions['aCategories']), 4);
                    $cat_array = array();
                    for ($c = 0; $c < $l; $c++) {
                        $cat_array[] = osc_esc_html($conditions['aCategories'][$c]);
                    }
                    if (count($conditions['aCategories']) > $l) {
                        $cat_array[] = '<a href="#" class="more-tooltip" categories="' . osc_esc_html(implode(
                            ', ',
                            $conditions['aCategories']
                        )) . '" >' . __('...More') . '</a>';
                    }

                    $pieces[] = sprintf(__('<b>Categories:</b> %s'), implode(', ', $cat_array));
                }

                $row['alert'] = implode(', ', $pieces);
                // fourth row
                $row['date'] = osc_format_date($aRow['dt_date']);

                $row = osc_apply_filter('alerts_processing_row', $row, $aRow);

                $this->addRow($row);
                $this->rawRows[] = $aRow;
            }
        }
    }
}
