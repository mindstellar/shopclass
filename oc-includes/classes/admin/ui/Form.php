<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin\ui;

/**
 * The scaffolding behind osc_admin_form_open()/_close(), osc_admin_form_section() and the
 * form-row pair. Those functions are the public, plugin-facing API and stay procedural;
 * this class is the implementation they delegate to, so the markup lives in one place.
 */
class Form
{
    /** @var list<string> Open bodies, innermost last: 'plain', 'main' or 'rail'. */
    private static array $bodies = array();

    private static int $sections = 0;

    /**
     * Open a form: the <form>, its hidden page/action/extra fields, and the horizontal
     * wrapper. Body of osc_admin_form_open().
     *
     * @param array<string,mixed> $opts
     *
     * @return void
     */
    public static function open(array $opts = array())
    {
        $method     = strtolower((string)($opts['method'] ?? 'post'));
        $horizontal = $opts['horizontal'] ?? true;

        echo '<form action="' . osc_esc_html($opts['url'] ?? osc_admin_base_url(true)) . '"'
            . ' method="' . osc_esc_html($method) . '"'
            . (!empty($opts['name']) ? ' name="' . osc_esc_html($opts['name']) . '"' : '')
            . (!empty($opts['id']) ? ' id="' . osc_esc_html($opts['id']) . '"' : '')
            . (!empty($opts['class']) ? ' class="' . osc_esc_html($opts['class']) . '"' : '')
            . (!empty($opts['upload']) ? ' enctype="multipart/form-data"' : '')
            . (isset($opts['csrf']) && !$opts['csrf'] ? ' nocsrf' : '')
            . '>';

        $hidden = $opts['fields'] ?? array();
        if ($method === 'post' || array_key_exists('page', $opts) || array_key_exists('action', $opts)) {
            $hidden = array_merge(
                array(
                    'page'   => $opts['page'] ?? \Params::getParam('page'),
                    'action' => $opts['action'] ?? null,
                ),
                $hidden
            );
        }
        foreach ($hidden as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            echo '<input type="hidden" name="' . osc_esc_html($name) . '"'
                . ' value="' . osc_esc_html($value) . '"/>';
        }

        if ($horizontal) {
            echo '<fieldset><div class="form-horizontal' . (!empty($opts['stack']) ? ' osc-stack' : '') . '">';
        }
    }

    /**
     * Close a form, optionally with its action row. Body of osc_admin_form_close().
     *
     * @param array<int,array<string,mixed>>|null $actions
     * @param array<string,mixed>                  $opts
     *
     * @return void
     */
    public static function close($actions = null, array $opts = array())
    {
        if (is_array($actions)) {
            osc_admin_form_actions($actions, $opts);
        }
        if ($opts['horizontal'] ?? true) {
            echo '</div></fieldset>';
        }
        echo '</form>';
    }

    /**
     * The id of a section heading, from its title: "Server rules (nginx)" is "s-server-rules-nginx".
     * The settings search links to it.
     *
     * @param string $title
     *
     * @return string
     */
    public static function anchor($title)
    {
        $slug = trim((string)preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower((string)$title)), '-');

