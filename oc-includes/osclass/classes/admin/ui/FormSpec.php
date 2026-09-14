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

use LogicException;

/**
 * The builder behind osc_admin_form(). It is sugar and nothing else: every method writes
 * into a spec array, toArray() hands that array back, and register() passes it to the
 * registry unchanged. No storage, no rendering and no validation of its own -- a bad spec
 * still fails at registration, in the one place that already knows what a good one is.
 *
 * The array form stays fully supported. Anything the builder cannot express can be added
 * with field() or set(), so a page never has to drop back to a hand-written form.
 *
 * Four names that do not mean what a reader might assume:
 *   section()  sets the *preference section* the values are stored in -- the page-level
 *              'section' key. The visual sections of a page are groups: use group().
 *   default()  sets the field's 'default', the value used until something is saved.
 *   hint()     sets the field's 'help' text, because help() is taken by the page-level
 *              'help' key. A help() chained onto a field writes the page, not the field.
 *   validate() sets the *field's* check. The page's own, over more than one field, is
 *              onValidate().
 *
 * Modifiers apply to the field most recently added, so they read as a suffix on it:
 * ->text('s_name', $label)->required(). Calling one before any field is a programming
 * error and throws.
 *
 * @package mindstellar\admin\ui
 */
final class FormSpec
{
    /**
     * Emission order for page keys, so builder output does not depend on the order the
     * author happened to chain in. Keys never set are not emitted at all.
     */
    private const PAGE_KEY_ORDER = array(
        'title',
        'menu',
        'menu_title',
        'section',
        'store',
        'capability',
        'help',
        'intro',
        'validate',
        'after_save',
    );

    /** Emission order for field keys. Anything else follows, in the order it was set. */
    private const FIELD_KEY_ORDER = array(
        'type',
        'name',
        'column',
        'persist',
        'write_only',
        'label',
        'help',
        'default',
        'required',
        'disabled',
        'depends',
        'depends_value',
        'translate',
        'purify',
        'options',
        'row_label',
        'prefix',
        'suffix',
        'width',
        'attrs',
        'render',
        'sanitize',
        'validate',
    );

    private string $id;

    /** @var array<string,mixed> page-level keys, only those actually set */
    private array $page = array();

    /** @var array<int,array{title?:string,intro?:string,fields:array<int,array>}> */
    private array $groups = array();

    private ?int $groupIndex = null;

    private ?int $fieldIndex = null;

    /**
     * @param string $id
     */
    public function __construct(string $id)
    {
        $this->id = $id;
    }

    /**
     * The page id this spec registers under. Not part of the spec array -- the registry
     * takes it as its own argument.
     *
     * @return string
     */
    public function id(): string
    {
        return $this->id;
    }

    // --- page-level keys ------------------------------------------------------------

    /**
     * The page title.
     *
     * @param string $title
     *
     * @return self
     */
    public function title(string $title): self
    {
        return $this->setPage('title', $title);
    }

    /**
     * One of SettingsPageRegistry::MENUS, or '' for a page with no menu entry.
     *
     * @param string $menu
     *
     * @return self
     */
    public function menu(string $menu): self
    {
        return $this->setPage('menu', $menu);
    }

    /**
     * The label the menu entry carries, when it is not the page title.
     *
     * @param string $menuTitle
     *
     * @return self
     */
    public function menuTitle(string $menuTitle): self
    {
        return $this->setPage('menu_title', $menuTitle);
    }

    /**
     * The preference section the values are stored in. For a visual section, see group().
     *
     * @param string $section
     *
     * @return self
     */
    public function section(string $section): self
    {
        return $this->setPage('section', $section);
    }

    /**
     * Bind the page to one row of a table instead of to preferences. The name is
     * unprefixed -- core applies DB_TABLE_PREFIX -- and the save inserts when no key is
     * submitted and updates when one is.
     *
     * @param string $table
     * @param string $pk
     *
     * @return self
     */
    public function store(string $table, string $pk = 'pk_i_id'): self
    {
        return $this->setPage('store', array('table' => $table, 'pk' => $pk));
    }

