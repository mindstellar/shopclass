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
