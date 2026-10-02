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
 * The rendering behind the osc_admin_field() family. Those functions are the public,
 * plugin-facing API and stay procedural; this class is the implementation they delegate to,
 * so the field logic lives in one testable object instead of a spread of free functions.
 *
 * A Field is built from the same spec array the helpers take (see osc_admin_field()); the
 * static methods back the lower-level helpers that pass a type or id in explicitly.
 */
class Field
{
    /** The types a browser applies maxlength to; on the rest the attribute is ignored. */
    private const LENGTH_HINT_TYPES = array('text', 'email', 'url', 'tel', 'secret', 'textarea');

    /**
     * The types whose stored value is escaped on save, so its length is not the one the
     * browser counted. Mirrors SettingsPageRegistry::PURIFIED_TYPES, copied rather than
     * imported so a field primitive need not know about the settings registry.
     */
    private const PURIFIED_TYPES = array('text', 'textarea', 'tel', 'color', 'hidden', 'richtext');

    /** The types one value per locale can be spread over. */
    private const TRANSLATED_TYPES = array('text', 'textarea', 'richtext');

    /** @var array<string,mixed> */
    private $spec;

    /** @var string */
    private $type;

    /** @var string */
    private $id;

    /**
     * @param array<string,mixed> $spec
     */
    public function __construct(array $spec)
    {
        $this->spec = $spec;
        $this->type = $spec['type'] ?? 'text';
        $this->id   = self::idFor($spec);
    }

    /**
     * One labelled field, row and all. The body of osc_admin_field().
     *
     * @return void
     */
    public function render()
    {
        $type    = $this->type;
        $id      = $this->id;
        $spec    = $this->spec;
        $locales = self::localesFor($spec);
        // The editors stack a field -- label above control, no label column -- because a
        // body that fills the column has nothing to sit beside. A row is the settings
        // shape and stays the default.
        $stacked = $type !== 'hidden' && ($spec['layout'] ?? '') === 'stacked';
        // A hidden input has nothing to label and nothing to hint at; a row around it would
        // draw an empty label column and a gap where no control is, and a help box under it
        // would explain something nobody can see.
        $row     = $type === 'hidden' || $stacked ? false : ($spec['row'] ?? true);

        if ($stacked && $locales === array()) {
            echo '<div class="osc-field">';
            self::stackedLabel($id, $spec);
        }

        if ($row) {
            // A checkbox carries its own label beside the control; anything else labels the
            // row. Passing both would print the label twice. A row_label on any other type
            // labels the row instead of the field's own label, for the field whose row says
            // one thing ("Other comment settings") and whose errors have to say another
            // ("Comments per page must be 0 or more").
            $rowLabel = $type === 'checkbox'
                ? ($spec['row_label'] ?? '')
                : ($spec['row_label'] ?? $spec['label'] ?? '');
            // Neither a choice list nor a custom field has one control to point at -- each
            // radio owns its own label, and what a custom field draws is the declaration's
            // business -- so the row label stays plain text. A `for` pointing at an id
            // nothing carries reaches nothing.
            $opts = in_array($type, array('radio', 'custom'), true)
                ? array()
                : array('for' => $locales === array() ? $id : self::idFor(array(
                    'name' => self::translatedName($spec, (string)array_key_first($locales)),
                )));
            // The marked-up form of the row label, for the label carrying an <em> or a
            // link. It is raw markup and so is the declaration's to get right, exactly as
            // it already is on osc_admin_form_row_open().
            if (isset($spec['label_html']) && $spec['label_html'] !== '') {
                $opts['label_html'] = $spec['label_html'];
            }
            // The row is what the shared script shows and hides, so the relationship is
            // declared on it. Which way it resolves is decided again on save: this is a
            // convenience, not the rule.
            if (!empty($spec['depends'])) {
                $opts['data'] = array('osc-depends' => (string)$spec['depends']);
                // Hex-escaped so no "&" reaches osc_esc_html(), which leaves an existing
                // entity alone and would hand the script a different string.
                if (isset($spec['depends_value'])) {
                    $opts['data']['osc-depends-value'] = json_encode(
                        array_values(array_map('strval', (array)$spec['depends_value'])),
                        JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
                    );
                }
            }
            osc_admin_form_row_open($rowLabel, $opts);
        }

        if ($type === 'checkbox') {
            $spec['id'] = $id;
            osc_admin_checkbox($spec);
            self::errorText($id, $spec);
        } elseif ($locales !== array()) {
            self::translated($id, $spec, $locales);
            self::help($spec);
        } else {
            // The words either side of a field, and a secret's reveal button, sit on the
            // control's own line. The inputs are display:block, so without this they drop
            // underneath. Each affix is a whole phrase the translator can read, which is the
            // point: the sentence is never split around an opaque %s they cannot move.
            $prefix = (string)($spec['prefix'] ?? '');
            $suffix = (string)($spec['suffix'] ?? '');
            $inline = $prefix !== '' || $suffix !== ''
                || ($type === 'secret' && !empty($spec['reveal']));
            if ($inline) {
                echo '<div class="field-inline">';
            }
            // Neither a choice list nor a custom field has one control to name, so their
            // affixes stay plain text.
            $affixFor = in_array($type, array('radio', 'custom'), true)
                ? ''
                : ' for="' . osc_esc_html($id) . '"';
            if ($prefix !== '') {
                echo '<label class="field-prefix"' . $affixFor . '>' . osc_esc_html($prefix) . '</label>';
            }

            self::control($type, $id, $spec);

            if ($suffix !== '') {
                echo '<label class="field-suffix"' . $affixFor . '>' . osc_esc_html($suffix) . '</label>';
            }
            if ($inline) {
                echo '</div>';
            }

            if ($type !== 'hidden') {
                self::errorText($id, $spec);
                self::help($spec);
            }
        }

        if ($row) {
            osc_admin_form_row_close();
        }
        if ($stacked && $locales === array()) {
            echo '</div>';
        }
    }

