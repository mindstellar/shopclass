<?php

declare(strict_types=1);

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Installer form helpers: one field block, one show-password button, one checkbox row and one
 * alert panel, so no step pastes them.
 */

if (!function_exists('install_btn_icon')) {
    /**
     * Prints the direction icon of an action-bar button: 'back' (chevron) or 'next' (arrow).
     * Both mirror under dir="rtl".
     */
    function install_btn_icon(string $kind): void
    {
        $path = $kind === 'back' ? 'M10 3.5L5.5 8l4.5 4.5' : 'M3 8h10M9 4l4 4-4 4';
        echo '<svg class="ins-icon ins-btn-dir" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="' . $path . '"/></svg>';
    }
}

if (!function_exists('install_reveal_button')) {
    /**
     * Prints the show-password button for the input with this id.
     */
    function install_reveal_button(string $targetId, string $class = 'ins-input-btn'): void
    {
        $eye = '<svg viewBox="0 0 20 20" width="16" height="16" focusable="false"><path d="M1 10s3.5-6 9-6 9 6 9 6-3.5 6-9 6-9-6-9-6z" fill="none" stroke="currentColor" stroke-width="1.5" /><circle cx="10" cy="10" r="2.5" fill="none" stroke="currentColor" stroke-width="1.5" />';
        echo '<button type="button" class="' . osc_esc_html($class) . '" data-reveal-target="' . osc_esc_html($targetId) . '" aria-pressed="false" aria-label="' . osc_esc_html(__('Show password')) . '">'
            . '<span class="ins-icon-show" aria-hidden="true">' . $eye . '</svg></span>'
            . '<span class="ins-icon-hide" aria-hidden="true">' . $eye . '<path d="M2 2l16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" /></svg></span>'
            . '</button>';
    }
}

if (!function_exists('install_field')) {
    /**
     * Prints a label, input, help line and error line.
     *
     * Keys: id, name, label, type (text|email|password|select), value, options (select),
     * help, error, invalid, reveal, generate, opt, attrs (name => value), class.
     *
     * @param array<string, mixed> $f
     */
    function install_field(array $f): void
    {
        $id      = (string) $f['id'];
        $name    = (string) ($f['name'] ?? $id);
        $type    = (string) ($f['type'] ?? 'text');
        $value   = (string) ($f['value'] ?? '');
        $help    = (string) ($f['help'] ?? '');
        $error   = (string) ($f['error'] ?? '');
        $invalid = !empty($f['invalid']);
        $e       = static fn (string $s): string => osc_esc_html($s);

        $describe = array();
        if ($help !== '') {
            $describe[] = $id . '-help';
        }
        if ($invalid) {
            $describe[] = $id . '-error';
        }

        $attrs = '';
        foreach ((array) ($f['attrs'] ?? array()) as $k => $v) {
            $attrs .= ' ' . $e((string) $k) . '="' . $e((string) $v) . '"';
        }
        $common = ' id="' . $e($id) . '" name="' . $e($name) . '"'
            . ($invalid ? ' aria-invalid="true"' : '')
            . ($describe ? ' aria-describedby="' . $e(implode(' ', $describe)) . '"' : '')
            . $attrs;

        echo '<div class="ins-field' . (!empty($f['class']) ? ' ' . $e((string) $f['class']) : '') . '">';
        echo '<label for="' . $e($id) . '">' . $e((string) $f['label']);
        if (!empty($f['opt'])) {
            echo ' <span class="ins-opt">' . $e((string) $f['opt']) . '</span>';
        }
        echo '</label>';

        $wrap = $type === 'password' && !empty($f['reveal']);
        if ($wrap) {
            echo '<div class="ins-input-wrap">';
        }
        if ($type === 'select') {
            echo '<select class="ins-input"' . $common . '>';
            foreach ((array) ($f['options'] ?? array()) as $val => $text) {
                echo '<option value="' . $e((string) $val) . '"' . ((string) $val === $value ? ' selected' : '') . '>' . $e((string) $text) . '</option>';
            }
            echo '</select>';
        } else {
            echo '<input class="ins-input" type="' . $e($type) . '"' . $common . ' value="' . $e($value) . '" />';
        }
        if ($wrap) {
            install_reveal_button($id);
            echo '</div>';
        }
        if ($type === 'password' && !empty($f['generate'])) {
            // Hidden until the script that fills the field has loaded.
            echo '<button type="button" class="ins-text-btn" id="ins-gen-' . $e($id) . '" data-generate-target="' . $e($id) . '" hidden>'
                . '<svg class="ins-icon" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false"><path d="M13.5 8a5.5 5.5 0 11-1.6-3.9M13.5 2.5v3h-3" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" /></svg>'
                . '<span>' . $e(__('Make a strong password')) . '</span></button>';
        }
        if ($help !== '') {
            echo '<p class="ins-help" id="' . $e($id) . '-help">' . $e($help) . '</p>';
        }
        echo '<p class="ins-field-error" id="' . $e($id) . '-error"' . ($invalid ? '' : ' hidden') . '>'
            . '<svg viewBox="0 0 20 20" width="14" height="14" aria-hidden="true" focusable="false"><circle cx="10" cy="10" r="7.5" fill="none" stroke="currentColor" stroke-width="1.5" /><path d="M10 6v4.5M10 13.5v.01" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" /></svg>'
            . '<span>' . $e($error) . '</span></p>';
        echo '</div>';
    }
}

