<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
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
use mindstellar\admin\ListPaging;

class LogsDataTable extends DataTable
{
    private $order_by;

    /**
     * Header column id => the t_log column it sorts by.
     *
     * @var array<string,string>
     */
    private $sortable = array(
        'date'    => 'dt_date',
        'section' => 's_section',
        'action'  => 's_action',
        'who'     => 's_who',
        'ip'      => 's_ip',
    );

    /**
     * Builds the activity-log listing, applying the section and free-text request filters.
     *
     * @param array<string,mixed> $params Datatable request params (iPage, iDisplayLength, sort, direction)
     *
     * @return array<string,mixed> The getData() payload
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

    /**
     * Registers the log columns and lets plugins extend them via admin_logs_table.
     *
     * @return void
     */
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
     * Derives page, start, limit and ordering from the request params.
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
        $this->iPage = ListPaging::page();

        $this->order_by = $this->resolveOrder($_get, $this->sortable, 'dt_date');
        // This screen shows 20 a page rather than the usual 10: a log line is one row of
        // short fields, and the point of opening it is to scan.
        $this->limit = ListPaging::length(20);
        $this->start = ListPaging::start($this->iPage, $this->limit);
    }

    /**
     * Render "who" as the actor plus its id, when present.
     *
     * @param array<string,mixed> $aRow
     *
     * @return string
     */
    private function whoLabel($aRow)
    {
        $who = osc_esc_html($aRow['s_who']);
        if ((int) $aRow['fk_i_who_id'] > 0) {
            $who .= ' <span class="text-muted">#' . (int) $aRow['fk_i_who_id'] . '</span>';
        }

        return $who;
    }

    /**
     * Formats each log entry into table cells and keeps the raw row.
     *
     * @param array<int,array<string,mixed>> $logs
     *
     * @return void
     */
    private function processData($logs)
    {
        if (empty($logs)) {
            return;
        }

        foreach ($logs as $aRow) {
            $details = osc_esc_html($aRow['s_data']);
            if ((int) $aRow['fk_i_id'] > 0) {
                $details = '<span class="text-muted">#' . (int) $aRow['fk_i_id'] . '</span> ' . $details;
            }

            $row            = array();
            $row['date']    = osc_admin_date($aRow['dt_date']);
            $row['who']     = $this->whoLabel($aRow);
            $row['section'] = osc_esc_html($aRow['s_section']);
            $row['action']  = osc_esc_html($aRow['s_action']);
            $row['details'] = $details;
            $row['ip']      = osc_esc_html($aRow['s_ip']);

            $row = osc_apply_filter('logs_processing_row', $row, $aRow);

            $this->addRow($row);
            $this->rawRows[] = $aRow;
        }
    }
}
