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
 * The scaffolding behind osc_admin_editor_open()/_rail()/_close(),
 * osc_admin_publish_panel() and the disclosure pair. Those functions are the public,
 * plugin-facing API and stay procedural; this class holds the markup.
 *
 * An editing screen is a wide main column, a rail of panels beside it, and one save. Both
 * core editors used to hand-write that as Bootstrap columns, which is the grid the admin
 * theme is leaving. Here it is one CSS grid on .osc-editor, so the rail can be sticky on a
 * wide screen and come first on a narrow one without either screen knowing how.
 */
class Editor
{
    /** @var bool The main column is open and still has to be closed. */
    private static $mainOpen = false;

    /** @var bool The rail is open and still has to be closed. */
    private static $railOpen = false;

    /**
     * Open the editor: the form, the error summary, the grid, and the main column.
     * Body of osc_admin_editor_open().
     *
     * @param array<string,mixed> $opts
     *
     * @return void
     */
    public static function open(array $opts = array())
    {
        $form = $opts;
        unset(
            $form['errors'],
            $form['error_labels'],
            $form['error_ids'],
            $form['main_id'],
            $form['main_class']
        );
        $form['horizontal'] = false;
        osc_admin_form_open($form);

        self::errorSummary($opts['errors'] ?? array(), $opts);

        echo '<div class="osc-editor">';
        echo '<div class="osc-editor-main'
            . (!empty($opts['main_class']) ? ' ' . osc_esc_html($opts['main_class']) : '') . '"'
            . (!empty($opts['main_id']) ? ' id="' . osc_esc_html($opts['main_id']) . '"' : '')
            . '>';
        self::$mainOpen = true;
        self::$railOpen = false;
    }

    /**
     * Close the main column and open the rail. Body of osc_admin_editor_rail().
     *
     * @param array<string,mixed> $opts 'id', 'class'
     *
     * @return void
     */
    public static function rail(array $opts = array())
    {
        if (self::$mainOpen) {
            echo '</div>';
            self::$mainOpen = false;
        }

        echo '<div class="osc-editor-side'
            . (!empty($opts['class']) ? ' ' . osc_esc_html($opts['class']) : '') . '"'
            . (!empty($opts['id']) ? ' id="' . osc_esc_html($opts['id']) . '"' : '')
            . '>';
        self::$railOpen = true;
    }

    /**
     * Close whichever column is open, the grid, the save bar and the form.
     * Body of osc_admin_editor_close().
     *
     * @param array<int,array<string,mixed>>|null $actions Null for a form whose buttons are elsewhere
     * @param array<string,mixed>                 $opts    'dirty' => false for a plain action row
     *
     * @return void
     */
    public static function close($actions = array(), array $opts = array())
    {
        if (self::$railOpen || self::$mainOpen) {
            echo '</div>';
        }
        self::$mainOpen = false;
        self::$railOpen = false;
        echo '</div>';

        if (is_array($actions)) {
            echo '<div class="osc-editor-actions">';
            osc_admin_form_actions($actions, array('dirty' => $opts['dirty'] ?? true));
            echo '</div>';
        }

        osc_admin_form_close(null, array('horizontal' => false));
    }

    /**
     * The rail's status panel. Body of osc_admin_publish_panel().
     *
     * Never carries a Save: the form has one primary, and it is the bar at the foot. The
     * destructive actions sit in their own block after a rule, away from the routine ones.
     *
     * @param array<string,mixed> $opts
     *
     * @return void
     */
    public static function publishPanel(array $opts = array())
    {
        osc_admin_panel_open(
            (string)($opts['title'] ?? __('Status')),
            array(
                'class'   => 'osc-publish' . (!empty($opts['class']) ? ' ' . $opts['class'] : ''),
                'actions' => $opts['title_actions'] ?? array(),
            )
        );

        $status = $opts['status'] ?? array();
        if ($status !== array()) {
            echo '<div class="osc-publish-status">';
            foreach ($status as $pill) {
                osc_admin_status(
                    (string)($pill['state'] ?? $pill[0] ?? ''),
                    (string)($pill['word'] ?? $pill[1] ?? '')
                );
            }
            echo '</div>';
        }

        if (!empty($opts['rows'])) {
            osc_admin_definition($opts['rows']);
        }

        if (!empty($opts['body_html'])) {
            echo $opts['body_html'];
        }

        if (!empty($opts['actions'])) {
            echo '<div class="osc-publish-actions">';
            foreach ($opts['actions'] as $action) {
                osc_admin_action_button($action);
            }
            echo '</div>';
        }

        if (!empty($opts['danger'])) {
            echo '<div class="osc-publish-danger">';
            foreach ($opts['danger'] as $action) {
                $action['variant'] = $action['variant'] ?? 'outline-danger';
                osc_admin_action_button($action);
            }
            echo '</div>';
        }

        osc_admin_panel_close();
    }

    /**
     * A collapsible group. Body of osc_admin_disclosure_open().
     *
     * @param string              $title
     * @param array<string,mixed> $opts 'open', 'id', 'class', 'summary_hint'
     *
     * @return void
     */
    public static function disclosureOpen($title, array $opts = array())
    {
        echo '<details class="osc-disclosure'
            . (!empty($opts['class']) ? ' ' . osc_esc_html($opts['class']) : '') . '"'
            . (!empty($opts['id']) ? ' id="' . osc_esc_html($opts['id']) . '"' : '')
            . (!empty($opts['open']) ? ' open' : '') . '>';
        echo '<summary>' . osc_esc_html($title);
        if (!empty($opts['summary_hint'])) {
            echo '<span class="osc-disclosure-hint">' . osc_esc_html($opts['summary_hint']) . '</span>';
        }
        echo '</summary>';
        echo '<div class="osc-disclosure-body">';
    }

    /**
     * Close what disclosureOpen() opened. Body of osc_admin_disclosure_close().
     *
     * @return void
     */
    public static function disclosureClose()
    {
        echo '</div></details>';
    }

    /**
     * The summary at the head of the form: one line saying how many things need fixing,
     * then one link per field. Fills the same #error_list the client-side validator writes
     * to, so a server-rejected save and a caught-in-the-browser one read the same.
     *
     * @param array<string,string> $errors name => message
     * @param array<string,mixed>  $opts
     *
     * @return void
     */
    private static function errorSummary(array $errors, array $opts)
    {
        if ($errors === array()) {
            return;
        }

        $labels = $opts['error_labels'] ?? array();
        $ids    = $opts['error_ids'] ?? array();
        echo '<ul id="error_list" role="alert" style="display: block">';
        echo '<li><strong>' . osc_esc_html(
            count($errors) === 1
                ? __('1 thing needs fixing before this can be saved.')
                : sprintf(__('%d things need fixing before this can be saved.'), count($errors))
        ) . '</strong></li>';
        foreach ($errors as $name => $message) {
            $label = (string)($labels[$name] ?? $name);
            echo '<li><a href="#' . osc_esc_html((string)($ids[$name] ?? $name)) . '">'
                . osc_esc_html($label . ': ' . $message) . '</a></li>';
        }
        echo '</ul>';
    }
}

/* file end: ./oc-includes/osclass/classes/admin/ui/Editor.php */
