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

use mindstellar\storage\ProviderPresets;

$prefs         = __get('prefs');
$providers     = __get('provider_presets');
$queueStats    = __get('queue_stats');
$betterS3Active = __get('better_s3_active');
$betterS3Configured = __get('better_s3_configured');

//customize Head
$storage_js = static function () use ($providers) {
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            var presets = <?php echo ProviderPresets::toJson(); ?>;
            var providerSelect = document.getElementById('storage_provider');
            var endpointField = document.querySelector('[name="storage_s3_endpoint"]');
            var regionField = document.querySelector('[name="storage_s3_region"]');
            var pathStyleField = document.querySelector('[name="storage_s3_path_style"]');
            var publicUrlHint = document.getElementById('storage_public_url_hint');

            if (!providerSelect) {
                return;
            }

            providerSelect.addEventListener('change', function () {
                var preset = presets[providerSelect.value];
                if (!preset) {
                    return;
                }

                if (endpointField) {
                    endpointField.value = preset.endpoint;
                }
                if (regionField) {
                    regionField.value = preset.region;
                    regionField.disabled = !!preset.region_locked;
                }
                if (pathStyleField) {
                    pathStyleField.checked = !!preset.path_style;
                }
                if (publicUrlHint) {
                    publicUrlHint.textContent = preset.public_url_hint;
                }
            });
        });
    </script>
    <?php
};

osc_add_hook('admin_footer', $storage_js, 10);

function addHelp()
{
    echo '<p>'
         . __('Configure S3-compatible object storage for listing images. When enabled, uploads are moved to your '
              . 'bucket by the background storage queue; the local disk stays the default until you switch it on.')
         . '</p>';
}

osc_add_hook('help_box', 'addHelp');

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Settings'); ?>
        <a class="ms-1 bi bi-question-circle float-end" data-bs-target="#help-box" data-bs-toggle="collapse"
           href="#help-box"></a>
    </h1>
    <?php
}

/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Storage Settings &raquo; %s'), $string);
}

osc_add_filter('admin_title', 'customPageTitle');