    /**
     * Who may open and save the page: 'administrator' (the default) or 'moderator'.
     *
     * @param string $capability
     *
     * @return self
     */
    public function capability(string $capability): self
    {
        return $this->setPage('capability', $capability);
    }

    /**
     * The page's own help text. For a field's, see hint().
     *
     * @param string $help
     *
     * @return self
     */
    public function help(string $help): self
    {
        return $this->setPage('help', $help);
    }

    /**
     * The explanatory paragraph drawn above the first group.
     *
     * @param string $intro
     *
     * @return self
     */
    public function intro(string $intro): self
    {
        return $this->setPage('intro', $intro);
    }

    /**
     * The page's own rule over more than one field, run after every field has been checked
     * and handed the row being saved. For a rule about one field, see validate(), which is
     * the field modifier.
     *
     * @param mixed $callback callable(array $values, string $pageId, $id): string|string[]|null
     *
     * @return self
     */
    public function onValidate($callback): self
    {
        return $this->setPage('validate', $callback);
    }

    /**
     * The page's own effect, run once after a successful save and never after a rejected
     * one. Alternative to hooking 'admin_form_after_save' when the effect belongs to this
     * page alone.
     *
     * @param mixed $callback callable(array $values, string $id): void
     *
     * @return self
     */
    public function onAfterSave($callback): self
    {
        return $this->setPage('after_save', $callback);
    }

    // --- groups ---------------------------------------------------------------------

    /**
     * Start a new group. Fields added before the first group() land in an untitled one,
     * which is the single-group page most plugins want.
     *
     * @param string $title
     * @param string $intro
     *
     * @return self
     */
    public function group(string $title = '', string $intro = ''): self
    {
        $group = array();
        if ($title !== '') {
            $group['title'] = $title;
        }
        if ($intro !== '') {
            $group['intro'] = $intro;
        }
        $group['fields'] = array();

        $this->groups[]   = $group;
        $this->groupIndex = count($this->groups) - 1;
        $this->fieldIndex = null;

        return $this;
    }

    // --- fields ---------------------------------------------------------------------

    /**
     * Add a single-line text field.
     *
     * @param string $name
     * @param string $label
     * @param string $help
     *
     * @return self
     */
    public function text(string $name, string $label = '', string $help = ''): self
    {
        return $this->field($this->base('text', $name, $label, $help));
    }

    /**
     * Add an email field.
     *
     * @param string $name
     * @param string $label
     * @param string $help
     *
     * @return self
     */
    public function email(string $name, string $label = '', string $help = ''): self
    {
        return $this->field($this->base('email', $name, $label, $help));
    }

    /**
     * Add a URL field.
     *
     * @param string $name
     * @param string $label
     * @param string $help
     *
     * @return self
     */
    public function url(string $name, string $label = '', string $help = ''): self
    {
        return $this->field($this->base('url', $name, $label, $help));
    }

    /**
     * Add a telephone field.
     *
     * @param string $name
     * @param string $label
     * @param string $help
     *
     * @return self
     */
    public function tel(string $name, string $label = '', string $help = ''): self
    {
        return $this->field($this->base('tel', $name, $label, $help));
    }

    /**
     * Add a number field.
     *
     * @param string $name
     * @param string $label
     * @param string $help
     *
     * @return self
     */
    public function number(string $name, string $label = '', string $help = ''): self
    {
        return $this->field($this->base('number', $name, $label, $help));
    }

    /**
     * Add a colour field.
     *
     * @param string $name
     * @param string $label
     * @param string $help
     *
     * @return self
     */
    public function color(string $name, string $label = '', string $help = ''): self
    {
        return $this->field($this->base('color', $name, $label, $help));
    }

    /**
     * Add a masked secret field.
     *
     * @param string $name
     * @param string $label
     * @param string $help
     *
     * @return self
     */
    public function secret(string $name, string $label = '', string $help = ''): self
    {
        return $this->field($this->base('secret', $name, $label, $help));
    }

    /**
     * Add a multi-line text field.
     *
     * @param string $name
     * @param string $label
     * @param string $help
     *
     * @return self
     */
    public function textarea(string $name, string $label = '', string $help = ''): self
    {
        return $this->field($this->base('textarea', $name, $label, $help));
    }

