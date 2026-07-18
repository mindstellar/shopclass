<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * Osclass - software for creating and publishing online classified advertising platforms
 * Maintained and supported by Mindstellar Community
 * https://github.com/mindstellar/Osclass
 *
 * This program is free software: you can redistribute it and/or modify it under the terms
 * of the GNU General Public License as published by the Free Software Foundation, either
 * version 3 of the License, or (at your option) any later version. Distributed WITHOUT ANY
 * WARRANTY. See the GNU Affero General Public License for more details.
 */

function addHelp()
{
    echo '<p>' . __('Remove stale content in bulk — expired, unactivated, spam, blocked and '
                    . 'reported listings, and unactivated users. Choose what to clean and how old '
                    . 'it must be, then run it now or let the daily task handle it.') . '</p>';
}
osc_add_hook('help_box', 'addHelp');

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Tools'); ?>
        <a class="ms-1 bi bi-question-circle-fill float-right" data-bs-target="#help-box" data-bs-toggle="collapse"
           href="#help-box"></a>
    </h1>
    <?php
}

function customPageTitle($string)
{
    return sprintf(__('Cleanup &raquo; %s'), $string);
}
osc_add_filter('admin_title', 'customPageTitle');

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
$batch_limit = (int)osc_get_preference('batch_limit', 'cleanup');
if ($batch_limit < 1) {
    $batch_limit = 250;
}

osc_current_admin_theme_path('parts/header.php'); ?>
    <h2 class="render-title"><?php _e('Cleanup'); ?></h2>

    <form method="post" action="<?php echo osc_admin_base_url(true); ?>">
        <input type="hidden" name="page" value="tools"/>
        <input type="hidden" name="action" value="cleanup_post"/>
        <?php osc_csrf_token_form(); ?>
        <div class="widget-box">
            <div class="widget-box-content">
                <table class="table">
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
                        $enabled = osc_get_preference('enabled_' . $rule, 'cleanup') == 1;
                        $days    = (int)osc_get_preference('days_' . $rule, 'cleanup');
                        if ($days < 1) {
                            $days = 30;
                        }
                        $matching = $engine->countFor($rule, $meta['days'] ? $days : 0); ?>
                        <tr>
                            <td>
                                <input class="form-check-input" type="checkbox" id="enabled_<?php echo $rule; ?>"
                                       name="enabled_<?php echo $rule; ?>" value="1" <?php echo $enabled ? 'checked' : ''; ?>>
                            </td>
                            <td>
                                <label for="enabled_<?php echo $rule; ?>"><strong><?php echo osc_esc_html($meta['label']); ?></strong></label>
                                <div class="text-muted"><?php echo osc_esc_html($meta['desc']); ?></div>
                            </td>
                            <td>
                                <?php if ($meta['days']) { ?>
                                    <div class="input-group input-group-sm" style="max-width:11rem">
                                        <input type="number" min="1" class="form-control" name="days_<?php echo $rule; ?>"
                                               value="<?php echo $days; ?>">
                                        <span class="input-group-text"><?php _e('days'); ?></span>
                                    </div>
                                <?php } else { ?>
                                    <span class="text-muted">&mdash;</span>
                                <?php } ?>
                            </td>
                            <td class="text-end"><?php echo number_format($matching); ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>

                <div class="form-row mt-3" style="max-width:20rem">
                    <label for="batch_limit"><?php _e('Maximum items removed per run'); ?></label>
                    <input type="number" min="1" class="form-control form-control-sm" id="batch_limit"
                           name="batch_limit" value="<?php echo $batch_limit; ?>">
                    <div class="help-block"><?php _e('Keeps each run bounded so it never times out; run again to clear a larger backlog.'); ?></div>
                </div>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-submit"><?php echo osc_esc_html(__('Save settings')); ?></button>
            <button type="button" class="btn btn-danger" data-osc-dialog-open="#cleanup-run-dialog"><?php _e('Run cleanup now'); ?></button>
        </div>
    </form>

    <p class="text-muted mt-2">
        <i class="bi bi-clock-history"></i>
        <?php _e('Enabled rules also run automatically once a day.'); ?>
    </p>

    <dialog id="cleanup-run-dialog" class="osc-dialog osc-dialog-danger">
        <form method="post" action="<?php echo osc_admin_base_url(true); ?>">
            <input type="hidden" name="page" value="tools"/>
            <input type="hidden" name="action" value="cleanup_run"/>
            <?php osc_csrf_token_form(); ?>
            <div class="osc-dialog-body">
                <p class="osc-dialog-title"><i class="bi bi-exclamation-triangle-fill"></i> <?php _e('Run cleanup now?'); ?></p>
                <p class="osc-dialog-text"><?php _e("This permanently deletes the matching listings and users for every enabled rule (up to the per-run limit). This can't be undone."); ?></p>
            </div>
            <div class="osc-dialog-actions">
                <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
                <button type="submit" class="btn btn-danger btn-sm"><?php _e('Delete matching items'); ?></button>
            </div>
        </form>
    </dialog>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
