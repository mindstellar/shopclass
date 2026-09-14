<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
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
            echo '<fieldset><div class="form-horizontal">';
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
        $class = 'render-title' . (!empty($opts['spaced']) ? ' separate-top' : '');

        if ($title !== '') {
            echo '<h' . $level . ' class="' . $class . '">' . osc_esc_html($title) . '</h' . $level . '>';
        }
        if (!empty($opts['intro_html'])) {
            echo '<p class="form-intro">' . $opts['intro_html'] . '</p>';
        } elseif (!empty($opts['intro'])) {
            echo '<p class="form-intro">' . osc_esc_html($opts['intro']) . '</p>';
        }
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
        echo '<section class="settings-actions">';
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
