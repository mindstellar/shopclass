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
use mindstellar\admin\LogView;

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
            'from'    => self::dateParam('from'),
            'to'      => self::dateParam('to'),
        );

        $list = Log::getInstance()->search(
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
     * A Y-m-d request date, or '' when missing or not a real date.
     */
    public static function dateParam(string $name): string
    {
        $value = (string) Params::getParam($name);
        $d     = \DateTime::createFromFormat('!Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value ? $value : '';
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

        return '<span class="text-nowrap">' . $who . '</span>';
    }

    /**
     * A section or action cell: the plain label, with the stored code in the tooltip.
     */
    private function codeLabel(string $label, string $code): string
    {
        return '<span title="' . osc_esc_html($code) . '">' . osc_esc_html($label) . '</span>';
    }

    /**
     * The details cell: object id plus cleaned text.
     *
     * @param array<string,mixed> $aRow
     */
    private function detailsText(array $aRow): string
    {
        $details = osc_esc_html(LogView::cleanDetails((string) $aRow['s_data']));
        if ((int) $aRow['fk_i_id'] > 0) {
            $details = trim('<span class="text-muted">#' . (int) $aRow['fk_i_id'] . '</span> ' . $details);
        }

        return $details;
    }

    /**
     * Formats each log entry into table cells and keeps the raw row. Rows one action wrote
     * are folded under the first.
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

        foreach (LogView::fold($logs) as $group) {
            $aRow    = $group['head'];
            $details = $this->detailsText($aRow);
            if ($group['more'] !== array()) {
                $items = '';
                foreach ($group['more'] as $more) {
                    $items .= '<li>' . osc_esc_html(LogView::actionLabel((string) $more['s_action']) . ': '
                        . LogView::cleanDetails((string) $more['s_data'])) . '</li>';
                }
                $details .= '<details class="osc-log-more"><summary>'
                    . osc_esc_html(sprintf(
                        _n('%d more step', '%d more steps', count($group['more'])),
                        count($group['more'])
                    ))
                    . '</summary><ul>' . $items . '</ul></details>';
            }

            $row            = array();
            $row['date']    = osc_admin_date($aRow['dt_date']);
            $row['who']     = $this->whoLabel($aRow);
            $row['section'] = $this->codeLabel(LogView::sectionLabel((string) $aRow['s_section']), (string) $aRow['s_section']);
            $row['action']  = $this->codeLabel(LogView::actionLabel((string) $aRow['s_action']), (string) $aRow['s_action']);
            $row['details'] = $details;
            $row['ip']      = osc_esc_html($aRow['s_ip']);

            $row = osc_apply_filter('logs_processing_row', $row, $aRow);

            $this->addRow($row);
            $this->rawRows[] = $aRow;
        }
    }
}
