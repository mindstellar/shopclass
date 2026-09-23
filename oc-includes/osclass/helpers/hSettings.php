<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\admin\form\store\StoreException;
use mindstellar\admin\form\store\StoreFactory;
use mindstellar\admin\ui\Field;
use mindstellar\database\DbException;
use mindstellar\settings\SettingsImage;
use mindstellar\settings\SettingsPageRegistry;

/**
 * Declared admin settings pages.
 *
 * A plugin says what is on its settings page; core owns the page. From the declaration
 * core derives the shell and heading, the form and its osc_csrf_check(), per-field
 * sanitisation chosen by type, validation and error display, persistence into
 * preferences under the plugin's own section, the flash message and the Save row.
 *
 * The field primitives in hAdminUi.php already gave an author one way to draw a field.
 * What they could not do is stop the page around the field being re-invented -- a form,
 * a save handler, a CSRF check, a validation block -- and that re-invention is where the
 * security bugs live. So: no markup and no save handler in the plugin.
 *
 * Loaded with the other helpers, ahead of Plugins::init(), so a plugin can call
 * osc_register_settings_page() at include time. Nothing here renders anything, so it does
 * not want hAdminUi.php's late position; the render path only runs during a request that
 * has already loaded everything.
 */

if (!function_exists('osc_register_settings_page')) {
    /**
     * Declare a settings page. Thin wrapper over SettingsPageRegistry::register().
     *
     * See SettingsPageRegistry::register() for the $spec shape.
     *
     * @param string $id   Namespaced slug, usually the plugin's own.
     * @param array  $spec Page specification.
     *
     * @return void
     */
    function osc_register_settings_page($id, $spec)
    {
        SettingsPageRegistry::instance()->register($id, $spec);
        // A theme declares its pages after admin_menu_init has run, so add the entry now.
        if (SettingsPageRegistry::instance()->menuReady() && function_exists('osc_settings_menu_init')) {
            osc_settings_menu_init();
        }
    }
}

if (!function_exists('osc_admin_form')) {
    /**
     * A fluent builder over the same spec osc_register_settings_page() takes.
     *
     * Sugar only: it stores nothing, renders nothing and validates nothing. ->toArray()
     * hands back the array a hand-written page would have declared, and ->register()
     * passes that array to osc_register_settings_page() unchanged, so a bad spec still
     * throws from the registry.
     *
     *   osc_admin_form('acme')
     *       ->title($pageTitle)
     *       ->group($groupTitle)
     *       ->secret('api_key', $keyLabel)->writeOnly(false)->required()
     *       ->register();
     *
     * It lives here rather than with the field primitives because plugins declare their
     * pages at include time, before hAdminUi.php is loaded.
     *
     * @param string $id Namespaced slug, usually the plugin's own.
     *
     * @return \mindstellar\admin\ui\FormSpec
     */
    function osc_admin_form($id)
    {
        return new \mindstellar\admin\ui\FormSpec($id);
    }
}

if (!function_exists('osc_settings_pages')) {
    /**
     * All registered settings pages, keyed by id.
     *
     * @return array<string,array<string,mixed>>
     */
    function osc_settings_pages()
    {
        return SettingsPageRegistry::instance()->all();
    }
}

if (!function_exists('osc_settings_page')) {
    /**
     * The spec for a registered settings page, or null when it is not registered.
     *
     * @param string $id
     *
     * @return array<string,mixed>|null
     */
    function osc_settings_page($id)
    {
        return SettingsPageRegistry::instance()->get($id);
    }
}

if (!function_exists('osc_settings_page_conflicts')) {
    /**
     * Page ids more than one plugin tried to register, and how many times each. Empty on a
     * healthy install; an entry means a plugin's settings page is not the one being shown,
     * and its saved values are under a section nothing reads.
     *
     * @return array<string,int> page id => registration count
     */
    function osc_settings_page_conflicts()
    {
        return SettingsPageRegistry::instance()->conflicts();
    }
}