    /**
     * The label above a stacked field, marked when the field is required.
     *
     * @param string              $id
     * @param array<string,mixed> $spec
     *
     * @return void
     */
    private static function stackedLabel($id, array $spec)
    {
        $type = $spec['type'] ?? 'text';
        if ($type === 'checkbox') {
            // It carries its own label beside the control; a second one above would say
            // the same thing twice.
            return;
        }
        $label = (string)($spec['label'] ?? '');
        if ($label === '' && empty($spec['label_html'])) {
            return;
        }

        $class = 'form-label' . (empty($spec['required']) ? '' : ' osc-field-required');
        // Neither a choice list nor a custom field has one control to point at.
        $for   = in_array($type, array('radio', 'custom'), true)
            ? ''
            : ' for="' . osc_esc_html($id) . '"';
        echo '<label class="' . $class . '"' . $for . '>'
            . (empty($spec['label_html']) ? osc_esc_html($label) : $spec['label_html'])
            . '</label>';
    }

    /**
     * The control alone, without its row, label or hint. One escaping path and one width
     * decision for every field type.
     *
     * @param string              $type
     * @param string              $id
     * @param array<string,mixed> $spec
     *
     * @return void
     */
    public static function control($type, $id, array $spec)
    {
        $name  = (string)($spec['name'] ?? '');
        $value = $spec['value'] ?? ($spec['selected'] ?? '');
        $extra = $spec['attrs'] ?? array();
        // Two spellings for one cap. The declared key is the one the save enforces, so it
        // is the number the control shows; the attrs copy is dropped rather than emitted
        // beside it.
        if (isset($spec['maxlength'], $extra['maxlength'])) {
            unset($extra['maxlength']);
        }
        $attrs = self::attrsString($extra);

        // A hidden input is barred from constraint validation, so 'required' on one is a
        // promise the browser ignores. The save enforces it either way.
        //
        // A conditional field says it only where something can lift it again: the browser
        // refuses to submit a form holding a required control it cannot show, and it does
        // so silently -- no submit event, so the page's own validator never runs and never
        // reports anything, and the button looks dead. The shared script lifts the flag
        // while the row is off and puts it back with the row, but it finds the row by the
        // data-osc-depends attribute, which is emitted only when this draws the row. A
        // dependent field drawing no row of its own is hidden by whatever page put it
        // there, and nothing would give the flag back.
        $lifted = empty($spec['depends']) || ($spec['row'] ?? true);
        // An image already stored satisfies 'required', so the picker may be left empty.
        $kept = $type === 'image' && (string)$value !== '';
        // A rich-text control is hidden by the editor that replaces it, and the browser
        // silently refuses to submit a form holding a required control it cannot show --
        // no submit event, so the page's own validator never runs and the button looks
        // dead. The label still says required and the save still enforces it.
        $mounted = $type === 'richtext';
        if (!empty($spec['required']) && $type !== 'hidden' && $lifted && !$kept && !$mounted) {
            $attrs .= ' required';
        }
        if (!empty($spec['disabled'])) {
            $attrs .= ' disabled';
        }
        // The control says it is wrong and points at the sentence saying why, so a screen
        // reader announces the message with the field instead of leaving it as loose text.
        if ($type !== 'hidden' && self::errorFor($spec) !== '') {
            $attrs .= ' aria-invalid="true" aria-describedby="' . osc_esc_html($id . '-error') . '"';
        }
        if (isset($spec['placeholder']) && $type !== 'select') {
            $attrs .= ' placeholder="' . osc_esc_html($spec['placeholder']) . '"';
        }
        // Emitted only where the number is honest. A purified value is escaped on the way
        // in and escaping lengthens -- one typed "&" reaches the column as five characters
        // -- so on those types the attribute would promise a cap the save does not apply.
        $maxlength = isset($spec['maxlength']) ? (int)$spec['maxlength'] : 0;
        if ($maxlength > 0
            && in_array($type, self::LENGTH_HINT_TYPES, true)
            && !(($spec['purify'] ?? true) && in_array($type, self::PURIFIED_TYPES, true))
        ) {
            $attrs .= ' maxlength="' . $maxlength . '"';
        }

        // A control the page drives from script and never submits has no name; emitting an
        // empty one would put it in the request as a blank key.
        $common = ' id="' . osc_esc_html($id) . '"'
            . ($name === '' ? '' : ' name="' . osc_esc_html($name) . '"');

        switch ($type) {
            case 'custom':
                if (isset($spec['render']) && is_callable($spec['render'])) {
                    call_user_func($spec['render'], $spec);
                }
                break;

            case 'select':
                echo '<select' . $common . ' class="' . self::cssClass($type, $spec) . '"' . $attrs . '>';
                if (isset($spec['placeholder'])) {
                    echo '<option value="">' . osc_esc_html($spec['placeholder']) . '</option>';
                }
                foreach (($spec['options'] ?? array()) as $optValue => $optLabel) {
                    echo '<option value="' . osc_esc_html($optValue) . '"'
                        . ((string)$optValue === (string)$value ? ' selected' : '') . '>'
                        . osc_esc_html($optLabel) . '</option>';
                }
                echo '</select>';
                break;

            case 'textarea':
                echo '<textarea' . $common . ' class="' . self::cssClass($type, $spec) . '"'
                    . ' rows="' . (int)($spec['rows'] ?? 5) . '"' . $attrs . '>'
                    . osc_esc_html((string)$value) . '</textarea>';
                break;

            case 'richtext':
                // A textarea carrying its editor's configuration. One shared script mounts
                // every one of them, so no screen writes an editor setup of its own and
                // nothing has to find its editors by a name pattern. With no JavaScript it
                // is the textarea, and the markup in it still posts.
                echo '<textarea' . $common . ' class="' . self::cssClass($type, $spec) . '"'
                    . ' rows="' . (int)($spec['rows'] ?? 12) . '"'
                    . ' data-osc-richtext="' . osc_esc_html(self::richtextConfig($spec)) . '"'
                    . $attrs . '>' . osc_esc_html((string)$value) . '</textarea>';
                break;

            case 'radio':
                self::choices($id, $name, (string)$value, $spec);
                break;

            case 'color':
                echo '<input type="color"' . $common . ' class="' . self::cssClass($type, $spec) . '"'
                    . ' value="' . osc_esc_html((string)$value) . '"' . $attrs . ' />';
                break;

            case 'hidden':
                // The value a page's own script computes from the controls beside it. It is
                // collected, validated and stored like any other field; it is simply not one
                // the administrator types into.
                echo '<input type="hidden"' . $common . ' value="' . osc_esc_html((string)$value) . '"'
                    . $attrs . ' />';
                break;

            case 'file':
                // No value: a file input's value cannot be set, and a browser would refuse it.
                echo '<input type="file"' . $common . ' class="' . self::cssClass($type, $spec) . '"'
                    . $attrs . ' />';
                break;

            case 'image':
                echo '<div class="field-image">';
                if (!empty($spec['preview_url'])) {
                    echo '<img class="field-image-preview" src="' . osc_esc_html($spec['preview_url']) . '"'
                        . ' alt="' . osc_esc_html($spec['label'] ?? '') . '" />';
                }
                echo '<input type="file"' . $common . ' class="' . self::cssClass('file', $spec) . '"'
                    . ' accept="image/*"' . $attrs . ' />';
                if ($kept) {
                    $removeName = (string)($spec['remove_name'] ?? $name . '_remove');
                    echo '<label class="field-choice"><input type="checkbox"'
                        . ' id="' . osc_esc_html($id . '-remove') . '"'
                        . ' name="' . osc_esc_html($removeName) . '" value="1"'
                        . (!empty($spec['disabled']) ? ' disabled' : '') . ' />'
                        . '<span>' . osc_esc_html(__('Remove image')) . '</span></label>';
                }
                echo '</div>';
                break;

            case 'number':
                foreach (array('min', 'max', 'step') as $key) {
                    if (isset($spec[$key])) {
                        $attrs .= ' ' . $key . '="' . osc_esc_html($spec[$key]) . '"';
                    }
                }
                echo '<input type="number"' . $common . ' class="' . self::cssClass($type, $spec) . '"'
                    . ' value="' . osc_esc_html((string)$value) . '"' . $attrs . ' />';
                break;

            case 'secret':
                // A stored secret is not echoed back into the DOM when 'masked' is set: the
                // field renders empty over a bullet placeholder, and a blank submission means
                // "unchanged". Callers keep the old value themselves until the save layer lands.
                $masked = !empty($spec['masked']);
                echo '<input type="password"' . $common . ' class="' . self::cssClass($type, $spec) . '"'
                    . ' value="' . ($masked ? '' : osc_esc_html((string)$value)) . '"'
                    . ' autocomplete="off" spellcheck="false"'
                    . ($masked && (string)$value !== '' ? ' placeholder="••••••••"' : '')
                    . $attrs . ' />';
                if (!empty($spec['reveal'])) {
                    echo '<button type="button" class="btn btn-sm btn-secondary" data-osc-reveal="'
                        . osc_esc_html($id) . '" aria-controls="' . osc_esc_html($id) . '"'
                        . ' aria-pressed="false" data-label-show="' . osc_esc_html(__('Show')) . '"'
                        . ' data-label-hide="' . osc_esc_html(__('Hide')) . '">'
                        . osc_esc_html(__('Show')) . '</button>';
                }
                break;

            default:
                // email/url/tel are text fields wearing a keyboard and a validator. Anything
                // else falls back to text rather than emitting a type the caller invented.
                $htmlType = in_array($type, array('email', 'url', 'tel'), true) ? $type : 'text';
                echo '<input type="' . $htmlType . '"' . $common
                    . ' class="' . self::cssClass($type, $spec) . '"'
                    . ' value="' . osc_esc_html((string)$value) . '"' . $attrs . ' />';
                break;
        }
    }

