<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * LogsDataTable — read-only admin listing of the activity log (t_log).
 * KeywordBlocksDataTable-shaped, minus bulk/row actions: the log is a report,
 * not an editable list. Section and free-text filters come off the request.
 */
class LogsDataTable extends DataTable
{
    private $order_by;

    /**
     * Map a sortable datatable column to its t_log column.
     *
     * @var array<string,string>
     */
    private $column_names = array(
        'date'    => 'dt_date',
        'section' => 's_section',
        'action'  => 's_action',
        'who'     => 's_who',
        'ip'      => 's_ip',
    );

    /**
     * @param array $params
     *
     * @return array
     */
    public function table($params)
    {
        $this->addTableHeader();
        $this->getDBParams($params);

        $filters = array(
            'section' => (string) Params::getParam('section'),
            'q'       => (string) Params::getParam('q'),
        );

        $list = Log::newInstance()->search(
            $this->start,
            $this->limit,
            $this->order_by['column_name'],
            $this->order_by['type'],
            $filters
        );

        $this->processData($list['logs']);
        $this->totalFiltered = $list['total_results'];
        $this->total         = $list['rows'];

        return $this->getData();
    }

    private function addTableHeader()
    {
        $this->addColumn('date', __('Date'));
        $this->addColumn('who', __('Who'));
        $this->addColumn('section', __('Section'));
        $this->addColumn('action', __('Action'));
        $this->addColumn('details', __('Details'));
        $this->addColumn('ip', __('IP'));

        $dummy = &$this;
        osc_run_hook('admin_logs_table', $dummy);
    }

    /**
     * @param array $_get
     */
    private function getDBParams($_get)
    {
        if (!isset($_get['iDisplayStart'])) {
            $_get['iDisplayStart'] = 0;
        }
        if (!isset($_get['iDisplayLength']) || !is_numeric($_get['iDisplayLength'])) {
            $_get['iDisplayLength'] = 20;
        }
        $p_iPage = 1;
        if (!is_numeric(Params::getParam('iPage')) || Params::getParam('iPage') < 1) {
            Params::setParam('iPage', $p_iPage);
            $this->iPage = $p_iPage;
        } else {
            $this->iPage = Params::getParam('iPage');
        }

        $this->order_by['column_name'] = 'dt_date';
        $this->order_by['type']        = 'DESC';
        foreach ($_get as $k => $v) {
            /* for sorting */
            if ($k === 'iSortCol_0' && isset($this->column_names[$v])) {
                $this->order_by['column_name'] = $this->column_names[$v];
            }
            if ($k === 'sSortDir_0') {
                $this->order_by['type'] = $v;
            }
        }
        // set start and limit using iPage param
        $start = ($this->iPage - 1) * $_get['iDisplayLength'];

        $this->start = (int) $start;
        $this->limit = (int) $_get['iDisplayLength'];
    }

    /**
     * Render "who" as the actor plus its id, when present.
     *
     * @param array $aRow
     *
     * @return string
     */
    private function whoLabel($aRow)
    {
        $who = osc_esc_html($aRow['s_who']);
        if ((int) $aRow['fk_i_who_id'] > 0) {
            $who .= ' <span class="log-id">#' . (int) $aRow['fk_i_who_id'] . '</span>';
        }

        return $who;
    }

    /**
     * @param array $logs
     */
    private function processData($logs)
    {
        if (empty($logs)) {
            return;
        }

        foreach ($logs as $aRow) {
            $details = osc_esc_html($aRow['s_data']);
            if ((int) $aRow['fk_i_id'] > 0) {
                $details = '<span class="log-id">#' . (int) $aRow['fk_i_id'] . '</span> ' . $details;
            }

            $section = trim((string) $aRow['s_section']);

            $row            = array();
            $row['date']    = '<span class="log-when">' . osc_esc_html($aRow['dt_date']) . '</span>';
            $row['who']     = $this->whoLabel($aRow);
            $row['section'] = $section === ''
                ? '<span class="text-muted">&mdash;</span>'
                : '<span class="log-tag">' . osc_esc_html($section) . '</span>';
            $row['action']  = '<span class="log-action">' . osc_esc_html($aRow['s_action']) . '</span>';
            $row['details'] = $details;
            $row['ip']      = '<span class="log-ip">' . osc_esc_html($aRow['s_ip']) . '</span>';

            $row = osc_apply_filter('logs_processing_row', $row, $aRow);

            $this->addRow($row);
            $this->rawRows[] = $aRow;
        }
    }
}
