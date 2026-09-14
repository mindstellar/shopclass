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

use Sitemap;

/**
 * The sitemap screen's two declared forms: the sitemap settings, and the robots.txt editor.
 * The custom-URL list and the regenerate button stay hand-written.
 *
 * @package mindstellar\admin\form
 */
final class SitemapSettingsForm
{
    public const PAGE_ID = 'core.settings_sitemap';

    public const PAGE_ROBOTS = 'core.settings_sitemap_robots';

    /** What sitemap_number holds until an admin saves one, and what a zero or blank becomes. */
    public const DEFAULT_NUMBER = 5000;

    /** The robots.txt box, and the id and name the screen has always given it. */
    public const ROBOTS = 'sitemap_robots';

    /** Include toggles in screen order, each with whether it is on before it is ever saved. */
    public const TOGGLES = array(
        'sitemap_categories'  => true,
        'sitemap_pages'       => true,
        'sitemap_cities'      => false,
        'sitemap_regions'     => false,
        'sitemap_countries'   => false,
        'sitemap_cat_regions' => false,
        'sitemap_cat_city'    => false,
    );

    /** @var bool whether the last robots.txt save reached the disk */
    private static bool $robotsWritten = false;

    /**
     * Declare the settings form, once per request.
     *
     * @return string the page id
     */
    public static function register(): string
    {
        if (osc_settings_page(self::PAGE_ID) !== null) {
            return self::PAGE_ID;
        }

        $labels = array(
            'sitemap_categories'  => __('Include categories'),
            'sitemap_pages'       => __('Include pages'),
            'sitemap_cities'      => __('Include cities'),
            'sitemap_regions'     => __('Include regions'),
            'sitemap_countries'   => __('Include countries'),
            'sitemap_cat_regions' => __('Include categories with regions'),
            'sitemap_cat_city'    => __('Include categories with cities'),
        );

        $form = CoreSettings::page(self::PAGE_ID, __('Sitemap settings'), Sitemap::PREF_GROUP)
            ->onAfterSave(static function () {
                osc_sitemap_clear_cache();
            })
            ->number(
                'sitemap_number',
                __('URLs per sitemap file'),
                __('Number of URLs per XML item sitemap file. Extra listings roll into additional '
                   . 'sitemaps automatically. Keep this low if you hit memory or timeout errors.')
            )
                ->set('id', 'sitemap_number')
                ->set('min', 1)
                ->suffix(__('URLs'))
                ->default(self::DEFAULT_NUMBER)
                // Corrected rather than refused, as the screen always did: a blank or a zero
                // is the default, and nothing past what one sitemap file may hold is stored.
                ->sanitize(static function ($value) {
                    return self::number($value);
                })
            ->custom('include_row_open', static function () {
                osc_admin_form_row_open(__('Include in sitemap'));
            })
                ->set('row', false);

        foreach (self::TOGGLES as $key => $defaultOn) {
            $form->checkbox($key, $labels[$key])
                ->set('row', false)
                ->set('id', $key)
                ->default($defaultOn);
        }

        $form
            ->custom('include_row_close', static function () {
                osc_admin_form_row_close();
            })
                ->set('row', false)
            ->register();

        return self::PAGE_ID;
    }

    /**
     * Declare the robots.txt form, once per request.
     *
     * @return string the page id
     */
    public static function registerRobots(): string
    {
        if (osc_settings_page(self::PAGE_ROBOTS) !== null) {
            return self::PAGE_ROBOTS;
        }

        CoreSettings::page(self::PAGE_ROBOTS, __('robots.txt'), Sitemap::PREF_GROUP)
            ->onValidate(static function () {
                return self::robotsWritable()
                    ? null
                    : _m('robots.txt is not writable. Fix the file or folder permissions and try again');
            })
            ->onAfterSave(static function (array $values) {
                // A before_save listener that drops the box leaves the file as it is, never empty.
                if (!array_key_exists(self::ROBOTS, $values)) {
                    self::$robotsWritten = true;

                    return;
                }
                self::writeRobots((string)$values[self::ROBOTS]);
            })
            ->textarea(self::ROBOTS, __('robots.txt contents'))
                ->set('id', self::ROBOTS)
                ->set('rows', 10)
                ->set('monospace', true)
                ->set(
                    'help_html',
                    '<span class="text-danger">'
                    . osc_esc_html(__('Make a backup before changing your robots.txt file.')) . '</span>'
                )
                ->width('key')
                ->purify(false)
                ->persist(false)
                // Re-read untrimmed: every declared box loses its surrounding whitespace, and
                // a robots.txt keeps its final newline. Line endings are the file's, not the browser's.
                ->sanitize(static function () {
                    return str_replace("\r\n", "\n", \Params::getParamString(self::ROBOTS, false, false, false));
                })
            ->register();

        return self::PAGE_ROBOTS;
    }

