<?php

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\admin\form;

use mindstellar\utility\Utils;

/**
 * The general settings screen: what the site is called, how it counts and formats things,
 * and the handful of keys and switches that have nowhere better to live.
 *
 * Three of its controls are not what they look like. The date and time formats are picked
 * from a list of samples or typed by hand, and the page's own script writes the answer into
 * a hidden field -- so the samples are drawn by hand and the hidden field is what is stored.
 * And the contact address is the one preference a demo install refuses to write, so on one
 * it is declared as a control that is stored nowhere rather than left off the screen.
 *
 * Four controls keep the names the screen has always posted while their values go on living
 * under the preference keys every reader uses.
 *
 * @package mindstellar\admin\form
 */
final class MainSettingsForm
{
    public const PAGE_ID = 'core.settings_main';

    /** The date formats offered as samples; anything else is the free-text box. */
    public const DATE_FORMATS = array('F j, Y', 'Y/m/d', 'm/d/Y', 'd/m/Y');

    /** The time formats offered as samples; anything else is the free-text box. */
    public const TIME_FORMATS = array('g:i a', 'g:i A', 'H:i');

    /**
     * The formats the samples should be drawn against: what is stored, or what was just
     * submitted and refused. Read at render time rather than captured, because the
     * declaration is built once per request and a rejected save redraws it.
     *
     * @var array<string,string>
     */
    private static array $shown = array();