    /**
     * Add a select field.
     *
     * @param string               $name
     * @param string               $label
     * @param array<string,string> $options value => label
     * @param string               $help
     *
     * @return self
     */
    public function select(string $name, string $label = '', array $options = array(), string $help = ''): self
    {
        $field = $this->base('select', $name, $label, $help);
        if ($options !== array()) {
            $field['options'] = $options;
        }

        return $this->field($field);
    }

    /**
     * Add a radio group.
     *
     * @param string               $name
     * @param string               $label
     * @param array<string,string> $options value => label
     * @param string               $help
     *
     * @return self
     */
    public function radio(string $name, string $label = '', array $options = array(), string $help = ''): self
    {
        $field = $this->base('radio', $name, $label, $help);
        if ($options !== array()) {
            $field['options'] = $options;
        }

        return $this->field($field);
    }

    /**
     * A value the page's own script computes from the controls beside it. Collected,
     * validated and stored like any other field, and drawn as a bare <input type="hidden">
     * with no row of its own. The label is still worth giving: it is what an error names.
     *
     * @param string $name
     * @param string $label
     *
     * @return self
     */
    public function hidden(string $name, string $label = ''): self
    {
        return $this->field($this->base('hidden', $name, $label, ''));
    }

    /**
     * Add a checkbox. The label sits beside the control; the row's own label comes from
     * rowLabel().
     *
     * @param string $name
     * @param string $label
     * @param string $help
     *
     * @return self
     */
    public function checkbox(string $name, string $label = '', string $help = ''): self
    {
        return $this->field($this->base('checkbox', $name, $label, $help));
    }

    /**
     * A field core has no type for. The callable emits the control into the normal row.
     *
     * @param string $name
     * @param mixed  $render callable(array $field, array $values): void
     * @param string $label
     *
     * @return self
     */
    public function custom(string $name, $render = null, string $label = ''): self
    {
        $field = $this->base('custom', $name, $label, '');
        if ($render !== null) {
            $field['render'] = $render;
        }

        return $this->field($field);
    }

    /**
     * Append a field spec as written. The escape hatch for anything the typed methods do
     * not cover; the array form of a field is what the registry stores either way.
     *
     * @param array<string,mixed> $field
     *
     * @return self
     */
    public function field(array $field): self
    {
        if ($this->groupIndex === null) {
            $this->group();
        }

        $this->groups[$this->groupIndex]['fields'][] = $field;
        $this->fieldIndex                            = count($this->groups[$this->groupIndex]['fields']) - 1;

        return $this;
    }

    // --- field modifiers ------------------------------------------------------------

    /**
     * Whether the field most recently added must be filled in.
     *
     * @param bool $required
     *
     * @return self
     * @throws LogicException when no field has been added yet
     */
    public function required(bool $required = true): self
    {
        return $this->set('required', $required);
    }

    /**
     * Whether the field most recently added is drawn disabled.
     *
     * @param bool $disabled
     *
     * @return self
     * @throws LogicException when no field has been added yet
     */
    public function disabled(bool $disabled = true): self
    {
        return $this->set('disabled', $disabled);
    }

    /**
     * The value used until something is saved.
     *
     * @param mixed $value
     *
     * @return self
     * @throws LogicException when no field has been added yet
     */
    public function default($value): self
    {
        return $this->set('default', $value);
    }

    /**
     * Show this field only while another field on the same page is switched on, or, given
     * $value, while that field holds one of those values. Server side as well as client
     * side: while the master is off the value is discarded and the field is not required.
     *
     * @param string                   $master
     * @param string|string[]|null     $value
     *
     * @return self
     * @throws LogicException when no field has been added yet
     */
    public function dependsOn(string $master, string|array|null $value = null): self
    {
        $this->set('depends', $master);
        if ($value !== null) {
            return $this->set('depends_value', $value);
        }
        // Re-pointing a field at an on/off master must not keep the value list it had.
        unset($this->groups[$this->groupIndex]['fields'][$this->fieldIndex]['depends_value']);

        return $this;
    }