    /**
     * What the view needs to draw both forms, keyed by the div each one sits in.
     *
     * @param string                   $rejected the page id a refused save belongs to, if any
     * @param array<string,mixed>|null $values   that page's submitted values
     *
     * @return array<string,array<string,mixed>> view variables per div: 'settings', 'robots'
     */
    public static function formVars(string $rejected = '', ?array $values = null): array
    {
        $settings = self::register();
        $robots   = self::registerRobots();

        $settingsValues = $settings === $rejected && $values !== null ? $values : osc_settings_values($settings);
        // Stored before this screen clamped it, a zero or negative still reads as the default.
        if ((int)($settingsValues['sitemap_number'] ?? 0) <= 0) {
            $settingsValues['sitemap_number'] = self::DEFAULT_NUMBER;
        }

        $robotsValues = $robots === $rejected && $values !== null
            ? $values
            : array(self::ROBOTS => self::robotsContent());

        return array(
            'settings' => CoreSettings::vars(
                $settings,
                'sitemap_settings_post',
                $settingsValues,
                array(
                    'name'    => 'settings_form',
                    'actions' => array(
                        array(
                            'label' => __('Save changes'),
                            'type'  => 'submit',
                            'attrs' => array('id' => 'submit_sitemap_settings'),
                        ),
                    ),
                )
            ),
            'robots'   => CoreSettings::vars(
                $robots,
                'sitemap_robots_post',
                $robotsValues,
                array(
                    'name'    => 'sitemap_robots_form',
                    'actions' => array(
                        array(
                            'label' => __('Save robots.txt'),
                            'type'  => 'submit',
                            'attrs' => self::robotsWritable() ? array() : array('disabled' => 'disabled'),
                        ),
                    ),
                )
            ),
        );
    }

    /**
     * Absolute path of the site's robots.txt.
     *
     * @return string
     */
    public static function robotsPath(): string
    {
        return osc_base_path() . 'robots.txt';
    }

    /**
     * Whether robots.txt can be written: the file itself, or the folder it would be created in.
     *
     * @return bool
     */
    public static function robotsWritable(): bool
    {
        $path = self::robotsPath();

        return file_exists($path) ? is_writable($path) : is_writable(dirname($path));
    }

    /**
     * What the robots.txt box shows: the file, or the default body when there is none or it is blank.
     *
     * @return string
     */
    public static function robotsContent(): string
    {
        $path    = self::robotsPath();
        $content = file_exists($path) ? (string)file_get_contents($path) : '';

        return trim($content) === '' ? osc_sitemap_default_robots_txt() : $content;
    }

    /**
     * Whether the last robots.txt save reached the disk. A failed write happens after
     * validation, so the controller asks here before treating the save as done.
     *
     * @return bool
     */
    public static function robotsWritten(): bool
    {
        return self::$robotsWritten;
    }

    /**
     * The robots form's effect: put the file on disk and say how it went.
     *
     * @param string $content
     *
     * @return void
     */
    private static function writeRobots(string $content): void
    {
        self::$robotsWritten = file_put_contents(self::robotsPath(), $content, LOCK_EX) !== false;

        if (self::$robotsWritten) {
            osc_add_flash_ok_message(_m('robots.txt has been updated'), 'admin');
        } else {
            osc_add_flash_error_message(_m('robots.txt could not be saved'), 'admin');
        }
    }

    /**
     * A URLs-per-file count as the sitemap reads it: whole, positive, and within one file's limit.
     *
     * @param mixed $value
     *
     * @return int
     */
    private static function number($value): int
    {
        $number = (int)$value;
        if ($number <= 0) {
            $number = self::DEFAULT_NUMBER;
        }

        return min($number, Sitemap::MAX_SITEMAP_URLS);
    }
}