    /**
     * The locales a field expands over: what 'locales' carries when 'translate' is set on
     * a text, textarea or richtext field, and nothing otherwise. The caller supplies the
     * list -- core's declared settings page from osc_settings_field_locales(), a plugin
     * from whatever it already has -- so drawing a field never queries anything.
     *
     * @param array<string,mixed> $spec
     *
     * @return array<string,string> code => locale name
     */
    public static function localesFor(array $spec)
    {
        if (empty($spec['translate'])
            || !in_array($spec['type'] ?? 'text', self::TRANSLATED_TYPES, true)
            || empty($spec['locales'])
            || !is_array($spec['locales'])
        ) {
            return array();
        }

        return $spec['locales'];
    }

    /**
     * One control per locale, in the tab widget the rest of the admin's multilang editors
     * use. Each control is named for its locale -- the field name with the locale code
     * appended, or the pattern 'translate_name' gives -- which is the key the value is
     * stored under.
     *
     * One enabled locale gets no tabs: a single tab is a label pretending to be a choice.
     *
     * @param string               $id      the field's own id, which the panels are named from
     * @param array<string,mixed>  $spec
     * @param array<string,string> $locales code => locale name
     *
     * @return void
     */
    public static function translated($id, array $spec, array $locales)
    {
        $type    = $spec['type'] ?? 'text';
        $stored  = is_array($spec['value'] ?? null) ? $spec['value'] : array();
        $label   = (string)($spec['label'] ?? '');
        $tabs    = count($locales) > 1;
        $stacked = ($spec['layout'] ?? '') === 'stacked';
        // A second field on the same locales does not need a second strip: 'tabs' => false
        // draws the panels without one, and the strip above them switches these too. Two
        // strips on one screen can disagree, and a title in one language beside a body in
        // another is a mistake nothing on screen explains.
        $strip   = $tabs && ($spec['tabs'] ?? true);
        $open    = self::openLocale($locales);

        echo '<div class="field-translate">';
        if ($strip) {
            echo '<div class="osc-tab"><ul>';
            foreach ($locales as $code => $localeName) {
                // A locale whose control was rejected says so on its tab, so the error is
                // findable without opening every one of them.
                $error = self::errorFor($spec, $code);
                echo self::localeTab(
                    self::localePanelId($id, $code),
                    $localeName,
                    (string)$code,
                    $open,
                    $error === '' ? '' : ' data-osc-tab-error title="' . osc_esc_html($error) . '"'
                );
            }
            echo '</ul></div>';
        }

        foreach ($locales as $code => $localeName) {
            $opens        = (string)$code === $open;
            $sub          = $spec;
            $sub['name']  = self::translatedName($spec, (string)$code);
            $sub['value'] = (string)($stored[$code] ?? '');
            $sub['error'] = self::errorFor($spec, $code);
            unset($sub['id']);
            if ($tabs && $label !== '' && !$stacked) {
                // Only the tab strip says which locale a control belongs to, and a tab is
                // not the control's label. A caller's own aria-label still wins.
                $sub['attrs'] = (array)($spec['attrs'] ?? array())
                    + array('aria-label' => $label . ' (' . $localeName . ')');
            }
            if ($tabs) {
                echo '<div class="field-translate-panel" id="'
                    . osc_esc_html(self::localePanelId($id, $code)) . '"'
                    . ($opens ? '' : ' hidden') . '>';
            }
            $subId = self::idFor($sub);
            if ($stacked) {
                // The label belongs inside the panel: one label above the strip would name
                // whichever locale happens to be open.
                echo '<div class="osc-field">';
                $shown = $tabs && $label !== '' ? $label . ' (' . $localeName . ')' : $label;
                self::stackedLabel($subId, array('label' => $shown, 'required' => !empty($spec['required'])) + $sub);
            }
            // Only the locale on screen is required of the browser: a required control in a
            // hidden panel is a form the browser refuses to submit and says nothing about --
            // no submit event, so the page's own validator never runs and the button looks
            // dead. Every locale is still checked on save.
            $sub['required'] = $opens && !empty($spec['required']);
            self::control($type, $subId, $sub);
            self::errorText($subId, $sub);
            if ($stacked) {
                echo '</div>';
            }
            if ($tabs) {
                echo '</div>';
            }
        }
        echo '</div>';
    }