    /**
     * Declare the form, once per request.
     *
     * @return string the page id
     */
    public static function register(): string
    {
        if (osc_settings_page(self::PAGE_ID) !== null) {
            return self::PAGE_ID;
        }

        $form = CoreSettings::page(self::PAGE_ID, __('General Settings'));

        $form
            ->text('pageTitle', __('Page title'))
                ->required()
                ->validate(static fn ($value) => osc_validate_text($value) ? null : _m('Page title field is required'))
            ->text('pageDesc', __('Page description'))
            ->email('contactEmail', __('Contact e-mail'))
                ->required();

        if (defined('DEMO')) {
            // A demo install shows the address and refuses to change it, which is what the
            // hand-written controller did by stepping over one write.
            $form->persist(false);
        }

        $form
            ->select('language', __('Default language'), self::locales())
            ->select('currency', __('Default currency'), self::currencies())
                // The one id on this screen that is not the control's own name, so it is
                // the one worth keeping.
                ->set('id', 'currency_admin')
            ->select('weekStart', __('Week starts on'), array(
                '0' => __('Sunday'),
                '1' => __('Monday'),
                '2' => __('Tuesday'),
                '3' => __('Wednesday'),
                '4' => __('Thursday'),
                '5' => __('Friday'),
                '6' => __('Saturday'),
            ))
            ->select('timezone', __('Timezone'), self::timezones())
                ->width('text')
            ->custom('date_time_formats', static function () {
                self::drawFormats();
            }, __('Date & time format'))
            // The two ids the page's own script writes the chosen format into. Derived
            // from the name they would be field-dateFormat, and the lookup would find
            // nothing: the samples would stop writing anything and the old value would be
            // submitted back unchanged.
            ->hidden('dateFormat', __('Date format'))
                ->set('id', 'dateFormat')
            ->hidden('timeFormat', __('Time format'))
                ->set('id', 'timeFormat')
            ->number('num_rss_items', __('RSS shows'))
                ->set('min', 0)
                ->suffix(__('listings at most'))
                ->sanitize(self::intOrZero())
                ->default(0)
            ->number('max_latest_items_at_home', __('Latest listings shown'))
                ->column('maxLatestItems@home')
                ->set('min', 0)
                ->suffix(__('at most'))
                ->sanitize(self::intOrZero())
                ->default(0)
            ->number('default_results_per_page', __('Search page shows'))
                ->column('defaultResultsPerPage@search')
                ->set('min', 0)
                ->suffix(__('listings at most'))
                ->sanitize(self::intOrZero())
                ->default(0)
            ->group(__('Category settings'))
            ->checkbox(
                'selectable_parent_categories',
                __('Allow users to select a parent category as a category
                            when inserting or editing a listing ')
            )
                ->rowLabel(__('Parent categories'))
            ->group(__('Contact Settings'))
            ->checkbox('enabled_attachment', __('Allow people to attach a file to the contact form'))
                ->column('contact_attachment')
                ->rowLabel(__('Attachments'))
            ->group(__('Cron Settings'))
            ->checkbox('auto_cron')
                ->rowLabel(__('Automatic cron process'))
                ->set('label_html', sprintf(
                    __('Allow Shopclass to run a built-in <a href="%s" target="_blank" rel="noopener">cron</a>'
                       . ' automatically without setting crontab'),
                    'https://en.wikipedia.org/wiki/Cron'
                ))
                ->set(
                    'help_html',
                    __('It is <b>recommended</b> to have this option enabled, because some features require it.')
                )
            ->group(__('Maps'))
            // Not a secret: a Maps JavaScript key is public by design -- it ships in the
            // page source -- so masking it would only hide it from the administrator who
            // has to check it.
            ->text('googlemaps_api_key', __('Google Maps key'), __('Add your Google Maps JavaScript API key.'))
                ->width('key')
            ->text('openstreet_api_key', __('OpenStreetMaps key'), __('Add your Mapquest Consumer key.'))
                ->width('key')
            ->group(__('Software updates'))
            ->custom('version_check', static function () {
                self::drawVersionCheck();
            })
            ->checkbox('allow_update_prerelease', __('Allow prerelease update'))
                ->rowLabel(__('Allow Prerelease'))
            ->custom('layout_clear', static function () {
                echo '<div class="clear"></div>';
            })
                ->set('row', false)
            ->register();

        return self::PAGE_ID;
    }

    /**
     * What the view needs to draw the form.
     *
     * @param array<string,mixed>|null $values values a rejected save is handing back, or null
     *                                         for the stored ones
     *
     * @return array<string,mixed> view variables for osc_admin_settings_form()
     */
    public static function formVars(?array $values = null): array
    {
        $vars = CoreSettings::vars(
            self::register(),
            'update',
            $values,
            array('name' => 'settings_form')
        );

        // The samples are drawn against whatever the hidden fields are carrying, so a
        // refused save redraws the choice that was made rather than the one on file.
        self::$shown = array(
            'dateFormat' => (string)($vars['values']['dateFormat'] ?? ''),
            'timeFormat' => (string)($vars['values']['timeFormat'] ?? ''),
        );

        return $vars;
    }

    /**
     * Every enabled locale, code => name.
     *
     * @return array<string,string>
     */
    private static function locales(): array
    {
        $options = array();
        foreach (\OSCLocale::newInstance()->listAllEnabled() as $locale) {
            $options[$locale['pk_c_code']] = $locale['s_name'];
        }

        return self::orStored($options, (string)osc_language());
    }

    /**
     * Every currency the site knows, by its own code.
     *
     * @return array<string,string>
     */
    private static function currencies(): array
    {
        $options = array();
        foreach (\Currency::newInstance()->listAll() as $currency) {
            $options[$currency['pk_c_code']] = $currency['pk_c_code'];
        }

        return self::orStored($options, (string)osc_currency());
    }

    /**
     * A choice list that came back empty falls back to what is stored. A select with no
     * options is a spec error, and a spec error here is the whole settings screen gone --
     * far worse than a list with one entry on an install that somehow has no others.
     *
     * @param array<string,string> $options
     * @param string               $current the stored value, used as the sole option when
     *                                      the list is empty
     *
     * @return array<string,string>
     */
    private static function orStored(array $options, string $current): array
    {
        return $options === array() ? array($current => $current) : $options;
    }

    /**
     * Every timezone, by its own name.
     *
     * @return array<string,string>
     */
    private static function timezones(): array
    {
        $options = array();
        foreach (Utils::timezoneList() as $timezone) {
            $options[$timezone] = $timezone;
        }

        return $options;
    }

    /**
     * A blank box is zero here, which is what the screen has always stored for one; a
     * negative number is left negative so the declared minimum refuses it.
     *
     * @return callable(mixed):int
     */
    private static function intOrZero(): callable
    {
        return static function ($value) {
            return (int)$value;
        };
    }

    /**
     * The date and time sample lists, and the free-text box beside each. The page's own
     * script copies whichever is chosen into the hidden field that carries the value.
     *
     * @return void
     */
    private static function drawFormats()
    {
        echo '<div class="custom-date-time">';
        self::drawFormatGroup(
            'date',
            'df',
            __('Date'),
            __('Custom date format'),
            self::DATE_FORMATS,
            (string)(self::$shown['dateFormat'] ?? '')
        );
        self::drawFormatGroup(
            'time',
            'tf',
            __('Time'),
            __('Custom time format'),
            self::TIME_FORMATS,
            (string)(self::$shown['timeFormat'] ?? '')
        );
        echo '</div>';
        echo '<div class="help-box">'
            . '<a href="https://php.net/date" target="_blank" rel="noopener">'
            . osc_esc_html(__('Documentation on date and time formatting'))
            . '</a></div>';
    }

    /**
     * One of the two sample lists.
     *
     * @param string   $wrapperId   id of the wrapper the page's script targets
     * @param string   $group       the radio group's name, and the prefix of its custom entry
     * @param string   $label
     * @param string   $customLabel accessible name of the free-text box
     * @param string[] $formats     the samples offered
     * @param string   $current     the format in force, selecting a sample or filling the box
     *
     * @return void
     */
    private static function drawFormatGroup(
        string $wrapperId,
        string $group,
        string $label,
        string $customLabel,
        array $formats,
        string $current
    ) {
        $options = array();
        foreach ($formats as $format) {
            $options[$format] = date($format);
        }
        $isCustom                     = !in_array($current, $formats, true);
        $options[$group . '_custom'] = array(
            'label'       => __('Custom'),
            'custom_html' => '<input type="text" name="' . $group . '_custom_text" id="' . $group . '_custom_text"'
                . ' class="input-text field-inline-text"'
                . ' aria-label="' . osc_esc_html($customLabel) . '"'
                . ' value="' . ($isCustom ? osc_esc_html($current) : '') . '" />',
        );

        echo '<div id="' . osc_esc_html($wrapperId) . '">';
        echo '<div class="form-sublabel">' . osc_esc_html($label) . '</div>';
        osc_admin_radio_group(array(
            'row'      => false,
            'name'     => $group,
            'label'    => $label,
            'selected' => $isCustom ? $group . '_custom' : $current,
            'options'  => $options,
        ));
        echo '<span id="custom_' . osc_esc_html($wrapperId) . '"></span>';
        echo '</div>';
    }

    /**
     * When core last looked for a new release, and the button that looks again.
     *
     * @return void
     */
    private static function drawVersionCheck()
    {
        $last = (int)osc_get_preference('last_version_check');
        echo '<div class="field-inline"><span id="last-version-check">'
            . osc_esc_html(__('Last checked on ') . ($last > 0
                ? osc_format_date(date('d-m-Y h:i:s', $last))
                : __('never')))
            . '</span>';
        osc_admin_action_button(array(
            'label'   => __('Check updates'),
            'type'    => 'button',
            'variant' => 'secondary',
            'attrs'   => array('id' => 'check-updates'),
        ));
        echo '</div>';
    }
}
