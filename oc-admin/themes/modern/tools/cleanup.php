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

osc_admin_page(array(
    'section' => __('Tools'),
    'title'   => __('Cleanup'),
    'help'    => __('Remove stale content in bulk — expired, unactivated, spam, blocked and '
                    . 'reported listings, and unactivated users. Choose what to clean and how old '
                    . 'it must be, then run it now or let the daily task handle it.'),
));

// Rule metadata, in run order. `days` = whether the rule has an age threshold.
$cleanup_rules = array(
    'reported'          => array('label' => __('Reported listings'),    'desc' => __('Listings visitors have flagged as spam.'),            'days' => false),
    'expired'           => array('label' => __('Expired listings'),     'desc' => __('Listings past their expiration date.'),               'days' => true),
    'inactive_listings' => array('label' => __('Unactivated listings'), 'desc' => __('Listings never activated from the confirmation email.'), 'days' => true),
    'spam'              => array('label' => __('Spam listings'),        'desc' => __('Listings marked as spam.'),                          'days' => true),
    'blocked'           => array('label' => __('Blocked listings'),     'desc' => __('Listings that are disabled/blocked.'),                'days' => true),
    'inactive_users'    => array('label' => __('Unactivated users'),    'desc' => __('Accounts never activated from the confirmation email.'), 'days' => true),
);

$engine      = Cleanup::newInstance();
$batch_limit = (int)osc_get_preference('batch_limit', 'osclass');
if ($batch_limit < 1) {
    $batch_limit = 250;
}

osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Cleanup')); ?>

    <?php osc_admin_form_open(array('page' => 'tools', 'action' => 'cleanup_post')); ?>

        <div class="table-responsive">
        <table class="table" style="min-width:34rem">
            <thead>
            <tr>
                <th><?php _e('Enabled'); ?></th>
                <th><?php _e('What to remove'); ?></th>
                <th><?php _e('Older than'); ?></th>
                <th class="text-end"><?php _e('Matching now'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($cleanup_rules as $rule => $meta) {
                $enabled = osc_get_preference('enabled_' . $rule, 'osclass') == 1;
                $days    = (int)osc_get_preference('days_' . $rule, 'osclass');
                if ($days < 1) {
                    $days = 30;
                }
                $matching = $engine->countFor($rule, $meta['days'] ? $days : 0); ?>
                <tr>
                    <td>
                        <input type="checkbox" id="enabled_<?php echo $rule; ?>"
                               name="enabled_<?php echo $rule; ?>" value="1" <?php echo $enabled ? 'checked' : ''; ?>>
                    </td>
                    <td>
                        <label for="enabled_<?php echo $rule; ?>"><strong><?php echo osc_esc_html($meta['label']); ?></strong></label>
                        <div class="text-muted"><?php echo osc_esc_html($meta['desc']); ?></div>
                    </td>
                    <td>
                        <?php if ($meta['days']) {
                            osc_admin_number(array(
                                'row'    => false,
                                'name'   => 'days_' . $rule,
                                'value'  => $days,
                                'min'    => 1,
                                'suffix' => __('days'),
                            ));
                        } else { ?>
                            <span class="text-muted">&mdash;</span>
                        <?php } ?>
                    </td>
                    <td class="text-end"><?php echo number_format($matching); ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        </div>

        <?php osc_admin_form_row_open(__('Maximum items removed per run'), array('for' => 'batch_limit')); ?>
            <?php osc_admin_number(array(
                'row'   => false,
                'id'    => 'batch_limit',
                'name'  => 'batch_limit',
                'value' => $batch_limit,
                'min'   => 1,
                'help'  => __('Keeps each run bounded so it never times out; run again to clear a larger backlog.'),
            )); ?>
        <?php osc_admin_form_row_close(); ?>

        <?php osc_admin_page_head(__('Listing statistics')); ?>

        <p class="form-intro">
            <?php _e('View counts are written on every page render, so they are the busiest '
                     . 'write on a large site. Turn them off if you do not use them.'); ?>
        </p>

        <?php osc_admin_form_row_open(__('View counting')); ?>
            <?php osc_admin_checkbox(array(
                'id'      => 'item_views_enabled',
                'name'    => 'item_views_enabled',
                'label'   => __('Count listing views'),
                'checked' => osc_item_views_enabled(),
                'help'    => __('Report counts are always recorded — moderation depends on them.'),
            ));
            osc_admin_checkbox(array(
                'id'      => 'count_bot_views',
                'name'    => 'count_bot_views',
                'label'   => __('Count crawler visits as views'),
                'checked' => osc_count_bot_views(),
                'help'    => __('Off by default. Search-engine and AI crawlers are usually most of a busy '
                                . "site's traffic, so counting them both inflates the numbers and multiplies "
                                . 'the writes.'),
            )); ?>
        <?php osc_admin_form_row_close(); ?>

        <?php osc_admin_form_row_open(__('Keep daily statistics history for'), array('for' => 'item_stats_retention_days')); ?>
            <?php osc_admin_number(array(
                'row'    => false,
                'id'     => 'item_stats_retention_days',
                'name'   => 'item_stats_retention_days',
                'value'  => osc_item_stats_retention_days(),
                'min'    => 0,
                'suffix' => __('days'),
                'help'   => __('0 keeps it forever. This is the history behind the statistics charts, which '
                               . 'look back up to ten months; it is a few rows per day for the whole site.'),
            )); ?>
        <?php osc_admin_form_row_close(); ?>

    <?php osc_admin_form_close(array(
        array('label' => __('Save settings'), 'type' => 'submit', 'variant' => 'primary'),
        array(
            'label'   => __('Run cleanup now'),
            'type'    => 'button',
            'variant' => 'danger',
            'attrs'   => array('data-osc-dialog-open' => '#cleanup-run-dialog'),
        ),
    )); ?>

    <p class="text-muted mt-2">
        <i class="bi bi-clock-history"></i>
        <?php _e('Enabled rules also run automatically once a day.'); ?>
    </p>

    <?php osc_admin_confirm_dialog(array(
            'id'      => 'cleanup-run-dialog',
            'method'  => 'post',
            'fields'  => array('page' => 'tools', 'action' => 'cleanup_run'),
            'title'   => __('Run cleanup now?'),
            'text'    => __("This permanently deletes the matching listings and users for every enabled rule (up to the per-run limit). This can't be undone."),
            'confirm' => __('Delete matching items'),
        )); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