    /**
     * One locale's posted name: the pattern 'translate_name' gives, with %s standing for
     * the locale code, or the field name with the code appended when there is none.
     *
     * The pattern exists because a screen's posted names are a contract with whatever
     * already reads them -- title[en_US], en_US#s_title -- and adopting the shared field
     * must not change a single one of them.
     *
     * @param array<string,mixed> $spec
     * @param string              $code
     *
     * @return string
     */
    public static function translatedName(array $spec, $code)
    {
        $name = (string)($spec['name'] ?? '');
        if (empty($spec['translate_name'])) {
            return $name . $code;
        }

        return sprintf((string)$spec['translate_name'], $code);
    }

    /**
     * A field's error message: the one it was given, or the one its locale was given when
     * 'error' is a map keyed by locale code.
     *
     * @param array<string,mixed> $spec
     * @param string|null         $code
     *
     * @return string Empty when the field was not rejected
     */
    public static function errorFor(array $spec, $code = null)
    {
        $error = $spec['error'] ?? '';
        if (is_array($error)) {
            $error = $code === null ? '' : ($error[$code] ?? '');
        }

        return (string)$error;
    }

    /**
     * The sentence under a rejected control. Mandatory where there is an error: a red
     * border says something is wrong and never says what.
     *
     * @param string              $id
     * @param array<string,mixed> $spec
     *
     * @return void
     */
    public static function errorText($id, array $spec)
    {
        $error = self::errorFor($spec);
        if ($error === '') {
            return;
        }

        echo '<p class="field-error" id="' . osc_esc_html($id . '-error') . '">'
            . osc_esc_html($error) . '</p>';
    }