if (!function_exists('osc_settings_page_url')) {
    /**
     * The admin URL of a declared settings page.
     *
     * @param string $id
     *
     * @return string
     */
    function osc_settings_page_url($id)
    {
        return osc_admin_base_url(true) . '?page=settings&action=custom&id=' . urlencode($id);
    }
}

if (!function_exists('osc_settings_value')) {
    /**
     * The stored value of one declared field, or the field's declared default when
     * nothing has been saved yet.
     *
     * A checkbox is returned as a bool, a number as an int or float, everything else as
     * the stored string -- so a caller never has to remember what a preference column
     * holds.
     *
     * A key that does not name a row reads as no row at all -- the declared default --
     * because an unparseable id in a bookmarked URL has to draw the empty form rather
     * than a white screen. The write path still refuses the same key outright, so
     * nothing can be stored under it.
     *
     * @param string          $pageId
     * @param string          $name
     * @param int|string|null $id     the row to read, on a page bound to a table. A
     *                                positive integer or nothing: anything else reads
     *                                as no row rather than being coerced into one.
     *
     * @return mixed
     */
    function osc_settings_value($pageId, $name, $id = null)
    {
        $page  = osc_settings_page($pageId);
        $store = $page === null ? null : StoreFactory::forPage($page);
        if ($store === null) {
            return null;
        }

        $fields = SettingsPageRegistry::instance()->fields($pageId);
        $field  = $fields[$name] ?? null;
        if ($field === null) {
            return null;
        }

        try {
            return $store->value($name, $field, $id);
        } catch (StoreException $e) {
            return $store->value($name, $field, null);
        }
    }
}

if (!function_exists('osc_settings_cast')) {
    /**
     * Turn a stored preference string back into the shape its field type implies.
     *
     * @param string $type
     * @param mixed  $value
     *
     * @return mixed
     */
    function osc_settings_cast($type, $value)
    {
        if ($type === 'checkbox') {
            return (bool)$value;
        }
        if ($type === 'number') {
            return strpos((string)$value, '.') === false ? (int)$value : (float)$value;
        }
        if ($type === 'image') {
            return ctype_digit((string)$value) && (int)$value > 0 ? (int)$value : '';
        }

        return $value;
    }
}

if (!function_exists('osc_settings_values')) {
    /**
     * Every declared field on a page, keyed by name, resolved the same way
     * osc_settings_value() resolves one.
     *
     * A key the store cannot parse reads as no row, the same way osc_settings_value()
     * resolves one.
     *
     * @param string          $pageId
     * @param int|string|null $id     the row to read, on a page bound to a table
     *
     * @return array<string,mixed> field name => value
     */
    function osc_settings_values($pageId, $id = null)
    {
        $page  = osc_settings_page($pageId);
        $store = $page === null ? null : StoreFactory::forPage($page);
        if ($store === null) {
            return array();
        }

        $fields = SettingsPageRegistry::instance()->fields($pageId);

        try {
            return $store->load($fields, $id);
        } catch (StoreException $e) {
            return $store->load($fields, null);
        }
    }
}

if (!function_exists('osc_settings_locales')) {
    /**
     * Every enabled locale as code => name, in the order the site lists them.
     *
     * A translated field's preference key is the field name with the locale code appended,
     * so codes must stay the fixed five characters of 'en_US' or those keys turn ambiguous.
     *
     * @param bool $refresh re-read the list after a locale is enabled or disabled
     *
     * @return array<string,string>
     */
    function osc_settings_locales($refresh = false)
    {
        static $cache = null;
        if ($cache !== null && !$refresh) {
            return $cache;
        }

        $cache = array();
        foreach (OSCLocale::newInstance()->listAllEnabled() as $locale) {
            $code = (string)($locale['pk_c_code'] ?? '');
            if ($code !== '') {
                $cache[$code] = (string)($locale['s_name'] ?? $code);
            }
        }

        return $cache;
    }
}