if (!function_exists('install_check')) {
    /**
     * Prints a checkbox row with a title and an optional help line.
     *
     * Keys: id, name, value ('1'), title, help, checked, invalid.
     *
     * @param array<string, mixed> $f
     */
    function install_check(array $f): void
    {
        $e    = static fn (string $s): string => osc_esc_html($s);
        $id   = (string) $f['id'];
        $help = (string) ($f['help'] ?? '');

        echo '<label class="ins-check" for="' . $e($id) . '">'
            . '<input type="checkbox" id="' . $e($id) . '" name="' . $e((string) ($f['name'] ?? $id)) . '" value="' . $e((string) ($f['value'] ?? '1')) . '"'
            . (!empty($f['checked']) ? ' checked="checked"' : '')
            . (!empty($f['invalid']) ? ' aria-invalid="true"' : '')
            . ' /><span><span class="ins-check-title">' . $e((string) $f['title']) . '</span>'
            . ($help !== '' ? '<span class="ins-help">' . $e($help) . '</span>' : '')
            . '</span></label>';
    }
}

if (!function_exists('install_panel')) {
    /**
     * Prints an alert panel with an icon, a title and a text line.
     *
     * $tone is 'danger' or 'warning'. Options: id, hidden, role (default alert), html_text
     * (print $text as given; it must already be safe). An empty title or text is left out.
     *
     * @param array<string, mixed> $opt
     */
    function install_panel(string $tone, string $title, string $text, array $opt = array()): void
    {
        $e    = static fn (string $s): string => osc_esc_html($s);
        $tone = $tone === 'warning' ? 'warning' : 'danger';
        $id   = (string) ($opt['id'] ?? '');

        echo '<div' . ($id !== '' ? ' id="' . $e($id) . '"' : '') . ' class="ins-panel ins-panel-' . $tone . '" role="' . $e((string) ($opt['role'] ?? 'alert')) . '" tabindex="-1"'
            . (!empty($opt['hidden']) ? ' hidden' : '') . '>'
            . '<svg class="ins-icon ins-panel-icon" viewBox="0 0 16 16" width="20" height="20" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 1.8l6.5 11.4h-13z"/><path d="M8 6.5v3M8 11.5v.1"/></svg>'
            . '<div class="ins-panel-body">'
            . ($title !== '' ? '<p class="ins-panel-title">' . $e($title) . '</p>' : '')
            . '<p class="ins-panel-text">' . (!empty($opt['html_text']) ? $text : $e($text)) . '</p>'
            . '</div></div>';
    }
}