    /**
     * The editor configuration a richtext field carries, as JSON for its data attribute.
     * Built here rather than in the browser so the toolbar presets stay in one place and a
     * plugin's tinymce_config filter still reaches them.
     *
     * @param array<string,mixed> $spec
     *
     * @return string
     */
    public static function richtextConfig(array $spec)
    {
        $overrides = (array)($spec['config'] ?? array());
        if (!empty($spec['height'])) {
            $overrides['height'] = (int)$spec['height'];
        }
        if (!empty($spec['media'])) {
            // The library picker and the paste/drop upload are the script's to wire, since
            // both are callbacks; what it cannot invent is the signed endpoint.
            $overrides['osc_media']      = true;
            $overrides['osc_upload_url'] = (string)($spec['upload_url'] ?? '');
        }

        if (!function_exists('osc_tinymce_config')) {
            return (string)json_encode($overrides);
        }
        $config = osc_tinymce_config((string)($spec['preset'] ?? 'basic'), $overrides);

        return is_string($config) ? $config : '{}';
    }

    /**
     * One tab of a locale strip. The admin's own language opens first by default, and its
     * tab carries a marker saying so.
     *
     * @param string      $panel   the id of the panel the tab shows
     * @param string      $label   the locale's name
     * @param string      $code    the locale's code
     * @param string|null $current the locale that opens first; the admin's own by default
     * @param string      $attrs   extra attributes for the <li>, already escaped
     *
     * @return string
     */
    public static function localeTab($panel, $label, $code, $current = null, $attrs = '')
    {
        $current = $current ?? self::adminLocale();
        $opens   = (string)$code === (string)$current;
        $marker  = '';
        if ((string)$code === self::adminLocale() && defined('OC_ADMIN') && OC_ADMIN) {
            $attrs  .= ' data-osc-tab-mine title="' . osc_esc_html(__('Your admin language')) . '"';
            $marker = ' <span class="visually-hidden">(' . osc_esc_html(__('Your admin language')) . ')</span>';
        }

        return '<li' . ($opens ? ' class="ui-tabs-active ui-state-active"' : '') . $attrs . '>'
            . '<a href="#' . osc_esc_html($panel) . '">' . osc_esc_html($label) . $marker . '</a></li>';
    }