if (!function_exists('osc_settings_field_locales')) {
    /**
     * The locales one field expands over: every enabled locale for a translated text or
     * textarea, and none at all for anything else.
     *
     * An install with one enabled locale still gets the per-locale key, otherwise enabling
     * a second locale later would strand everything already stored.
     *
     * A field may name the list itself, which is the same key the render side reads: a
     * screen whose controls are drawn for the back-office locales has to save those and
     * not the front-end ones, or it writes rows for tabs nobody was shown.
     *
     * @param array $field
     *
     * @return array<string,string> code => locale name
     */
    function osc_settings_field_locales(array $field)
    {
        if (empty($field['translate'])
            || !in_array($field['type'] ?? 'text', array('text', 'textarea', 'richtext'), true)
        ) {
            return array();
        }

        if (!empty($field['locales']) && is_array($field['locales'])) {
            return $field['locales'];
        }

        return osc_settings_locales();
    }
}

if (!function_exists('osc_settings_master_on')) {
    /**
     * Whether a master field's submitted value counts as switched on.
     *
     * '0' is off: that is what an unticked checkbox stores and what a select may offer,
     * and a dependent field has to read the same answer from either.
     *
     * @param mixed $value
     *
     * @return bool
     */
    function osc_settings_master_on($value)
    {
        if (is_array($value)) {
            foreach ($value as $one) {
                if (osc_settings_master_on($one)) {
                    return true;
                }
            }

            return false;
        }

        return trim((string)$value) !== '' && (string)$value !== '0';
    }
}

if (!function_exists('osc_settings_depends_met')) {
    /**
     * Whether a field's 'depends' chain is satisfied by the submitted values.
     *
     * Walks the chain rather than looking at the immediate master alone: a dependent
     * master that is itself switched off leaves its own control on screen with a value
     * still in it, and taking that at face value would keep a grandchild field alive.
     *
     * A step with a 'depends_value' is met while the master's value, compared as a string,
     * is one of those listed; a step without one while the master is on.
     *
     * @param array  $fields declared fields, keyed by name
     * @param string $name
     * @param array  $values submitted values, keyed by field name
     * @param array  $seen   names already on this chain
     *
     * @return bool
     */
    function osc_settings_depends_met(array $fields, $name, array $values, array $seen = array())
    {
        $field = $fields[$name] ?? null;
        if ($field === null || empty($field['depends'])) {
            return true;
        }

        $master = (string)$field['depends'];
        // A chain that loops back on itself has no answer, and a master that is not on the
        // page can never be on. Both are spec errors; off is the side that stores nothing.
        if (isset($seen[$name]) || !isset($fields[$master])) {
            return false;
        }
        $seen[$name] = true;

        $submitted = $values[$master] ?? '';
        if (isset($field['depends_value'])) {
            $wanted = array_map('strval', (array)$field['depends_value']);
            $met    = false;
            foreach ((array)$submitted as $one) {
                if (!is_array($one) && in_array((string)$one, $wanted, true)) {
                    $met = true;
                    break;
                }
            }
        } else {
            $met = osc_settings_master_on($submitted);
        }

        return $met && osc_settings_depends_met($fields, $master, $values, $seen);
    }
}

if (!function_exists('osc_settings_field_active')) {
    /**
     * Whether a declared field is part of this submission at all.
     *
     * The client script hiding a row is UX and nothing more. This is the answer the save
     * path uses: a field whose master is off is not required and its posted value is
     * discarded rather than stored, so nothing is gained by submitting one by hand.
     *
     * @param string     $pageId
     * @param string     $name
     * @param array      $values submitted values, keyed by field name
     * @param array|null $fields the page's declared fields, when the caller already holds them
     *
     * @return bool
     */
    function osc_settings_field_active($pageId, $name, array $values, ?array $fields = null)
    {
        if ($fields === null) {
            $fields = SettingsPageRegistry::instance()->fields($pageId);
        }

        return osc_settings_depends_met($fields, $name, $values);
    }
}

