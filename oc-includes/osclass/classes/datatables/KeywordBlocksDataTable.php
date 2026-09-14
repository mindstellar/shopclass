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
 * KeywordBlocksDataTable — admin listing of the keyword blocklist.
 * BanRulesDataTable-shaped.
 */
class KeywordBlocksDataTable extends DataTable
{
    private $order_by;

    /**
     * Header column id => the t_keyword_block column it sorts by.
     *
     * @var array<string,string>
     */
    private $sortable = array(
        'keyword'   => 's_keyword',
        'scope'     => 's_scope',
        'substring' => 'b_substring',
    );

    /**
     * Builds the keyword-blocklist listing for the admin datatable.
     *
     * @param array<string,mixed> $params Datatable request params (iPage, iDisplayLength, sort, direction)
     *
     * @return array<string,mixed> The getData() payload
     */
    public function table($params)
    {
        $this->addTableHeader();
        $this->getDBParams($params);

        $list = KeywordBlock::newInstance()->search(
            $this->start,
            $this->limit,
            $this->order_by['column_name'],
            $this->order_by['type']
        );

        $this->processData($list['keywords']);
        $this->totalFiltered = $list['total_results'];
        $this->total         = $list['rows'];

        return $this->getData();
    }

    /**
     * Registers the keyword columns and lets plugins extend them via admin_keyword_block_table.
     *
     * @return void
     */
    private function addTableHeader()
    {
        $this->addColumn('bulkactions', '<input id="check_all" type="checkbox" />');
        $this->addColumn('keyword', __('Keyword'));
        $this->addColumn('scope', __('Matches in'));
        $this->addColumn('substring', __('Match type'));

        $dummy = &$this;
        osc_run_hook('admin_keyword_block_table', $dummy);
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
        $p_iPage = 1;
        if (!is_numeric(Params::getParam('iPage')) || Params::getParam('iPage') < 1) {
            Params::setParam('iPage', $p_iPage);
            $this->iPage = $p_iPage;
        } else {
            $this->iPage = Params::getParam('iPage');
        }

        $this->order_by = $this->resolveOrder($_get, $this->sortable, 'pk_i_id');
        // set start and limit using iPage param
        $start = ($this->iPage - 1) * $_get['iDisplayLength'];

        $this->start = (int)$start;
        $this->limit = (int)$_get['iDisplayLength'];
    }

    /**
     * Returns the translated label for a blocklist scope, defaulting to title and description.
     *
     * @param string $scope One of title, description, meta, all
     *
     * @return string
     */
    private function scopeLabel($scope)
    {
        switch ($scope) {
            case 'title':
                return __('Title only');
            case 'description':
                return __('Description only');
            case 'meta':
                return __('Custom fields');
            case 'all':
            default:
                return __('Title and description');
        }
    }

    /**
     * Formats each blocked keyword into table cells and keeps the raw row.
     *
     * @param array<int,array<string,mixed>> $keywords
     *
     * @return void
     */
    private function processData($keywords)
    {
        if (empty($keywords)) {
            return;
        }

        foreach ($keywords as $aRow) {
            $options = array();

            $options[] = '<a href="' . osc_admin_base_url(true)
                . '?page=settings&action=keyword_block_edit&amp;id=' . $aRow['pk_i_id'] . '">' . __('Edit') . '</a>';
            $options[] = '<a onclick="return delete_dialog(\'' . $aRow['pk_i_id'] . '\');" href="'
                . osc_admin_base_url(true) . '?page=settings&action=keyword_block_delete&amp;id[]='
                . $aRow['pk_i_id'] . '">' . __('Delete') . '</a>';

            $options = osc_apply_filter('actions_manage_keyword_block', $options, $aRow);

            $auxOptions = '<ul>' . PHP_EOL;
            foreach ($options as $actual) {
                $auxOptions .= '<li>' . $actual . '</li>' . PHP_EOL;
            }
            $auxOptions .= '</ul>' . PHP_EOL;
            $actions = '<div class="actions">' . $auxOptions . '</div>' . PHP_EOL;

            $row                = array();
            $row['bulkactions'] = '<input type="checkbox" name="id[]" value="' . $aRow['pk_i_id'] . '" /></div>';
            $row['keyword']     = osc_esc_html($aRow['s_keyword']) . $actions;
            $row['scope']       = osc_esc_html($this->scopeLabel($aRow['s_scope']));
            $row['substring']   = ((int)$aRow['b_substring'] === 1)
                ? osc_esc_html(__('Substring'))
                : osc_esc_html(__('Whole word'));

            $row = osc_apply_filter('keyword_block_processing_row', $row, $aRow);

            $this->addRow($row);
            $this->rawRows[] = $aRow;
        }
    }
}
