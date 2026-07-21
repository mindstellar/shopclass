<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

function addHelp()
{
    echo '<p>'
         . __('Configure the XML sitemap: how many URLs go in each file, which extra location pages to '
              . 'include, any custom URLs to append, and the robots.txt that advertises it to search engines.')
         . '</p>';
}

osc_add_hook('help_box', 'addHelp');

function customPageHeader()
{
    ?>
    <h1><?php _e('Settings'); ?>
        <a class="ms-1 bi bi-question-circle float-end" data-bs-target="#help-box" data-bs-toggle="collapse"
           href="#help-box"></a>
    </h1>
    <?php
}

osc_add_hook('admin_page_header', 'customPageHeader');

/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Sitemap &raquo; %s'), $string);
}

osc_add_filter('admin_title', 'customPageTitle');

$prefs           = __get('prefs');
$custom_urls     = __get('custom_urls');
$robots_content  = __get('robots_content');
$robots_writable = __get('robots_writable');
$sitemap_index_url = __get('sitemap_index_url');

$sitemapChecks = array(
    'sitemap_cities'      => __('Include cities'),
    'sitemap_regions'     => __('Include regions'),
    'sitemap_countries'   => __('Include countries'),
    'sitemap_cat_regions' => __('Include categories with regions'),
    'sitemap_cat_city'    => __('Include categories with cities'),
);