    /**
     * The locale a translated field opens on: the admin's own when the field offers it,
     * otherwise the first one.
     *
     * @param array<string,string> $locales code => locale name
     *
     * @return string
     */
    public static function openLocale(array $locales)
    {
        $admin = self::adminLocale();

        return array_key_exists($admin, $locales) ? $admin : (string)array_key_first($locales);
    }

    /** The admin's own language, or '' outside the admin. */
    private static function adminLocale()
    {
        return function_exists('osc_current_admin_locale') ? (string)osc_current_admin_locale() : '';
    }

    /**
     * The id of one locale's panel. Kept off the control's own id: they sit on different
     * elements and an id used twice is a tab that focuses the wrong thing.
     *
     * @param string $id
     * @param string $code
     *
     * @return string
     */
    public static function localePanelId($id, $code)
    {
        return $id . '-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$code);
    }

    /**
     * The option list of a radio group. Each option is its own label wrapping its own
     * control, so the whole line is a hit target and no id can drift from its label.
     *
     * @param string              $id
     * @param string              $name
     * @param string              $value
     * @param array<string,mixed> $spec
     *
     * @return void
     */
    public static function choices($id, $name, $value, array $spec)
    {
        $label = (string)($spec['label'] ?? '');
        echo '<div class="field-choices"'
            . ($label === '' ? '' : ' role="group" aria-label="' . osc_esc_html($label) . '"')
            . '>';

        $i = 0;
        foreach (($spec['options'] ?? array()) as $optValue => $option) {
            $i++;
            $custom      = '';
            $optId       = $id . '-' . $i;
            $optDisabled = false;
            if (is_array($option)) {
                $custom      = (string)($option['custom_html'] ?? '');
                $optId       = (string)($option['id'] ?? $optId);
                $optDisabled = !empty($option['disabled']);
                $option      = $option['label'] ?? '';
            }

            // The id counts the options rather than slugging their values: a value is free
            // text ("F j, Y"), and neither spaces nor two values slugging to the same
            // string can be allowed to produce an id two radios share.
            echo '<label class="field-choice">'
                . '<input type="radio" id="' . osc_esc_html($optId) . '"'
                . ' name="' . osc_esc_html($name) . '" value="' . osc_esc_html($optValue) . '"'
                . ((string)$optValue === $value ? ' checked' : '')
                . (!empty($spec['disabled']) || $optDisabled ? ' disabled' : '') . ' />'
                . '<span>' . osc_esc_html($option) . '</span>'
                . $custom
                . '</label>';
        }
        echo '</div>';
    }