    /**
     * The key this field is stored under, when it is not the field's own name: a column on
     * a table store, a preference name on a preference one.
     *
     * @param string $column
     *
     * @return self
     * @throws LogicException when no field has been added yet
     */
    public function column(string $column): self
    {
        return $this->set('column', $column);
    }

    /**
     * What this field's key takes.
     *
     * false is a field that is stored nowhere -- a confirmation box, a re-authentication
     * box, a control another field is derived from -- collected and validated like any
     * other and never written. A callable is handed the validated value and every other
     * validated value, and returns what is stored; null from it writes nothing, which is
     * how "blank means unchanged" is declared.
     *
     * It says nothing about what the control shows on the way back: that is writeOnly().
     *
     * @param mixed $persist false, or callable(mixed $value, array $values): mixed|null
     *
     * @return self
     * @throws LogicException when no field has been added yet
     */
    public function persist($persist): self
    {
        return $this->set('persist', $persist);
    }

    /**
     * Whether the control shows what is stored.
     *
     * Off by default, so a field draws the stored value. On, nothing is read and the
     * control draws the declared default -- for a value whose stored form is not the one
     * that was typed, or that has no stored form at all. A secret must say which it is.
     *
     * @param bool $writeOnly
     *
     * @return self
     * @throws LogicException when no field has been added yet
     */
    public function writeOnly(bool $writeOnly = true): self
    {
        return $this->set('write_only', $writeOnly);
    }

    /**
     * One control per enabled locale, each stored under its own key. text and textarea only.
     *
     * @param bool $translate
     *
     * @return self
     * @throws LogicException when no field has been added yet
     */
    public function translate(bool $translate = true): self
    {
        return $this->set('translate', $translate);
    }

    /**
     * Store the value as submitted, apart from the trim every field gets, instead of
     * reducing it to plain text. For a field that holds markup or code on purpose;
     * text, textarea, tel and color only. Either way the stored value is printed through
     * osc_esc_html() or osc_esc_js(): this governs stripping, not escaping.
     *
     * @param bool $purify
     *
     * @return self
     * @throws LogicException when no field has been added yet
     */
    public function purify(bool $purify = false): self
    {
        return $this->set('purify', $purify);
    }

    /**
     * The choices of a select or radio field.
     *
     * @param array<string,string> $options value => label
     *
     * @return self
     * @throws LogicException when no field has been added yet
     */
    public function options(array $options): self
    {
        return $this->set('options', $options);
    }

    /**
     * The row label of a checkbox, whose own label sits beside the control.
     *
     * @param string $label
     *
     * @return self
     * @throws LogicException when no field has been added yet
     */
    public function rowLabel(string $label): self
    {
        return $this->set('row_label', $label);
    }

    /**
     * The field's 'help' text. The page-level help() is a different key.
     *
     * @param string $help
     *
     * @return self
     * @throws LogicException when no field has been added yet
     */
    public function hint(string $help): self
    {
        return $this->set('help', $help);
    }

    /**
     * Static text drawn before the control.
     *
     * @param string $prefix
     *
     * @return self
     * @throws LogicException when no field has been added yet
     */
    public function prefix(string $prefix): self
    {
        return $this->set('prefix', $prefix);
    }

    /**
     * Static text drawn after the control.
     *
     * @param string $suffix
     *
     * @return self
     * @throws LogicException when no field has been added yet
     */
    public function suffix(string $suffix): self
    {
        return $this->set('suffix', $suffix);
    }

    /**
     * The control's width class.
     *
     * @param string $width
     *
     * @return self
     * @throws LogicException when no field has been added yet
     */
    public function width(string $width): self
    {
        return $this->set('width', $width);
    }

    /**
     * Extra HTML attributes put on the control.
     *
     * @param array<string,string> $attrs
     *
     * @return self
     * @throws LogicException when no field has been added yet
     */
    public function attrs(array $attrs): self
    {
        return $this->set('attrs', $attrs);
    }

