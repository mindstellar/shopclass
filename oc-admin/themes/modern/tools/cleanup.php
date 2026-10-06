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

osc_admin_page(array(
    'section' => __('Tools'),
    'title'   => __('Cleanup'),
    'help'    => __('Remove stale content in bulk — expired, unactivated, spam, blocked and '
                    . 'reported listings, and unactivated users. Choose what to clean and how old '
                    . 'it must be, then run it now or let the daily task handle it.'),
));

// What each rule removes, in run order; the names come from Cleanup::ruleLabels().
$cleanup_rules = array(
    'reported'          => __('Listings visitors have flagged as spam, unchanged since.'),
    'expired'           => __('Listings past their expiration date.'),
    'inactive_listings' => __('Listings never activated from the confirmation email.'),
    'spam'              => __('Listings marked as spam.'),
    'blocked'           => __('Listings that are disabled/blocked.'),
    'inactive_users'    => __('Accounts never activated from the confirmation email.'),
    'orphan_avatars'    => __('Profile pictures left behind by deleted accounts.'),
);
$rule_labels = Cleanup::ruleLabels();

$engine      = Cleanup::getInstance();
$batch_limit = Cleanup::batchLimit();
$running     = \mindstellar\job\CleanupJobs::isRunning();
$history     = View::getInstance()->_get('cleanup_history') ?: array();

osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Cleanup'), array(array(
        'label'   => __('Run cleanup now'),
        'icon'    => 'bi-trash3',
        'variant' => 'outline-danger',
        'attrs'   => array('data-osc-dialog-open' => '#cleanup-run-dialog'),
    ))); ?>

    <p class="text-muted">
        <?php if ($running) {
            osc_admin_status('active', __('Running'));
            echo ' ';
            _e('Cleanup is running in the background. Reload this page to see the counts go down.');
        } else { ?>
            <i class="bi bi-clock-history" aria-hidden="true"></i>
            <?php if ($history !== array()) {
                printf(__('Enabled rules run once a day. The last cleanup finished %s.'), osc_admin_when($history[0]['dt_date']));
            } else {
                _e('Enabled rules run once a day.');
            }
        } ?>
    </p>

    <?php osc_admin_form_open(array('page' => 'tools', 'action' => 'cleanup_post')); ?>

        <?php osc_admin_form_section(__('What to remove'), array(
            'intro' => __('Tick what to clean and how old it must be. Everything removed is gone for good.'),
        )); ?>

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
            <?php foreach ($cleanup_rules as $rule => $desc) {
                $enabled  = Cleanup::isEnabled($rule);
                $days     = Cleanup::days($rule);
                $matching = $engine->countFor($rule, $days); ?>
                <tr>
                    <td>
                        <input type="checkbox" id="enabled_<?php echo $rule; ?>"
                               name="enabled_<?php echo $rule; ?>" value="1" <?php echo $enabled ? 'checked' : ''; ?>>
                    </td>
                    <td>
                        <label for="enabled_<?php echo $rule; ?>"><strong><?php echo osc_esc_html($rule_labels[$rule]); ?></strong></label>
                        <div class="text-muted"><?php echo osc_esc_html($desc); ?></div>
                    </td>
                    <td>
                        <?php osc_admin_number(array(
                            'row'    => false,
                            'name'   => 'days_' . $rule,
                            'value'  => $days,
                            'min'    => 1,
                            'suffix' => __('days'),
                        )); ?>
                    </td>
                    <td class="text-end"><?php echo number_format($matching); ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
        </div>

        <?php osc_admin_form_row_open(__('Items removed per batch'), array('for' => 'batch_limit')); ?>
            <?php osc_admin_number(array(
                'row'   => false,
                'id'    => 'batch_limit',
                'name'  => 'batch_limit',
                'value' => $batch_limit,
                'min'   => 1,
                'help'  => __('Cleanup runs in the background, one batch at a time, until nothing matches.'),
            )); ?>
        <?php osc_admin_form_row_close(); ?>

        <?php osc_admin_form_section(__('Listing statistics'), array(
            'spaced' => true,
            'intro'  => __('View counts are written on every page render, so they are the busiest '
                           . 'write on a large site. Turn them off if you do not use them.'),
        )); ?>

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
    )); ?>

    <?php osc_admin_form_section(__('Recent cleanups'), array('spaced' => true)); ?>
    <?php if ($history === array()) { ?>
        <p class="text-muted"><?php _e('Nothing has been cleaned up yet.'); ?></p>
    <?php } else { ?>
        <div class="table-responsive">
            <table class="table" style="min-width:30rem">
                <thead>
                <tr>
                    <th><?php _e('When'); ?></th>
                    <th><?php _e('Details'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $entry) { ?>
                    <tr>
                        <td class="text-nowrap"><?php echo osc_admin_when($entry['dt_date']); ?></td>
                        <td><?php echo osc_esc_html((string) $entry['s_data']); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    <?php } ?>

    <?php osc_admin_confirm_dialog(array(
            'id'      => 'cleanup-run-dialog',
            'method'  => 'post',
            'fields'  => array('page' => 'tools', 'action' => 'cleanup_run'),
            'title'   => __('Run cleanup now?'),
            'text'    => __("This permanently deletes the matching listings, users and profile pictures for every enabled rule. It runs in the background. This can't be undone."),
            'confirm' => __('Delete matching items'),
        )); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