if (!function_exists('osc_settings_sanitize')) {
    /**
     * The submitted value of one field, sanitised by its type.
     *
     * Type-driven and deliberately small: this is not FieldValidator, which serves the
     * listing custom-field system (t_meta_fields, FormService) and would tie admin
     * settings to it for no gain. A field wanting more supplies its own `sanitize`.
     *
     * A field may also say how the submission becomes its value at all, with `collect`.
     * That is for a control core cannot read by name -- one posted as an array, or one a
     * plugin contributes keys to -- and the declaration then owns the sanitising, because
     * core no longer knows what shape it is looking at.
     *
     * @param array $field
     *
     * @return mixed
     */
    function osc_settings_sanitize(array $field)
    {
        if (isset($field['collect'])) {
            $value = call_user_func($field['collect'], $field);

            return isset($field['sanitize']) ? call_user_func($field['sanitize'], $value) : $value;
        }

        $name = $field['name'];

        if ($field['type'] === 'checkbox') {
            // Presence is the value. An unchecked box submits nothing at all, and
            // comparing against whatever string the browser invents for a checked one is
            // how a setting ends up impossible to switch on.
            return Params::getParam($name) !== '';
        }

        // Raw on purpose: a secret may legitimately contain characters osc_sanitize_string
        // would strip, and every value here is escaped again on output.
        $value = Params::getParam($name, false, false);
        if (is_array($value)) {
            return '';
        }
        $value = (string)$value;
        // Every type but one loses the whitespace around it. A secret is stored exactly as
        // typed: a password with a leading or trailing space is a password, and trimming one
        // here would store a hash of something the sign-in form never sees.
        if ($field['type'] !== 'secret') {
            $value = trim($value);
        }

        // A body is written as markup, so it is sanitized rather than stripped: taking the
        // tags out would empty the field the admin just formatted. 'purify' => false
        // stores exactly what was typed.
        if ($field['type'] === 'richtext') {
            return ($field['purify'] ?? true) ? osc_sanitize_html($value) : $value;
        }

        // Every tag out of free-typed text, contents and all: what Params::getParam() has
        // always done to the same value on a hand-written screen, so a screen moved onto
        // a declaration does not start storing markup it used to strip. A field holding
        // markup or code on purpose declares 'purify' => false and is read raw.
        if (($field['purify'] ?? true) && in_array($field['type'], SettingsPageRegistry::PURIFIED_TYPES, true)) {
            $value = trim(osc_sanitize_text($value));
        }

        if (isset($field['sanitize'])) {
            return call_user_func($field['sanitize'], $value);
        }

        switch ($field['type']) {
            case 'number':
                return $value === '' ? '' : (strpos($value, '.') === false ? (int)$value : (float)$value);
            case 'email':
                // Deliberately not FILTER_SANITIZE_EMAIL: it deletes the illegal characters
                // and hands back something that then validates, so "john doe@example.test"
                // is stored as a different address instead of being refused. An address is
                // an identifier -- a typo has to come back to be corrected.
                return $value;
            case 'url':
                return osc_sanitize_url($value);
            default:
                return $value;
        }
    }
}