    /**
     * The width class a field gets. Width follows the field's meaning, not the page it
     * happens to sit on; 'width' overrides it when a screen genuinely needs something else.
     *
     * @param string              $type
     * @param array<string,mixed> $spec
     *
     * @return string
     */
    public static function cssClass($type, array $spec)
    {
        $widths = array(
            'num'    => 'field-num',
            'text'   => 'field-text',
            'key'    => 'field-key',
            'select' => 'field-select',
            'full'   => '',
        );

        $byType = array(
            'number'   => 'field-num',
            'select'   => 'field-select',
            'secret'   => 'field-key',
            'textarea' => 'field-text',
            'richtext' => '',
            'color'    => 'field-color',
            'file'     => '',
        );

        $width = isset($spec['width'])
            ? ($widths[$spec['width']] ?? '')
            : ($byType[$type] ?? 'field-text');

        // input-text is what the admin styles a text control with; a select, a colour well and
        // a file picker are not text controls and the class would fight their own sizing.
        $classes = in_array($type, array('select', 'color', 'file'), true) ? array() : array('input-text');

        // field-select is a select's appearance, not just its width, so it stays on even when
        // the caller asks for a different width -- the width classes carry !important and win
        // that part on their own.
        if ($type === 'select' && $width !== 'field-select') {
            $classes[] = 'field-select';
        }
        if ($width !== '') {
            $classes[] = $width;
        }
        if ($type === 'textarea' && !empty($spec['monospace'])) {
            $classes[] = 'field-mono';
        }
        if (!empty($spec['class'])) {
            $classes[] = $spec['class'];
        }

        return osc_esc_html(implode(' ', $classes));
    }

    /**
     * The hint under a field, in the same help-box the admin already uses.
     *
     * @param array<string,mixed> $spec
     *
     * @return void
     */
    public static function help(array $spec)
    {
        if (!empty($spec['help_html'])) {
            echo '<div class="help-box">' . $spec['help_html'] . '</div>';
        } elseif (!empty($spec['help'])) {
            echo '<div class="help-box">' . osc_esc_html((string)$spec['help']) . '</div>';
        }
    }

    /**
     * A field's DOM id: its own, or one derived from its name so the label is clickable
     * without every caller having to invent one.
     *
     * @param array<string,mixed> $spec
     *
     * @return string
     */
    public static function idFor(array $spec)
    {
        if (!empty($spec['id'])) {
            return (string)$spec['id'];
        }

        $name = (string)($spec['name'] ?? '');

        return $name === '' ? '' : 'field-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $name);
    }

    /**
     * Extra attributes as an escaped string. A value of true renders the attribute alone
     * (`readonly`), false and null drop it.
     *
     * @param array<string,mixed> $attrs name => value; true renders bare, false and null drop
     *
     * @return string
     */
    public static function attrsString(array $attrs)
    {
        $out = '';
        foreach ($attrs as $name => $value) {
            if ($value === false || $value === null) {
                continue;
            }
            $out .= $value === true
                ? ' ' . osc_esc_html($name)
                : ' ' . osc_esc_html($name) . '="' . osc_esc_html($value) . '"';
        }

        return $out;
    }
}