        return 's-' . $slug;
    }

    /**
     * A heading inside a screen, drawn the one way every screen draws it: under the header's h1
     * title a section is an h2 and a sub-section an h3; under an in-page h2 title, h3 and h4.
     * The class sets the size, so both look the same. Body of osc_admin_section_heading().
     *
     * @param string      $text
     * @param string      $level  'section' or 'subsection'
     * @param string|null $id     the id to link to; null makes one from the text, '' leaves it off
     * @param bool        $spaced ignored; a stack spaces its sections with gap
     *
     * @return void
     */
    public static function heading($text, $level = 'section', $id = null, $spaced = false)
    {
        // Under a page title the header drew as the h1, a section is an h2; under an in-page h2 title, an h3.
        $top   = PageState::headerTitle() !== null ? 2 : 3;
        $tag   = 'h' . ($level === 'subsection' ? $top + 1 : $top);
        $id    = $id ?? self::anchor($text);
        $class = 'render-title ' . ($level === 'subsection' ? 'osc-subsection-title' : 'osc-section-title');
        echo '<' . $tag . ($id !== '' ? ' id="' . osc_esc_html($id) . '"' : '') . ' class="' . $class . '">'
            . osc_esc_html($text) . '</' . $tag . '>';
    }

    /**
     * A section heading with an optional intro paragraph. Body of osc_admin_form_section().
     *
     * @param string              $title
     * @param array<string,mixed> $opts
     *
     * @return void
     */
    public static function section($title, array $opts = array())
    {
        $level = (int)($opts['level'] ?? 3) === 2 ? 2 : 3;

        if ($title !== '' && $level === 3) {
            self::heading($title);
        } elseif ($title !== '') {
            echo '<h2 id="' . self::anchor($title) . '" class="render-title">' . osc_esc_html($title) . '</h2>';
        }
        self::intro($opts, 'form-intro');
    }

    /**
     * Print the intro paragraph of $opts, if it has one.
     *
     * @param array<string,mixed> $opts
     * @param string              $class
     */
    private static function intro(array $opts, string $class): void
    {
        if (!empty($opts['intro_html'])) {
            echo '<p class="' . $class . '">' . $opts['intro_html'] . '</p>';
        } elseif (!empty($opts['intro'])) {
            echo '<p class="' . $class . '">' . osc_esc_html($opts['intro']) . '</p>';
        }
    }

    /**
     * The id and extra attributes of a body or a section.
     *
     * @param array<string,mixed> $opts
     * @param string              $id
     */
    private static function idAndAttrs(array $opts, string $id): string
    {
        return ($id !== '' ? ' id="' . osc_esc_html($id) . '"' : '')
            . Field::attrsString($opts['attrs'] ?? array());
    }

    /**
     * Open a screen's body. Body of osc_admin_body_open().
     *
     * @param array<string,mixed> $opts 'layout' => 'stack', 'split' (record and rail, as on a billing
     *                                  order) or 'grid' (tiles, as on the dashboard); 'id', 'class', 'attrs'
     *
     * @since 7.1.0
     */
    public static function bodyOpen(array $opts = array()): void
    {
        $layout = $opts['layout'] ?? 'stack';
        $class  = 'osc-body'
            . ($layout === 'split' ? ' osc-body-split' : ($layout === 'grid' ? ' osc-body-grid' : ''))
            . (!empty($opts['class']) ? ' ' . $opts['class'] : '');

        echo '<div class="' . osc_esc_html($class) . '"' . self::idAndAttrs($opts, (string)($opts['id'] ?? '')) . '>';
        if ($layout === 'split') {
            echo '<div>';
        }
        self::$bodies[] = $layout === 'split' ? 'main' : 'plain';
    }

    /**
     * End a split body's main column and start its rail. Body of osc_admin_body_rail().
     *
     * @param array<string,mixed> $opts 'id', 'class', 'attrs'
     *
     * @since 7.1.0
     */
    public static function bodyRail(array $opts = array()): void
    {
        $last = array_key_last(self::$bodies);
        if ($last === null || self::$bodies[$last] !== 'main') {
            return;
        }
        self::$bodies[$last] = 'rail';

        $class = 'osc-body-rail' . (!empty($opts['class']) ? ' ' . $opts['class'] : '');
        echo '</div><aside class="' . osc_esc_html($class) . '"' . self::idAndAttrs($opts, (string)($opts['id'] ?? '')) . '>';
    }

    /**
     * Close the innermost open body. Body of osc_admin_body_close().
     *
     * @since 7.1.0
     */
    public static function bodyClose(): void
    {
        $state = array_pop(self::$bodies);
        if ($state === null) {
            return;
        }
        echo match ($state) {
            'main'  => '</div></div>',
            'rail'  => '</aside></div>',
            default => '</div>',
        };
    }

    /**
     * Open a section: heading, head actions, intro, then its body. Body of osc_admin_section().
     *
     * @param string              $title empty for a section with no heading
     * @param array<string,mixed> $opts  'intro'/'intro_html', 'actions', 'id', 'level' => 'section' or
     *                                   'subsection', 'card', 'class', 'attrs'
     *
     * @since 7.1.0
     */
    public static function sectionOpen(string $title, array $opts = array()): void
    {
        $id    = (string)($opts['id'] ?? ($title !== '' ? self::anchor($title) : ''));
        $class = 'osc-section'
            . (!empty($opts['card']) ? ' osc-section-card' : '')
            . (!empty($opts['class']) ? ' ' . $opts['class'] : '');

        echo '<section class="' . osc_esc_html($class) . '"' . self::idAndAttrs($opts, $id) . '>';

        $actions = $opts['actions'] ?? array();
        if (!empty($actions)) {
            echo '<div class="osc-section-head">';
        }
        if ($title !== '') {
            // The section carries the id, so the heading must not repeat it.
            self::heading($title, (string)($opts['level'] ?? 'section'), '');
        }
        if (!empty($actions)) {
            echo '<span class="osc-section-actions">';
            foreach ($actions as $action) {
                osc_admin_action_button($action);
            }
            echo '</span></div>';
        }
        self::intro($opts, 'form-intro osc-section-intro');

        echo '<div class="osc-section-body">';
        self::$sections++;
    }

    /**
     * Close the innermost open section. Body of osc_admin_section_close().
     *
     * @since 7.1.0
     */
    public static function sectionClose(): void
    {
        if (self::$sections === 0) {
            return;
        }
        self::$sections--;
        echo '</div></section>';
    }

    /**
     * A titled block of actions: a heading, an optional intro, optional body content, and a
     * list of actions each rendered as a button with its own help line. Body of
     * osc_admin_action_section(). For the "do a thing" panels (connection test, queue,
     * migration, cleanup, maintenance) that are not label|control forms.
     *
     * Each action is one of:
     *  - a confirm trigger: 'confirm' => '#dialog-id' (opens a osc_admin_confirm_dialog,
     *    which carries its own fields and submits itself — no wrapping form here);
     *  - its own mini-form: 'action' (and optional 'page'/'fields'/'name') → a submit button
     *    in a horizontal:false form;
     *  - a link: 'url';
     *  - a bare button otherwise.
     * plus 'label', 'variant', 'icon', 'attrs', and 'help'/'help_html'.
     *
     * @param array<string,mixed> $opts 'title', 'intro'/'intro_html', 'body_html' (before the
     *                            actions), 'actions' (list), 'footer_html' (after the actions)
     *
     * @return void
     */
    public static function actionSection(array $opts = array())
    {
        echo '<section class="osc-section settings-actions">';
        self::section($opts['title'] ?? '', $opts);

        if (!empty($opts['body_html'])) {
            echo $opts['body_html'];
        }

        foreach ($opts['actions'] ?? array() as $action) {
            echo '<div class="settings-action">';

            $btn = array(
                'label'   => $action['label'] ?? '',
                'variant' => $action['variant'] ?? 'secondary',
                'icon'    => $action['icon'] ?? null,
                'attrs'   => $action['attrs'] ?? array(),
            );

            if (!empty($action['confirm'])) {
                $btn['type']                          = 'button';
                $btn['attrs']['data-osc-dialog-open'] = $action['confirm'];
                osc_admin_action_button($btn);
            } elseif (!empty($action['url'])) {
                $btn['url'] = $action['url'];
                osc_admin_action_button($btn);
            } elseif (isset($action['action']) || isset($action['fields'])) {
                $btn['type'] = $action['type'] ?? 'submit';
                self::open(array(
                    'page'       => $action['page'] ?? null,
                    'action'     => $action['action'] ?? null,
                    'fields'     => $action['fields'] ?? array(),
                    'name'       => $action['name'] ?? null,
                    'horizontal' => false,
                ));
                osc_admin_action_button($btn);
                self::close(null, array('horizontal' => false));
            } else {
                $btn['type'] = $action['type'] ?? 'button';
                osc_admin_action_button($btn);
            }

            if (!empty($action['help_html'])) {
                echo '<p class="settings-action-help">' . $action['help_html'] . '</p>';
            } elseif (!empty($action['help'])) {
                echo '<p class="settings-action-help">' . osc_esc_html($action['help']) . '</p>';
            }

            echo '</div>';
        }

        if (!empty($opts['footer_html'])) {
            echo $opts['footer_html'];
        }

        echo '</section>';
    }

    /**
     * The select-all checkbox for a table's header. Its id is what the check-all script looks for.
     *
     * @since 7.1.0
     */
    public static function bulkHeader(): string
    {
        return '<input id="check_all" type="checkbox" aria-label="' . osc_esc_html(__('Select all')) . '" />';
    }

    /**
     * One row's bulk-action checkbox.
     *
     * @param string              $name  Input name, e.g. 'id[]'
     * @param mixed               $value Submitted value, usually the row id
     * @param string|null         $label Row name used in the "Select %s" aria-label
     * @param array<string,mixed> $attrs Extra attributes
     *
     * @since 7.1.0
     */
    public static function bulkCell(string $name, $value, ?string $label, array $attrs = array()): string
    {
        $html = '<input type="checkbox" name="' . osc_esc_html($name) . '" value="' . osc_esc_html((string) $value) . '"'
            . ' aria-label="' . osc_esc_html(sprintf(__('Select %s'), (string) $label)) . '"';
        foreach ($attrs as $attr => $val) {
            $html .= ' ' . osc_esc_html((string) $attr) . '="' . osc_esc_html((string) $val) . '"';
        }

        return $html . ' />';
    }

    /**
     * The status pill. $html prints as given, so a caller with a plain word escapes it first.
     *
     * @param string $state Lower-case state key, or '' for a pill with no state of its own
     * @param string $title Optional tooltip
     *
     * @since 7.1.0
     */
    public static function statusBadge(string $state, string $html, string $title = ''): string
    {
        return '<span class="osc-status' . ($state !== '' ? ' status-' . osc_esc_html($state) : '') . '"'
            . ($title !== '' ? ' title="' . osc_esc_html($title) . '"' : '') . '>' . $html . '</span>';
    }

    /**
     * A warning or note box. Body of osc_admin_callout(), which documents the arguments.
     *
     * @param array<string,mixed> $opts
     *
     * @since 7.1.0
     */
    public static function callout(string $tone, string $text, array $opts = array()): string
    {
        $class = 'callout-' . $tone
            . (($opts['block'] ?? true) ? ' callout-block' : '')
            . (!empty($opts['class']) ? ' ' . $opts['class'] : '');

        $tag = ($opts['tag'] ?? 'div') === 'span' ? 'span' : 'div';

        return '<' . $tag . Field::attrsString(array(
            'class' => $class,
            'id'    => ($opts['id'] ?? '') ?: null,
            'role'  => ($opts['role'] ?? '') ?: null,
        ) + (array)($opts['attrs'] ?? array())) . '>' . (!empty($opts['html']) ? $text : osc_esc_html($text)) . '</' . $tag . '>';
    }

    /**
     * A hand-built table. Body of osc_admin_table(), which documents the arguments.
     *
     * @param array<string,string|array<string,mixed>> $columns
     * @param array<int,array<string,mixed>>           $rows
     * @param array<string,mixed>                      $opts
     *
     * @since 7.1.0
     */
    public static function table(array $columns, array $rows, array $opts = array()): void
    {
        $wrap = $opts['wrap'] ?? 'responsive';
        if ($wrap !== false) {
            echo '<div' . Field::attrsString(array(
                'class' => $wrap === 'actions' ? 'table-contains-actions' : 'table-responsive',
                'id'    => ($opts['wrap_id'] ?? '') ?: null,
            )) . '>';
        }

        $attrs = $opts['attrs'] ?? array();
        echo '<table' . Field::attrsString(self::withClass(
            array_filter(array('id' => $opts['id'] ?? null)) + $attrs,
            'table',
            $opts['class'] ?? '',
            !empty($opts['stack']) ? 'osc-table-stack' : ''
        )) . '>';

        if ((string)($opts['caption'] ?? '') !== '') {
            echo '<caption class="visually-hidden">' . osc_esc_html((string)$opts['caption']) . '</caption>';
        }

        echo '<thead><tr>';
        $cellAttrs = array();
        foreach ($columns as $key => $column) {
            $column  = is_array($column) ? $column : array('label' => (string)$column);
            $numeric = !empty($column['numeric']) ? 'col-numeric' : '';
            $name    = $column['col_name'] ?? ($column['label'] ?? '');

            echo '<th' . Field::attrsString(array(
                'scope' => 'col',
                'class' => self::classList($numeric, $column['class'] ?? '', isset($column['sorted']) ? 'sorting_' . $column['sorted'] : ''),
            )) . '>' . self::tableHead($column) . '</th>';

            $cellAttrs[$key] = array(
                'class' => array($numeric, $column['class'] ?? '', $column['td_class'] ?? ''),
                'name'  => $name === false || $name === '' ? null : $name,
            );
        }
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $spec    = is_array($row['cells'] ?? null);
            $cells   = $spec ? $row['cells'] : $row;
            $trAttrs = $spec ? ($row['attrs'] ?? array()) : array();

            echo '<tr' . Field::attrsString(self::withClass($trAttrs, $spec ? ($row['class'] ?? '') : '')) . '>';
            self::tableRowCells($cellAttrs, $cells);
            echo '</tr>';
        }

        if ($rows === array() && is_array($opts['empty'] ?? null)) {
            osc_admin_table_empty(count($columns), $opts['empty']);
        }
        echo '</tbody></table>';

        if ($wrap === 'actions') {
            echo '<div id="table-row-actions"></div>';
        }
        if ($wrap !== false) {
            echo '</div>';
        }
    }

    /**
     * A table of DataTable output. Body of osc_admin_data_table(), which documents the options.
     * Titles and cells print as given: DataTable builds them and plugin filters may add markup.
     *
     * @param array<string,mixed> $aData DataTable::getData() shape
     * @param array<string,mixed> $opts
     *
     * @since 7.1.0
     */
    public static function dataTable(array $aData, array $opts = array()): void
    {
        $titles    = $aData['aColumns'] ?? array();
        $sort      = is_scalar($opts['sort'] ?? null) ? (string)$opts['sort'] : '';
        $sorted    = ($opts['direction'] ?? '') === 'desc' ? 'desc' : 'asc';

        $columns = array();
        foreach ($titles as $key => $title) {
            $columns[$key] = array(
                'label_html' => (string)$title,
                'class'      => 'col-' . $key,
                'sorted'     => $sort !== '' && (string)$key === $sort ? $sorted : null,
                'col_name'   => osc_admin_col_name($titles, $key),
            );
        }

        $rows = array();
        foreach ($aData['aRows'] ?? array() as $key => $row) {
            $class = isset($opts['row_class']) ? $opts['row_class']($key, $row) : '';
            $class = is_array($class) ? implode(' ', $class) : (string)$class;
            $state = preg_match('/(?:^|\s)status-(?!border(?:\s|$))([\w-]+)/', $class, $m) === 1 ? $m[1] : '';
            $cells = array();
            foreach (array_keys($columns) as $column) {
                $cells[$column] = array('html' => self::dataTableCell((string)$column, $row[$column] ?? null, $key, $state, $opts));
            }
            $rows[] = array(
                'cells' => $cells,
                'class' => $class,
                'attrs' => isset($opts['row_attrs']) ? $opts['row_attrs']($key, $row) : array(),
            );
        }

        self::table($columns, $rows, array(
            'wrap'    => 'actions',
            'wrap_id' => $opts['wrap_id'] ?? '',
            'class'   => $opts['class'] ?? '',
            'stack'   => $opts['stack'] ?? !array_key_exists('bulkactions', $titles),
            'empty'   => $opts['empty'] ?? null,
        ));
    }

    /**
     * One DataTable cell: the screen's own markup when its 'cell' callback gives any, else the
     * value, with status as a badge here so the processing_row filters still see the plain word.
     * The badge takes its state from the row's status-* class.
     *
     * @param mixed               $value
     * @param int|string          $row
     * @param array<string,mixed> $opts
     */
    private static function dataTableCell(string $column, $value, $row, string $state, array $opts): string
    {
        $html = isset($opts['cell']) ? $opts['cell']($column, $value, $row) : null;
        if ($html !== null) {
            return (string)$html;
        }

        return $column === 'status' && $value !== null
            ? self::statusBadge($state, (string)$value)
            : (string)$value;
    }

    /**
     * Print one row's cells. A 'colspan' cell takes the place of the columns it covers, never
     * running past the last column, so the row always fills the table.
     *
     * @param array<int|string,array<string,mixed>> $cellAttrs column key => class list and name
     * @param array<int|string,mixed>               $cells
     */
    private static function tableRowCells(array $cellAttrs, array $cells): void
    {
        $total    = count($cellAttrs);
        $position = 0;
        $spanEnd  = 0;
        foreach ($cellAttrs as $key => $attr) {
            $position++;
            if ($position <= $spanEnd) {
                continue;
            }

            $cell    = $cells[$key] ?? null;
            $spec    = is_array($cell) ? $cell : array();
            $span    = min(max(1, (int)($spec['colspan'] ?? 1)), $total - $position + 1);
            $spanEnd = $position + $span - 1;
            $tag     = !empty($spec['th']) ? 'th' : 'td';

            echo '<' . $tag . Field::attrsString(array(
                'scope'         => $tag === 'th' ? 'row' : null,
                'class'         => self::classList(...array_merge($attr['class'], array($spec['class'] ?? ''))),
                'colspan'       => $span > 1 ? (string)$span : null,
                'data-col-name' => $attr['name'],
            )) . '>';
            self::tableCell($cell);
            echo '</' . $tag . '>';
        }
    }

    /**
     * The inside of a column's header cell.
     *
     * @param array<string,mixed> $column
     */
    private static function tableHead(array $column): string
    {
        if (isset($column['label_html'])) {
            return (string)$column['label_html'];
        }
        $label = osc_esc_html((string)($column['label'] ?? ''));

        return !empty($column['hide_label']) ? '<span class="visually-hidden">' . $label . '</span>' : $label;
    }

    /**
     * Print one cell's content: a scalar escaped, or a trusted html, render or status cell.
     *
     * @param mixed $cell
     */
    private static function tableCell($cell): void
    {
        if (!is_array($cell)) {
            echo osc_esc_html((string)$cell);
        } elseif (isset($cell['html'])) {
            echo $cell['html'];
        } elseif (isset($cell['render'])) {
            ($cell['render'])();
        } elseif (isset($cell['status'])) {
            osc_admin_status($cell['status'][0], $cell['status'][1]);
        }
    }

    /**
     * The attributes with the given classes and any 'class' they already hold joined into one.
     *
     * @param array<string,mixed> $attrs
     *
     * @return array<string,mixed>
     */
    private static function withClass(array $attrs, string ...$classes): array
    {
        return array('class' => self::classList(...array_merge($classes, array((string)($attrs['class'] ?? ''))))) + $attrs;
    }

    /**
     * Join class names, skipping empty ones; null when none are left, which drops the attribute.
     */
    private static function classList(string ...$parts): ?string
    {
        return implode(' ', array_filter($parts)) ?: null;
    }

    /**
     * Open one labelled row. Body of osc_admin_form_row_open().
     *
     * @param string              $label
     * @param array<string,mixed> $opts
     *
     * @return void
     */
    public static function rowOpen($label = '', array $opts = array())
    {
        $class = 'form-row';
        if (!empty($opts['class'])) {
            $class .= ' ' . osc_esc_html($opts['class']);
        }
        if (($opts['layout'] ?? '') === 'stacked') {
            $class .= ' form-row-stacked';
        }

        $data_attrs = '';
        if (!empty($opts['data']) && is_array($opts['data'])) {
            foreach ($opts['data'] as $data_key => $data_value) {
                if (preg_match('/^[a-z0-9_-]+$/i', $data_key)) {
                    $data_attrs .= ' data-' . $data_key . '="' . osc_esc_html($data_value) . '"';
                }
            }
        }

        echo '<div class="' . $class . '"'
            . (!empty($opts['id']) ? ' id="' . osc_esc_html($opts['id']) . '"' : '')
            . (!empty($opts['style']) ? ' style="' . osc_esc_html($opts['style']) . '"' : '')
            . $data_attrs
            . '>';

        $label_html = $opts['label_html'] ?? '';
        if ($label !== '' || $label_html !== '') {
            $for = $opts['for'] ?? '';
            echo '<div class="form-label">';
            if ($label_html !== '') {
                echo $for !== ''
                    ? '<label for="' . osc_esc_html($for) . '">' . $label_html . '</label>'
                    : $label_html;
            } else {
                echo $for !== ''
                    ? '<label for="' . osc_esc_html($for) . '">' . osc_esc_html($label) . '</label>'
                    : osc_esc_html($label);
            }
            echo '</div>';
        }

        echo '<div class="form-controls'
            . (!empty($opts['controls_class']) ? ' ' . osc_esc_html($opts['controls_class']) : '')
            . '">';
    }

    /**
     * Close a row opened by rowOpen(). Body of osc_admin_form_row_close().
     *
     * @return void
     */
    public static function rowClose()
    {
        echo '</div></div>';
    }
}
