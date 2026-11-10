<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The chrome around the declared sitemap settings and robots.txt forms. The custom-URL list
 * and the regenerate button store nothing a declaration describes and are still drawn here.
 */

osc_admin_page(array(
    'section' => __('Settings'),
    'heading' => __('Sitemap'),
    'title'   => __('Sitemap'),
    'help'    => __('Configure the XML sitemap: how many URLs go in each file, which extra location pages to '
                    . 'include, any custom URLs to append, and the robots.txt that advertises it to search engines.'),
));

$forms             = __get('sitemap_forms');
$custom_urls       = __get('custom_urls');
$robots_writable   = __get('robots_writable');
$sitemap_index_url = __get('sitemap_index_url');

$freqOptions = array(
    'hourly'  => __('Hourly'),
    'daily'   => __('Daily'),
    'weekly'  => __('Weekly'),
    'monthly' => __('Monthly'),
    'yearly'  => __('Yearly'),
);

osc_current_admin_theme_path('parts/header.php'); ?>
<?php osc_admin_body_open(array('id' => 'sitemap-setting')); ?>
    <p>
        <?php printf(
            __('The sitemap index is served at %s and always reflects the current site content.'),
            '<code>' . osc_esc_html($sitemap_index_url) . '</code>'
        ); ?>
    </p>

    <?php osc_admin_section(__('Sitemap settings'), array('id' => 'sitemap-general-settings')); ?>
        <?php osc_admin_settings_form($forms['settings']['id'], $forms['settings']); ?>
    <?php osc_admin_section_close(); ?>

    <?php osc_admin_section(__('Custom sitemap URLs'), array('id' => 'sitemap-custom-urls')); ?>
        <p><?php _e('Add URLs the sitemap would not otherwise discover on its own, such as pages served by a plugin.'); ?></p>
        <?php osc_admin_form_open(array(
            'name'   => 'sitemap_url_form',
            'page'   => 'settings',
            'action' => 'sitemap_custom_url_add',
        )); ?>
                <?php
                osc_admin_field(array(
                    'type'        => 'url',
                    'name'        => 'sitemap_url',
                    'label'       => __('URL'),
                    'placeholder' => 'https://www.example.com/page',
                ));
                osc_admin_select(array(
                    'name'     => 'sitemap_freq',
                    'label'    => __('Frequency'),
                    'selected' => 'weekly',
                    'options'  => $freqOptions,
                ));
                osc_admin_text(array(
                    'name'        => 'sitemap_lastmod',
                    'label'       => __('Last modified'),
                    'placeholder' => 'YYYY-MM-DD',
                    'width'       => 'num',
                    'attrs'       => array('inputmode' => 'numeric'),
                    'help'        => __('Optional. Leave blank to use today\'s date.'),
                )); ?>
                <?php osc_admin_form_close(array(
                    array('label' => __('Add URL'), 'type' => 'submit'),
                )); ?>

        <?php if (!empty($custom_urls)) { ?>
            <?php
            $tableRows = array();
            foreach ($custom_urls as $index => $custom) {
                $tableRows[] = array(
                    'url'     => (string) ($custom['url'] ?? ''),
                    'freq'    => (string) ($custom['freq'] ?? ''),
                    'lastmod' => (string) ($custom['lastmod'] ?? ''),
                    'remove'  => array('class' => 'text-end', 'render' => static function () use ($index): void {
                        osc_admin_form_open(array(
                            'name'       => 'sitemap_url_remove_form',
                            'page'       => 'settings',
                            'action'     => 'sitemap_custom_url_remove',
                            'fields'     => array('sitemap_url_index' => $index),
                            'horizontal' => false,
                        ));
                        osc_admin_action_button(array(
                            'label'   => __('Remove'),
                            'type'    => 'submit',
                            'variant' => 'dim',
                        ));
                        osc_admin_form_close(null, array('horizontal' => false));
                    }),
                );
            }
            osc_admin_table(array(
                'url'     => __('URL'),
                'freq'    => __('Frequency'),
                'lastmod' => __('Last modified'),
                'remove'  => array('label' => __('Remove'), 'class' => 'text-end', 'col_name' => false),
            ), $tableRows, array('wrap' => false, 'stack' => true));
            ?>
        <?php } ?>
    <?php osc_admin_section_close(); ?>

    <?php osc_admin_section(__('robots.txt'), array('id' => 'sitemap-robots')); ?>
        <?php if (!$robots_writable) { ?>
            <?php osc_admin_callout('danger', __('robots.txt is not writable by the web server. Fix the file or folder permissions before saving changes here.')); ?>
        <?php } ?>
        <?php osc_admin_settings_form($forms['robots']['id'], $forms['robots']); ?>
    <?php osc_admin_section_close(); ?>

    <?php osc_admin_action_section(array(
        'title'   => __('Regenerate'),
        'intro'   => __('The sitemap is cached for a few hours after it is first requested. Use this if you need '
                        . 'search engines to see fresh content immediately.'),
        'actions' => array(
            array(
                'label'   => __('Regenerate / clear cache'),
                'page'    => 'settings',
                'action'  => 'sitemap_regenerate',
                'name'    => 'sitemap_regenerate_form',
                'variant' => 'primary',
            ),
        ),
    )); ?>
<?php osc_admin_body_close(); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
