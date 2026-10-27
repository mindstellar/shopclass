<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\form\FormInputs;

/**
 * Class Form
 *
 * The base every core form class still extends. New code should build on
 * \mindstellar\form\FormInputs or \mindstellar\form\FormBuilder directly;
 * this class is not going away while the twelve core forms rest on it.
 *
 * @see \mindstellar\form\FormInputs
 */
class Form extends FormInputs
{
    protected $textClass = 'form-control form-control-sm';
    protected $selectClass = 'form-select form-select-sm';
    protected $passwordClass = 'form-control form-control-sm';

    /**
     * Echo a select box built from a list of rows.
     *
     * @param string                         $name
     * @param array<int,array<string,mixed>> $items
     * @param string                         $fld_key      Row key holding the option value
     * @param string                         $fld_name     Row key holding the option label
     * @param string|null                    $default_item Placeholder option label
     * @param string|int|null                $id           The currently selected value
     *
     * @return void
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
        echo self::withError($name, $attributes, static fn (array $a): string => (new self())->select($name, $id, $a, $options));
    }

    /**
     * Posted names whose refusal pointer is another name. A new field that prints its error
     * goes here when the names differ, else in MARKED_NAMES.
     */
    private const POINTER_NAMES = [
        'catId'        => 'category_id',
        'countryId'    => 'country',
        'countryName'  => 'country',
        'regionId'     => 'region',
        'regionName'   => 'region',
        'cityId'       => 'city',
        'cityName'     => 'city',
        'cityArea'     => 'city_area',
        'contactName'  => 'contact_name',
        'contactEmail' => 'contact_email',
        'contactPhone' => 'contact_phone',
    ];

    /** Posted names that are their own pointer and whose error a core form prints. */
    private const MARKED_NAMES = [
        'title', 'description', 'price', 'currency', 'address', 'zip', 'yourName', 'yourEmail',
        'phoneNumber', 'message', 'friendName', 'friendEmail',
    ];

    /** Posted names kept per language, whose pointer names the language: title[en_US] is /title/en_US. */
    private const LOCALE_NAMES = ['title', 'description'];

    /**
     * The pointer a field's name stands for in a refusal, e.g. catId is /category_id and
     * title[en_US] is /title/en_US.
     *
     * @internal
     *
     * @param string $name
     *
     * @return string
     */
    public static function errorPointer($name)
    {
        $base = (string) preg_replace('|\[.*$|', '', (string) $name);
        if (in_array($base, self::LOCALE_NAMES, true) && preg_match('|^[^\[]+\[([^\[\]/]+)\]$|', (string) $name, $m) === 1) {
            return '/' . $base . '/' . $m[1];
        }

        return '/' . (self::POINTER_NAMES[$base] ?? $base);
    }

    /**
     * Whether the core public forms print a field's message under its control. Anything else
     * (photos, options, custom fields) has to be told in the top line.
     *
     * @internal
     *
     * @param string $pointer
     *
     * @return bool
     */
    public static function marksPointer($pointer)
    {
        $base = (string) preg_replace('#^(' . implode('|', self::LOCALE_NAMES) . ')/[^/]+$#', '$1', substr((string) $pointer, 1));

        return str_starts_with((string) $pointer, '/')
            && (in_array($base, self::POINTER_NAMES, true) || in_array($base, self::MARKED_NAMES, true));
    }

    /**
     * Add aria-invalid and aria-describedby to a control whose field the last submit refused.
     *
     * @param string              $name
     * @param array<string,mixed> $attributes
     *
     * @return array<string,mixed>
     */
    public static function markInvalid($name, array $attributes)
    {
        if (osc_form_error(self::errorPointer($name)) === '') {
            return $attributes;
        }
        $attributes['aria-invalid']     = 'true';
        $attributes['aria-describedby'] = self::errorId($name, $attributes);

        return $attributes;
    }

    /**
     * The message line for a refused field, '' when the field was fine.
     *
     * @param string              $name
     * @param array<string,mixed> $attributes the control's, as markInvalid() returned them
     *
     * @return string
     */
    public static function errorLine($name, array $attributes = [])
    {
        $message = osc_form_error(self::errorPointer($name));
        if ($message === '') {
            return '';
        }

        return '<p class="oe-field-error" id="' . osc_esc_html(self::errorId($name, $attributes)) . '">'
            . osc_esc_html($message) . '</p>';
    }

    /**
     * A control with its refusal wired in: markInvalid() on its attributes, the control
     * $draw builds from them, then its errorLine().
     *
     * @param string                                $name
     * @param array<string,mixed>                   $attributes
     * @param callable(array<string,mixed>): string $draw
     *
     * @return string
     */
    protected static function withError($name, array $attributes, callable $draw)
    {
        $attributes = self::markInvalid($name, $attributes);

        return $draw($attributes) . self::errorLine($name, $attributes);
    }

    /**
     * @param string              $name
     * @param array<string,mixed> $attributes
     *
     * @return string
     */
    private static function errorId($name, array $attributes)
    {
        $id = (string) ($attributes['id'] ?? preg_replace('|([^_a-zA-Z0-9-]+)|', '', (string) $name));

        return $id . '-error';
    }