osc_current_admin_theme_path('parts/header.php'); ?>
    <div id="storage-settings">
        <h2 class="render-title"><?php _e('Storage Settings'); ?></h2>

        <?php if ($betterS3Active) { ?>
            <div class="flashmessage flashmessage-error">
                <?php _e('The <strong>Better S3</strong> plugin is currently active. Disable it before turning on '
                         . 'core S3 storage below, otherwise both will rewrite resource URLs and uploads will break.'); ?>
            </div>
        <?php } ?>

        <form name="storage_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
            <input type="hidden" name="page" value="settings"/>
            <input type="hidden" name="action" value="storage_post"/>
            <fieldset>
                <div class="form-horizontal">
                    <div class="form-row">
                        <div class="form-label"><?php _e('Active storage'); ?></div>
                        <div class="form-controls">
                            <select class="form-select form-select-sm" name="storage_active">
                                <option value="local" <?php echo ($prefs['storage_active'] !== 's3')
                                    ? 'selected="true"' : ''; ?>><?php _e('Local disk'); ?></option>
                                <option value="s3" <?php echo ($prefs['storage_active'] === 's3')
                                    ? 'selected="true"' : ''; ?>><?php _e('Amazon S3-compatible'); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Provider'); ?></div>
                        <div class="form-controls">
                            <select class="form-select form-select-sm" id="storage_provider">
                                <?php foreach ($providers as $id => $preset) { ?>
                                    <option value="<?php echo osc_esc_html($id); ?>">
                                        <?php echo osc_esc_html($preset['label']); ?>
                                    </option>
                                <?php } ?>
                            </select>
                            <div class="help-box">
                                <?php _e('Prefills the connection fields below with a starting point for the selected provider. '
                                         . 'Review every field before saving.'); ?>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Bucket'); ?></div>
                        <div class="form-controls">
                            <input type="text" class="input-large" name="storage_s3_bucket"
                                   value="<?php echo osc_esc_html($prefs['storage_s3_bucket']); ?>"/>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Region'); ?></div>
                        <div class="form-controls">
                            <input type="text" class="input-medium" name="storage_s3_region"
                                   value="<?php echo osc_esc_html($prefs['storage_s3_region']); ?>"/>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Endpoint'); ?></div>
                        <div class="form-controls">
                            <input type="text" class="input-large" name="storage_s3_endpoint"
                                   value="<?php echo osc_esc_html($prefs['storage_s3_endpoint']); ?>"/>
                            <div class="help-box">
                                <?php _e('Leave the provider-specific placeholders (e.g. {region}, {account_id}) filled in '
                                         . 'with your own values.'); ?>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Access key'); ?></div>
                        <div class="form-controls">
                            <input type="text" class="input-large" name="storage_s3_access_key"
                                   value="<?php echo osc_esc_html($prefs['storage_s3_access_key']); ?>"/>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Secret key'); ?></div>
                        <div class="form-controls">
                            <input type="password" class="input-large" name="storage_s3_secret_key" value=""
                                   placeholder="<?php echo osc_esc_html(__('Leave blank to keep the currently saved secret key')); ?>"
                                   autocomplete="new-password"/>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Path-style URLs'); ?></div>
                        <div class="form-controls">
                            <div class="form-label-checkbox">
                                <input type="checkbox" id="storage_s3_path_style" name="storage_s3_path_style"
                                       value="1" <?php echo($prefs['storage_s3_path_style'] ? 'checked="checked"' : ''); ?> />
                                <label for="storage_s3_path_style"><?php _e('Use path-style bucket URLs.'); ?></label>
                                <span class="help-box"><?php _e('Required by MinIO and most self-hosted setups; leave off for AWS.'); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Public URL'); ?></div>
                        <div class="form-controls">
                            <input type="text" class="input-large" name="storage_s3_public_url"
                                   value="<?php echo osc_esc_html($prefs['storage_s3_public_url']); ?>"/>
                            <div class="help-box" id="storage_public_url_hint">
                                <?php _e('Optional. Overrides the URL used to serve files, e.g. a CDN domain in front of the bucket.'); ?>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Signed URLs'); ?></div>
                        <div class="form-controls">
                            <div class="form-label-checkbox">
                                <input type="checkbox" id="storage_s3_signed_urls" name="storage_s3_signed_urls"
                                       value="1" <?php echo($prefs['storage_s3_signed_urls'] ? 'checked="checked"' : ''); ?> />
                                <label for="storage_s3_signed_urls"><?php _e('Serve files through time-limited signed URLs.'); ?></label>
                                <span class="help-box"><?php _e('Use this for a private bucket. Leave off for a public bucket or CDN.'); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Signed URL TTL'); ?></div>
                        <div class="form-controls">
                            <input type="number" class="input-medium" name="storage_s3_signed_ttl" min="60" max="604800"
                                   value="<?php echo osc_esc_html($prefs['storage_s3_signed_ttl']); ?>"/>
                            <span class="help-box"><?php _e('Seconds a signed URL stays valid (60-604800).'); ?></span>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-label"><?php _e('Local copies'); ?></div>
                        <div class="form-controls">
                            <select class="form-select form-select-sm" name="storage_keep_local">
                                <option value="all" <?php echo ($prefs['storage_keep_local'] !== 'none')
                                    ? 'selected="true"' : ''; ?>><?php _e('Keep local copies'); ?></option>
                                <option value="none" <?php echo ($prefs['storage_keep_local'] === 'none')
                                    ? 'selected="true"' : ''; ?>><?php _e('Delete after upload'); ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="clear"></div>
                    <div class="form-actions">
                        <input type="submit" id="save_changes" value="<?php echo osc_esc_html(__('Save changes')); ?>"
                               class="btn btn-submit"/>
                    </div>
                </div>
            </fieldset>
        </form>

        <div class="form-horizontal">
            <h2 class="render-title"><?php _e('Connection test'); ?></h2>
            <div class="form-row">
                <div class="form-controls">
                    <p><?php _e('Runs a small write/read/delete probe against the saved connection settings above.'); ?></p>
                    <form name="storage_test_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
                        <input type="hidden" name="page" value="settings"/>
                        <input type="hidden" name="action" value="storage_test_post"/>
                        <input type="submit" value="<?php echo osc_esc_html(__('Test connection')); ?>" class="btn btn-dim"/>
                    </form>
                </div>
            </div>

            <h2 class="render-title"><?php _e('Storage queue'); ?></h2>
            <div class="form-row">
                <div class="form-controls">
                    <p>
                        <?php echo sprintf(
                            osc_esc_html(__('Pending jobs: %d &middot; Failed jobs: %d')),
                            (int) $queueStats['pending'],
                            (int) $queueStats['error']
                        ); ?>
                    </p>
                    <form name="storage_queue_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
                        <input type="hidden" name="page" value="settings"/>
                        <input type="hidden" name="action" value="storage_queue_run"/>
                        <input type="submit" value="<?php echo osc_esc_html(__('Process queue now')); ?>" class="btn btn-dim"/>
                    </form>
                    <?php if (!empty($queueStats['dead_letters'])) { ?>
                        <div class="help-box">
                            <p><?php _e('Dead-lettered jobs (past the retry ceiling):'); ?></p>
                            <ul>
                                <?php foreach ($queueStats['dead_letters'] as $job) { ?>
                                    <li>
                                        #<?php echo osc_esc_html($job['pk_i_id']); ?>
                                        &mdash; <?php echo osc_esc_html($job['s_type']); ?>
                                        (<?php echo osc_esc_html($job['s_last_error']); ?>)
                                    </li>
                                <?php } ?>
                            </ul>
                        </div>
                    <?php } ?>
                </div>
            </div>

            <h2 class="render-title"><?php _e('Migration'); ?></h2>
            <div class="form-row">
                <div class="form-controls">
                    <p><?php _e('Backfill existing images between local disk and remote storage. Each action queues '
                                 . 'jobs processed by the storage queue above (or by cron) rather than running immediately.'); ?></p>

                    <form name="storage_offload_all_form" action="<?php echo osc_admin_base_url(true); ?>" method="post"
                          onsubmit="return confirm('<?php echo osc_esc_js(__('Queue every local image for upload to remote storage?')); ?>');">
                        <input type="hidden" name="page" value="settings"/>
                        <input type="hidden" name="action" value="storage_migrate_post"/>
                        <input type="hidden" name="op" value="offload_all"/>
                        <input type="submit"
                               value="<?php echo osc_esc_html(__('Offload all local images to remote storage')); ?>"
                               class="btn btn-dim"/>
                    </form>
                    <div class="help-box">
                        <?php _e('Backfills every image still on local disk to the active remote storage backend. '
                                 . 'Existing images are queued for upload; new uploads are already handled automatically.'); ?>
                    </div>

                    <form name="storage_restore_all_form" action="<?php echo osc_admin_base_url(true); ?>" method="post"
                          onsubmit="return confirm('<?php echo osc_esc_js(__('Download every remote image back to local disk?')); ?>');">
                        <input type="hidden" name="page" value="settings"/>
                        <input type="hidden" name="action" value="storage_migrate_post"/>
                        <input type="hidden" name="op" value="restore_all"/>
                        <input type="submit"
                               value="<?php echo osc_esc_html(__('Download all remote images back to local (offline copy)')); ?>"
                               class="btn btn-dim"/>
                    </form>
                    <div class="help-box">
                        <?php _e('Brings every remote image back to local disk and switches it back to local storage. '
                                 . 'Use this to keep a local copy, or before disabling remote storage.'); ?>
                    </div>

                    <?php if ($betterS3Configured) { ?>
                        <form name="storage_adopt_better_s3_form" action="<?php echo osc_admin_base_url(true); ?>" method="post"
                              onsubmit="return confirm('<?php echo osc_esc_js(__('Import your Better S3 settings and adopt images already in that bucket?')); ?>');">
                            <input type="hidden" name="page" value="settings"/>
                            <input type="hidden" name="action" value="storage_migrate_post"/>
                            <input type="hidden" name="op" value="adopt_better_s3"/>
                            <input type="submit"
                                   value="<?php echo osc_esc_html(__('Adopt existing Better S3 images')); ?>"
                                   class="btn btn-dim"/>
                        </form>
                        <div class="help-box">
                            <?php _e('Imports your Better S3 connection settings and marks images already uploaded to that '
                                     . 'bucket as remote, without re-uploading them.'); ?>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>
    </div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
