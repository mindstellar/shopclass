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

use mindstellar\form\base\FormInputs;

/**
 * Class Form
 * For compatibility
 * Use \mindstellar\form\base\FormInputs or \mindstellar\form\base\FormBuilder instead
 */
class Form extends FormInputs
{
    protected $textClass = 'form-control form-control-sm';
    protected $selectClass = 'form-select form-select-sm';
    protected $passwordClass = 'form-control form-control-sm';

    /**
     * @param $name
     * @param $items
     * @param $fld_key
     * @param $fld_name
     * @param $default_item
     * @param $id
     */
    protected static function generic_select($name, $items, $fld_key, $fld_name, $default_item, $id)
    {
        $newItems = [];
        foreach ($items as $k => $item) {
            if (isset($fld_key, $fld_name)) {
                $newItems[$item[$fld_key]] = $item[$fld_name];
                unset($items[$k]);
            }
        }
        $attributes['id']             = preg_replace('|([^_a-zA-Z0-9-]+)|', '', $name);
        $options['defaultValue']      = $id;
        $options['selectPlaceholder'] = $default_item;
        $options['selectOptions'] = $newItems;
        echo (new self())->select($name, $id, $attributes, $options);
    }

    /**
     * @param      $name
     * @param      $value
     * @param null $maxLength
     * @param bool $readOnly
     * @param bool $autocomplete
     */
    protected static function generic_input_text(
        $name,
        $value,
        $maxLength = null,
        $readOnly = false,
        $autocomplete = true
    ) {
        $attributes['id'] = preg_replace('|([^_a-zA-Z0-9-]+)|', '', $name);
        if (isset($maxLength)) {
            $attributes['maxlength'] = $maxLength;
        }
        if ($readOnly) {
            $attributes['readonly'] = 'readonly';
            $attributes['disabled'] = 'disabled';
        }

        if (!$autocomplete) {
            $attributes['autocomplete'] = 'off';
        }
        echo (new self())->text($name, $value, $attributes);
    }

    /**
     * @param      $name
     * @param      $value
     * @param null $maxLength
     * @param bool $readOnly
     */
    protected static function generic_password($name, $value, $maxLength = null, $readOnly = false)
    {
        $attributes['id'] = preg_replace('|([^_a-zA-Z0-9-]+)|', '', $name);
        if (isset($maxLength)) {
            $attributes['maxlength'] = $maxLength;
        }
        if ($readOnly) {
            $attributes['readonly'] = 'readonly';
            $attributes['disabled'] = 'disabled';
        }
        echo (new self())->password($name, $value, $attributes);
    }

    /**
     * @param $name
     * @param $value
     */
    protected static function generic_input_hidden($name, $value)
    {
        $attributes['id'] = preg_replace('|([^_a-zA-Z0-9-]+)|', '', $name);
        echo (new self())->hidden($name, $value, $attributes);
    }

    /**
     * @param      $name
     * @param      $value
     * @param bool $checked
     */
    protected static function generic_input_checkbox($name, $value, $checked = false)
    {
        $attributes['id']           = preg_replace('|([^_a-zA-Z0-9-]+)|', '', $name);
        if ($checked != false) {
            $attributes['checked'] = 'checked';
        }
        echo (new self())->checkbox($name, $value, $attributes);

    }

    /**
     * @param $name
     * @param $value
     */
    protected static function generic_textarea($name, $value)
    {
        $attributes['id'] = preg_replace('|([^_a-zA-Z0-9-]+)|', '', $name);
        echo (new self())->textarea($name, $value, $attributes);
    }
}