    /**
     * Echo a text input.
     *
     * @param string          $name
     * @param string|int|null $value
     * @param int|null        $maxLength
     * @param bool            $readOnly
     * @param bool            $autocomplete
     *
     * @return void
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
        echo self::withError($name, $attributes, static fn (array $a): string => (new self())->text($name, $value, $a));
    }

    /**
     * Echo a password input.
     *
     * @param string   $name
     * @param string   $value
     * @param int|null $maxLength
     * @param bool     $readOnly
     *
     * @return void
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
     * Echo a hidden input.
     *
     * @param string          $name
     * @param string|int|null $value
     *
     * @return void
     */
    protected static function generic_input_hidden($name, $value)
    {
        $attributes['id'] = preg_replace('|([^_a-zA-Z0-9-]+)|', '', $name);
        echo (new self())->hidden($name, $value, $attributes);
    }

    /**
     * Echo a checkbox input.
     *
     * @param string          $name
     * @param string|int|null $value
     * @param bool            $checked
     *
     * @return void
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
     * Echo a textarea.
     *
     * @param string      $name
     * @param string|null $value
     *
     * @return void
     */
    protected static function generic_textarea($name, $value)
    {
        $attributes['id'] = preg_replace('|([^_a-zA-Z0-9-]+)|', '', $name);
        echo self::withError($name, $attributes, static fn (array $a): string => (new self())->textarea($name, $value, $a));
    }

    /**
     * Build the vanilla submit-validation script for one form.
     * Rules and messages follow the jquery-validate shape and may be JS expressions or PHP arrays.
     *
     * @param string                          $formName The form's name attribute
     * @param string|array<string,mixed>      $rules    {field: {required, email, minlength, maxlength, digits, equalTo}}
     * @param string|array<string,mixed>      $messages {field: msg | {rule: msg}}
     * @param array{errorList?:string,scrollToList?:bool,reenableAfter?:int} $options
     *
     * @return string
     */
    protected static function validationJs(string $formName, $rules, $messages, array $options = []): string
    {
        $flags     = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
        $rules     = is_array($rules) ? (string) json_encode($rules, $flags) : $rules;
        $messages  = is_array($messages) ? (string) json_encode($messages, $flags) : $messages;
        $form      = (string) json_encode('form[name="' . $formName . '"]', $flags);
        $list      = (string) json_encode($options['errorList'] ?? '#error_list', $flags);
        $scroll    = !empty($options['scrollToList'])
            ? "if (container && container.scrollIntoView) { container.scrollIntoView({behavior: 'smooth', block: 'nearest'}); }"
            : "window.scrollTo({top: 0, behavior: 'smooth'});";
        $reenable  = (int) ($options['reenableAfter'] ?? 0);
        $reenable  = $reenable > 0
            ? 'setTimeout(function () { btns.forEach(function (b) { b.disabled = false; }); }, ' . $reenable . ');'
            : '';

        return <<<JS
(function (rules, messages) {
    var form = document.querySelector($form);
    if (!form) { return; }
    var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    var valueOf = function (name) {
        var el = form.querySelector('[name="' + name + '"]');
        return el ? (el.value == null ? '' : String(el.value)).trim() : '';
    };
    var msgFor = function (field, rule) {
        var m = messages[field];
        if (m == null) { return ''; }
        return (typeof m === 'string') ? m : (m[rule] || '');
    };
    var fieldError = function (name, spec) {
        var el = form.querySelector('[name="' + name + '"]');
        if (!el) { return null; }
        if (typeof spec === 'string') { spec = (spec === 'required') ? {required: true} : {}; }
        var v = valueOf(name);
        var mismatch = spec.equalTo && v !== valueOf(spec.equalTo);
        if (spec.required && v === '') { return {el: el, msg: msgFor(name, 'required')}; }
        if (v === '') { return mismatch ? {el: el, msg: msgFor(name, 'equalTo')} : null; }
        if (spec.minlength && v.length < spec.minlength) { return {el: el, msg: msgFor(name, 'minlength')}; }
        if (spec.maxlength && v.length > spec.maxlength) { return {el: el, msg: msgFor(name, 'maxlength')}; }
        if (spec.email && !emailRe.test(v)) { return {el: el, msg: msgFor(name, 'email')}; }
        if (spec.digits && !/^\d+$/.test(v)) { return {el: el, msg: msgFor(name, 'digits')}; }
        if (mismatch) { return {el: el, msg: msgFor(name, 'equalTo')}; }
        return null;
    };
    form.addEventListener('submit', function (e) {
        var errors = [];
        form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
        Object.keys(rules).forEach(function (name) {
            var err = fieldError(name, rules[name]);
            if (err) { errors.push(err); err.el.classList.add('is-invalid'); }
        });
        var container = document.querySelector($list);
        if (container) {
            container.innerHTML = '';
            errors.forEach(function (er) { var li = document.createElement('li'); li.textContent = er.msg; container.appendChild(li); });
        }
        if (errors.length) {
            e.preventDefault();
            $scroll
            if (errors[0].el.focus) { errors[0].el.focus(); }
        } else {
            var btns = form.querySelectorAll('button[type=submit], input[type=submit]');
            btns.forEach(function (b) { b.disabled = true; });
            $reenable
        }
    });
})($rules, $messages);
JS;
    }
}