if (!function_exists('osc_settings_validate')) {
    /**
     * Check one submitted value against what its field declares. Returns an error message,
     * or null when the value is good.
     *
     * @param array $field
     * @param mixed $value
     *
     * @return string|null
     */
    function osc_settings_validate(array $field, $value)
    {
        $label = $field['label'] ?? $field['name'];

        if (!empty($field['required']) && ($value === '' || $value === false || $value === null)) {
            return sprintf(__('%s cannot be left empty'), $label);
        }

        // Everything below describes a value that is there. An optional field left blank
        // has nothing to check and must not be rejected by a min or a pattern.
        if ($value === '' || $value === null) {
            return null;
        }

        switch ($field['type']) {
            case 'number':
                if (!is_int($value) && !is_float($value)) {
                    return sprintf(__('%s must be a number'), $label);
                }
                if (isset($field['min']) && $value < $field['min']) {
                    return sprintf(__('%1$s must be %2$s or more'), $label, $field['min']);
                }
                if (isset($field['max']) && $value > $field['max']) {
                    return sprintf(__('%1$s must be %2$s or less'), $label, $field['max']);
                }
                break;
            case 'email':
                if (!osc_validate_email($value)) {
                    return sprintf(__('%s is not a valid email address'), $label);
                }
                break;
            case 'url':
                if (!osc_validate_url($value)) {
                    return sprintf(__('%s is not a valid URL'), $label);
                }
                break;
            case 'select':
            case 'radio':
                // A <select> only offers what was declared, so anything else was hand-made:
                // reject it rather than store a value the page can never show again.
                if (!array_key_exists((string)$value, $field['options'])) {
                    return sprintf(__('%s is not one of the available options'), $label);
                }
                break;
            default:
                break;
        }

        // Either spelling of the cap: the control renders one number, and it is this one
        // the save enforces, so a declaration cannot show a limit it does not apply.
        $maxlength = $field['maxlength'] ?? ($field['attrs']['maxlength'] ?? null);
        if ($maxlength !== null && mb_strlen((string)$value) > (int)$maxlength) {
            return sprintf(__('%1$s must be %2$s characters or fewer'), $label, (int)$maxlength);
        }
        if (isset($field['pattern']) && !preg_match($field['pattern'], (string)$value)) {
            return sprintf(__('%s is not in the expected format'), $label);
        }
        if (isset($field['validate'])) {
            $error = call_user_func($field['validate'], $value, $field);
            if (is_string($error) && $error !== '') {
                return $error;
            }
        }

        return null;
    }
}

if (!function_exists('osc_settings_hook_values')) {
    /**
     * The submitted values as a hook listener may see them: everything except the secrets.
     *
     * A 'secret' is the one field type whose value must not travel -- a listener that logs
     * or forwards what it is handed would be recording the administrator's own password,
     * on a rejected submission as much as on a saved one. Nothing else is withheld: a
     * field that is no column is still an ordinary value a listener has every reason to
     * see, so the line is drawn at the type that means "do not repeat this", not at how
     * the field happens to be stored.
     *
     * @param array<string,array<string,mixed>> $fields declared fields, keyed by name
     * @param array<string,mixed>                $values submitted values, keyed by field name
     *
     * @return array<string,mixed>
     */
    function osc_settings_hook_values(array $fields, array $values)
    {
        foreach ($fields as $name => $field) {
            if (($field['type'] ?? 'text') === 'secret') {
                unset($values[$name]);
            }
        }

        return $values;
    }
}