$freqOptions = array(
    'hourly'  => __('Hourly'),
    'daily'   => __('Daily'),
    'weekly'  => __('Weekly'),
    'monthly' => __('Monthly'),
    'yearly'  => __('Yearly'),
);

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="sitemap-setting">
    <h2 class="render-title"><?php _e('Sitemap'); ?></h2>
    <p>
        <?php printf(
            __('The sitemap index is served at %s and always reflects the current site content.'),
            '<code>' . osc_esc_html($sitemap_index_url) . '</code>'
        ); ?>
    </p>

    <div id="sitemap-general-settings">
        <h3 class="render-title"><?php _e('Sitemap settings'); ?></h3>
        <form name="settings_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="sitemap_settings_post"/>
            <fieldset class="form-horizontal">
                <div class="form-row">
                    <div class="form-label"><?php _e('URLs per sitemap file'); ?></div>
                    <div class="form-controls">
                        <input type="text" class="input-medium" id="sitemap_number" name="sitemap_number"
                               value="<?php echo osc_esc_html($prefs['sitemap_number']); ?>"/>
                        <div class="help-box">
                            <?php _e('Number of URLs per XML item sitemap file. Extra listings roll into additional '
                                     . 'sitemaps automatically. Keep this low if you hit memory or timeout errors.'); ?>
                        </div>
                    </div>
                </div>
                <?php foreach ($sitemapChecks as $key => $label) { ?>
                    <div class="form-row">
                        <div class="form-label"><?php echo osc_esc_html($label); ?></div>
                        <div class="form-controls">
                            <div class="form-label-checkbox">
                                <input type="checkbox" id="<?php echo osc_esc_html($key); ?>"
                                       name="<?php echo osc_esc_html($key); ?>" value="1"
                                    <?php echo(!empty($prefs[$key]) ? 'checked="checked"' : ''); ?> />
                                <label for="<?php echo osc_esc_html($key); ?>"><?php echo osc_esc_html($label); ?></label>
                            </div>
                        </div>
                    </div>
                <?php } ?>
                <div class="form-actions">
                    <input type="submit" id="submit_sitemap_settings" value="<?php echo osc_esc_html(__('Save changes')); ?>"
                           class="btn btn-submit"/>
                </div>
            </fieldset>
        </form>
    </div>

    <div id="sitemap-custom-urls" class="separate-top">
        <h3 class="render-title"><?php _e('Custom sitemap URLs'); ?></h3>
        <p><?php _e('Add URLs the sitemap would not otherwise discover on its own, such as pages served by a plugin.'); ?></p>
        <form name="sitemap_url_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="sitemap_custom_url_add"/>
            <fieldset class="form-horizontal">
                <div class="form-row">
                    <div class="form-label"><?php _e('URL'); ?></div>
                    <div class="form-controls">
                        <input type="text" class="input-large" name="sitemap_url" placeholder="https://www.example.com/page"/>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Frequency'); ?></div>
                    <div class="form-controls">
                        <select class="form-select form-select-sm" name="sitemap_freq">
                            <?php foreach ($freqOptions as $value => $label) { ?>
                                <option value="<?php echo osc_esc_html($value); ?>"
                                    <?php echo ($value === 'weekly') ? 'selected="selected"' : ''; ?>>
                                    <?php echo osc_esc_html($label); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-label"><?php _e('Last modified'); ?></div>
                    <div class="form-controls">
                        <input type="text" class="input-medium" name="sitemap_lastmod" placeholder="YYYY-MM-DD"/>
                        <div class="help-box">
                            <?php _e('Optional. Leave blank to use today\'s date.'); ?>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <input type="submit" value="<?php echo osc_esc_html(__('Add URL')); ?>" class="btn btn-submit"/>
                </div>
            </fieldset>
        </form>

        <?php if (!empty($custom_urls)) { ?>
            <table class="table" cellpadding="0" cellspacing="0">
                <thead>
                <tr>
                    <th><?php _e('URL'); ?></th>
                    <th><?php _e('Frequency'); ?></th>
                    <th><?php _e('Last modified'); ?></th>
                    <th class="text-end"><?php _e('Remove'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($custom_urls as $index => $custom) { ?>
                    <tr>
                        <td data-col-name="<?php echo osc_esc_html(__('URL')); ?>">
                            <?php echo osc_esc_html($custom['url'] ?? ''); ?>
                        </td>
                        <td data-col-name="<?php echo osc_esc_html(__('Frequency')); ?>">
                            <?php echo osc_esc_html($custom['freq'] ?? ''); ?>
                        </td>
                        <td data-col-name="<?php echo osc_esc_html(__('Last modified')); ?>">
                            <?php echo osc_esc_html($custom['lastmod'] ?? ''); ?>
                        </td>
                        <td class="text-end">
                            <form name="sitemap_url_remove_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
                                <input type="hidden" name="page" value="settings"/>
                                <input type="hidden" name="action" value="sitemap_custom_url_remove"/>
                                <input type="hidden" name="sitemap_url_index" value="<?php echo osc_esc_html($index); ?>"/>
                                <input type="submit" value="<?php echo osc_esc_html(__('Remove')); ?>" class="btn btn-mini"/>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        <?php } ?>
    </div>

    <div id="sitemap-robots" class="separate-top">
        <h3 class="render-title"><?php _e('robots.txt'); ?></h3>
        <?php if (!$robots_writable) { ?>
            <div class="flashmessage flashmessage-error">
                <?php _e('robots.txt is not writable by the web server. Fix the file or folder permissions before saving changes here.'); ?>
            </div>
        <?php } ?>
        <form name="sitemap_robots_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="sitemap_robots_post"/>
            <fieldset class="form-horizontal">
                <div class="form-row">
                    <div class="form-label"><?php _e('robots.txt contents'); ?></div>
                    <div class="form-controls">
                        <textarea id="sitemap_robots" name="sitemap_robots" rows="10"
                                  style="width:100%;max-width:640px;"><?php echo osc_esc_html($robots_content); ?></textarea>
                        <div class="help-box text-danger">
                            <?php _e('Make a backup before changing your robots.txt file.'); ?>
                        </div>
                    </div>
                </div>
                <div class="form-actions">
                    <input type="submit" value="<?php echo osc_esc_html(__('Save robots.txt')); ?>" class="btn btn-submit"
                        <?php echo (!$robots_writable) ? 'disabled="disabled"' : ''; ?> />
                </div>
            </fieldset>
        </form>
    </div>

    <div id="sitemap-regenerate" class="separate-top">
        <h3 class="render-title"><?php _e('Regenerate'); ?></h3>
        <p><?php _e('The sitemap is cached for a few hours after it is first requested. Use this if you need '
                    . 'search engines to see fresh content immediately.'); ?></p>
        <form name="sitemap_regenerate_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="sitemap_regenerate"/>
            <input type="submit" value="<?php echo osc_esc_html(__('Regenerate / clear cache')); ?>" class="btn btn-dim"/>
        </form>
    </div>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