    /**
     * The callable that draws a custom field's control.
     *
     * @param mixed $render callable(array $field, array $values): void
     *
     * @return self
     * @throws LogicException when no field has been added yet
     */
    public function render($render): self
    {
        return $this->set('render', $render);
    }

    /**
     * The field's own tidy-up, applied to the submitted value before it is checked.
     *
     * @param mixed $callback callable(mixed $value): mixed, run before validation. On a
     *                        purified type the tags are already out of the value it gets.
     *
     * @return self
     * @throws LogicException when no field has been added yet
     */
    public function sanitize($callback): self
    {
        return $this->set('sanitize', $callback);
    }

    /**
     * The field's own check, returning an error message or null. The page's own rule over
     * more than one field is onValidate().
     *
     * @param mixed $callback callable(mixed $value, array $field): ?string
     *
     * @return self
     * @throws LogicException when no field has been added yet
     */
    public function validate($callback): self
    {
        return $this->set('validate', $callback);
    }

    /**
     * Floor the value at $min and tell the browser the same floor. Written out by hand that
     * is two numbers that can disagree; here it is one, for the number a screen corrects
     * rather than refuses.
     *
     * @param int $min
     *
     * @return self
     * @throws LogicException when no field has been added yet
     */
    public function clampMin(int $min): self
    {
        return $this
            ->set('min', $min)
            ->sanitize(static function ($value) use ($min) {
                return max($min, (int)$value);
            });
    }

    /**
     * Set any key on the field most recently added. Covers everything osc_admin_field()
     * understands that has no method of its own.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return self
     * @throws LogicException when no field has been added yet
     */
    public function set(string $key, $value): self
    {
        if ($this->groupIndex === null || $this->fieldIndex === null) {
            throw new LogicException('FormSpec: "' . $key . '" was set before any field was added');
        }

        $this->groups[$this->groupIndex]['fields'][$this->fieldIndex][$key] = $value;

        return $this;
    }

    // --- output ---------------------------------------------------------------------

    /**
     * The spec array, exactly as osc_register_settings_page() takes it. Inspectable
     * without registering, which is how builder output is held equal to a hand-written
     * array.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $spec = self::ordered($this->page, self::PAGE_KEY_ORDER);

        $groups = array();
        foreach ($this->groups as $group) {
            $fields = array();
            foreach ($group['fields'] as $field) {
                $fields[] = self::ordered($field, self::FIELD_KEY_ORDER);
            }
            $group['fields'] = $fields;
            $groups[]        = $group;
        }
        $spec['groups'] = $groups;

        return $spec;
    }

    /**
     * Hand the spec to the registry. Goes through osc_register_settings_page() so the
     * builder and the array form enter by the same door.
     *
     * @throws \InvalidArgumentException on an invalid id or spec.
     */
    public function register(): void
    {
        osc_register_settings_page($this->id, $this->toArray());
    }

    // --- internals ------------------------------------------------------------------

    /**
     * Set a page-level key.
     *
     * @param string $key
     * @param mixed  $value
     *
     * @return self
     */
    private function setPage(string $key, $value): self
    {
        $this->page[$key] = $value;

        return $this;
    }

    /**
     * The shared start of a field spec: type and name, plus label and help when given.
     *
     * @param string $type
     * @param string $name
     * @param string $label
     * @param string $help
     *
     * @return array<string,string>
     */
    private function base(string $type, string $name, string $label, string $help): array
    {
        $field = array('type' => $type, 'name' => $name);
        if ($label !== '') {
            $field['label'] = $label;
        }
        if ($help !== '') {
            $field['help'] = $help;
        }

        return $field;
    }

    /**
     * Reorder an array onto a known key order, keeping anything unlisted after it in the
     * order it was set. Key order is part of what toArray() promises: two specs that say
     * the same thing have to compare equal.
     *
     * @param array<string,mixed> $values
     * @param array<int,string>   $order
     *
     * @return array<string,mixed>
     */
    private static function ordered(array $values, array $order): array
    {
        $out = array();
        foreach ($order as $key) {
            if (array_key_exists($key, $values)) {
                $out[$key] = $values[$key];
                unset($values[$key]);
            }
        }

        return $out + $values;
    }
}