if (!function_exists('osc_settings_save')) {
    /**
     * Sanitise, validate and store every field on a declared page.
     *
     * Nothing is written unless every field passes: half a saved page is worse than a
     * rejected one, because the half that landed is invisible. The same guarantee covers
     * the lifecycle hooks -- 'admin_form_after_save' and a page's inline 'after_save' never
     * run on a rejected submission, and each runs exactly once on a successful one.
     *
     * A field whose 'depends' master is switched off is not part of the submission: its
     * posted value is discarded before validation, so it is neither required nor stored.
     *
     * A page's own 'validate' is where a rule spanning more than one field goes; it runs
     * after every field has been checked, is handed the row being saved, and its errors
     * join theirs.
     *
     * The order on a successful save is part of the contract: 'admin_form_before_save'
     * filters the values, the store writes them, the 'admin_form_after_save' hook runs, and
     * the page's own inline 'after_save' runs last -- so a page sees whatever a listener
     * already did rather than racing it.
     *
     * What a hook is handed is the submission without its secrets, and 'before_save' may
     * only hand back keys it was shown. A listener therefore cannot read the password the
     * administrator typed and cannot write one either; the page's own callables, which
     * declared the field in the first place, are handed everything.
     *
     * A page bound to a table saves the row its caller names and no other: the key is
     * never read out of the submission, so a request naming somebody else's row is just a
     * request with an unread field in it. No key inserts; a key that is not a positive
     * integer, or names a row that is gone, is refused with an error rather than guessed
     * at.
     *
     * @param string          $pageId
     * @param int|string|null $id     the row to update on a page bound to a table, or
     *                                null to insert one
     *
     * @return array array('errors' => string[], 'updated' => int, 'values' => array, 'id' => mixed)
     */
    function osc_settings_save($pageId, $id = null)
    {
        $page = osc_settings_page($pageId);
        if ($page === null) {
            $errors = array(__('That settings page is not registered.'));
            osc_run_hook('admin_form_save_failed', $pageId, $errors, array());

            return array('errors' => $errors, 'updated' => 0, 'values' => array(), 'id' => null);
        }

        $fields  = SettingsPageRegistry::instance()->fields($pageId);
        $locales = array();
        $values  = array();
        $errors  = array();

        // An image field's value is the id already stored; a posted file or remove box
        // changes it only once every field has passed.
        $stored = array();
        foreach ($fields as $name => $field) {
            if ($field['type'] === 'custom') {
                // Core does not know what a custom field submitted, so it does not pretend
                // to store it. The plugin owns the value the same way it owns the markup.
                continue;
            }
            $locales[$name] = osc_settings_field_locales($field);
            if ($field['type'] === 'image') {
                $stored[$name] = StoreFactory::forPage($page)->value($name, $field, $id);
                $values[$name] = $stored[$name];
                continue;
            }
            if ($locales[$name] === array()) {
                $values[$name] = osc_settings_sanitize($field);
                continue;
            }
            $translated = array();
            foreach ($locales[$name] as $code => $localeName) {
                // The posted name, which a field may spell its own way, not the storage key.
                $translated[$code] = osc_settings_sanitize(
                    array('name' => Field::translatedName($field, (string)$code)) + $field
                );
            }
            $values[$name] = $translated;
        }

        // The client script only hides a row; this is what decides, and a field whose
        // master is off is neither required nor stored. Every answer is worked out against
        // the untouched submission and applied after, so declaration order cannot change it.
        $discard = array();
        foreach ($fields as $name => $field) {
            if (array_key_exists($name, $values)
                && !osc_settings_field_active($pageId, $name, $values, $fields)
            ) {
                $discard[] = $name;
            }
        }
        foreach ($discard as $name) {
            unset($values[$name]);
        }

        $uploads  = array();
        $badFiles = array();
        foreach ($stored as $name => $storedId) {
            // Discarded with its master off, or posted from outside the admin: the stored
            // image stays exactly as it is.
            if (!array_key_exists($name, $values) || !SettingsImage::allowed($page)) {
                continue;
            }
            $posted = SettingsImage::posted($fields[$name]);
            if ($posted['error'] !== null) {
                $errors[]        = $posted['error'];
                $badFiles[$name] = true;
            } elseif ($posted['file'] !== null) {
                $uploads[$name] = $posted['file'];
            } elseif (Params::getParamString(SettingsImage::removeName($name), false, false) !== '') {
                $values[$name] = '';
            }
        }

        foreach ($fields as $name => $field) {
            if (!array_key_exists($name, $values)) {
                continue;
            }
            if ($field['type'] === 'image') {
                if (!empty($field['required']) && $values[$name] === ''
                    && !isset($uploads[$name]) && !isset($badFiles[$name])
                ) {
                    $errors[] = sprintf(__('%s cannot be left empty'), $field['label'] ?? $name);
                }
                continue;
            }
            if ($locales[$name] !== array()) {
                foreach ($locales[$name] as $code => $localeName) {
                    // The locale is named in the error: "Title cannot be left empty" on a
                    // page with four tabs does not say which tab to open.
                    $error = osc_settings_validate(
                        array('label' => ($field['label'] ?? $name) . ' (' . $localeName . ')') + $field,
                        $values[$name][$code]
                    );
                    if ($error !== null) {
                        $errors[] = $error;
                    }
                }
                continue;
            }
            $error = osc_settings_validate($field, $values[$name]);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        // A rule spanning more than one field has nowhere else to live: a field's own
        // validate callback is handed its value and its spec, and neither says anything
        // about the field next to it. It is handed the row as well, because the commonest
        // rule of this kind -- "that name is taken" -- has to exclude the row being edited.
        // Run after the per-field pass rather than instead of it, so the page still reports
        // everything wrong with a submission at once.
        if (isset($page['validate']) && is_callable($page['validate'])) {
            $reported = call_user_func($page['validate'], $values, $pageId, $id);
            foreach (is_array($reported) ? $reported : array($reported) as $error) {
                if (is_string($error) && $error !== '') {
                    $errors[] = $error;
                }
            }
        }

        // Uploaded only now that nothing else can refuse the submission, so a rejected save
        // never leaves a file behind.
        $fresh = array();
        if ($errors === array()) {
            foreach ($uploads as $name => $tmp) {
                $newId = SettingsImage::upload($page, $tmp);
                if ($newId === null) {
                    $errors[] = sprintf(__('%s is not a valid image'), $fields[$name]['label'] ?? $name);
                    continue;
                }
                $fresh[$name]  = $newId;
                $values[$name] = $newId;
            }
        }

        if ($errors !== array()) {
            if ($stored !== array()) {
                $values = SettingsImage::rollback($page, $fresh, $stored, $values);
            }
            osc_run_hook('admin_form_save_failed', $pageId, $errors, osc_settings_hook_values($fields, $values));

            // Nothing was written, so there is no row to name: a rejected save is not half
            // a save, and that holds for the key as much as for the values.
            return array('errors' => $errors, 'updated' => 0, 'values' => $values, 'id' => null);
        }

        // A filter, not an action: deriving or normalising a value means handing it back,
        // and a hook would only ever mutate its own copy of the array. What comes back is
        // narrowed to the keys that went out, so a listener can change a value it was shown
        // and cannot invent one it was not -- which is the same rule as withholding the
        // secrets, read from the other end. Without it a listener could rewrite a password
        // or an account type after every rule on the page had already passed.
        $exposed  = osc_settings_hook_values($fields, $values);
        $filtered = osc_apply_filter('admin_form_before_save', $exposed, $pageId);
        if (is_array($filtered)) {
            foreach ($values as $name => $value) {
                if (!array_key_exists($name, $exposed)) {
                    continue;
                }
                if (!array_key_exists($name, $filtered)) {
                    unset($values[$name]);
                    continue;
                }
                $values[$name] = $filtered[$name];
            }
        }

        // The store is the only part of this that knows where the values go. It walks the
        // declared fields rather than $values, which is what keeps a before_save listener
        // from writing a key the page never declared.
        $written = null;
        $refused = null;
        try {
            $written = StoreFactory::forPage($page)->save($fields, $values, $locales, $id);
        } catch (StoreException $e) {
            $refused = $e->getCode() === StoreException::NO_ROW
                ? __('That record no longer exists, so nothing was saved.')
                : __('That form does not name a record that can be saved.');
        } catch (DbException $e) {
            // A declared column the table does not have, or a value it will not hold. The
            // submission comes back on screen to be corrected rather than becoming an
            // error page, and the wording is core's own: the driver's names schema.
            $refused = __('That could not be saved. Please check the values and try again.');
        }
        if ($refused !== null) {
            // A refused write is a rejected save: no effects, no key, and the values back
            // on screen, exactly as a failed validation leaves them.
            $errors = array($refused);
            if ($stored !== array()) {
                $values = SettingsImage::rollback($page, $fresh, $stored, $values);
            }
            osc_run_hook('admin_form_save_failed', $pageId, $errors, osc_settings_hook_values($fields, $values));

            return array('errors' => $errors, 'updated' => 0, 'values' => $values, 'id' => null);
        }

        $savedId = $written['id'];

        // Whichever image the preference no longer names goes: the old one after a replace
        // or a remove, or a new one a before_save listener or a failed write left unstored.
        foreach ($stored as $name => $storedId) {
            $now = StoreFactory::forPage($page)->value($name, $fields[$name], $id);
            if ($storedId !== '' && (string)$now !== (string)$storedId) {
                SettingsImage::delete($page, $storedId);
            }
            if (isset($fresh[$name]) && (string)$now !== (string)$fresh[$name]) {
                SettingsImage::delete($page, $fresh[$name]);
            }
        }

        // The key of the row that was written: the new one on an insert, the existing one
        // on an update, and null on a preference page, which has rows for nothing.
        $exposed = osc_settings_hook_values($fields, $values);
        osc_run_hook('admin_form_after_save', $pageId, $exposed, $savedId);
        // The page's own callable is the other half of the declaration that named the
        // field, so it is handed everything; a hook listener is a third party and is not.
        if (isset($page['after_save']) && is_callable($page['after_save'])) {
            call_user_func($page['after_save'], $values, $savedId);
        }

        osc_run_hook('settings_page_saved', $pageId, $exposed);

        // Zero here is an unchanged row and a success: the store throws when a write fails
        // and refuses a key with no row behind it, so nothing else is left for the count
        // to mean.
        return array(
            'errors'  => array(),
            'updated' => (int)$written['updated'],
            'values'  => $values,
            'id'      => $savedId,
        );
    }
}

if (!function_exists('osc_settings_image_url')) {
    /**
     * The URL of the image stored in a declared page's image field, or '' when none is.
     *
     * Works whether or not the page is registered in this request. Unregistered, the id is
     * read from the preference named $name in the section named $pageId, which is where a
     * page that sets no 'section' or 'column' of its own stores it.
     *
     * @param string $pageId
     * @param string $name
     * @param string $variant '' for the main image, 'preview' or 'thumbnail'
     *
     * @return string
     */
    function osc_settings_image_url(string $pageId, string $name, string $variant = ''): string
    {
        $fields = SettingsPageRegistry::instance()->fields($pageId);
        if (isset($fields[$name])) {
            if ($fields[$name]['type'] !== 'image') {
                return '';
            }
            $id = osc_settings_value($pageId, $name);
        } elseif (osc_settings_page($pageId) === null) {
            $id = Preference::newInstance()->get($name, $pageId);
        } else {
            return '';
        }

        return SettingsImage::url($id, $variant);
    }
}

if (!function_exists('osc_settings_menu_init')) {
    /**
     * Put every declared page that asked for one into its menu section. Registered on
     * admin_menu_init, which runs after plugins have loaded and therefore after they have
     * declared their pages.
     *
     * @return void
     */
    function osc_settings_menu_init()
    {
        foreach (osc_settings_pages() as $id => $page) {
            if ($page['menu'] === '') {
                continue;
            }
            osc_add_admin_submenu_page(
                $page['menu'],
                $page['menu_title'],
                osc_settings_page_url($id),
                'settings-page-' . $id,
                $page['capability']
            );
        }
        SettingsPageRegistry::instance()->menuReady(true);
    }
}

osc_add_hook('admin_menu_init', 'osc_settings_menu_init');

/* file end: ./oc-includes/osclass/helpers/hSettings.php */
