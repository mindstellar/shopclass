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

/**
 * The chrome around the declared storage form. The form is core's; the provider-preset script,
 * the connection test, the queue and the migrations stay here.
 */

$form          = __get('storage_form');
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
                    // readOnly (not disabled): a disabled field is never submitted, so a
                    // locked region (e.g. Cloudflare R2's "auto") would save blank. readOnly
                    // keeps the value fixed in the UI while still POSTing it.
                    regionField.readOnly = !!preset.region_locked;
                }
                if (pathStyleField) {
                    pathStyleField.checked = !!preset.path_style;
                }
                if (publicUrlHint) {
                    publicUrlHint.textContent = preset.public_url_hint;
                }
            });

            // Reflect the saved provider's locked state on load without clobbering the
            // saved connection values (only a provider change rewrites the fields).
            var current = presets[providerSelect.value];
            if (current && regionField) {
                regionField.readOnly = !!current.region_locked;
                if (publicUrlHint && current.public_url_hint) {
                    publicUrlHint.textContent = current.public_url_hint;
                }
            }
        });
    </script>
    <?php
};

osc_add_hook('admin_footer', $storage_js, 10);

osc_admin_page(array(
    'section' => __('Settings'),
    'title'   => __('Storage Settings'),
    'help'    => __('Configure S3-compatible object storage for listing images. When enabled, uploads are moved to your '
                    . 'bucket by the background storage queue; the local disk stays the default until you switch it on.'),
));

osc_current_admin_theme_path('parts/header.php'); ?>
    <div id="storage-settings">
        <?php osc_admin_page_head(__('Storage Settings')); ?>

        <?php if ($betterS3Active) { ?>
            <div class="flashmessage flashmessage-error">
                <?php _e('The <strong>Better S3</strong> plugin is currently active. Disable it before turning on '
                         . 'core S3 storage below, otherwise both will rewrite resource URLs and uploads will break.'); ?>
            </div>
        <?php } ?>

        <?php osc_admin_settings_form($form['id'], $form); ?>

        <?php
        osc_admin_action_section(array(
            'title'   => __('Connection test'),
            'intro'   => __('Runs a small write/read/delete probe against the saved connection settings above.'),
            'actions' => array(
                array(
                    'label'   => __('Test connection'),
                    'page'    => 'settings',
                    'action'  => 'storage_test_post',
                    'name'    => 'storage_test_form',
                    'variant' => 'dim',
                ),
            ),
        ));

        $queueBody = '<p>' . sprintf(
            osc_esc_html(__('Pending jobs: %d &middot; Failed jobs: %d')),
            (int) $queueStats['pending'],
            (int) $queueStats['error']
        ) . '</p>';

        $queueFooter = '';
        if (!empty($queueStats['dead_letters'])) {
            $queueFooter .= '<div class="help-box"><p>'
                . osc_esc_html(__('Dead-lettered jobs (past the retry ceiling):')) . '</p><ul>';
            foreach ($queueStats['dead_letters'] as $job) {
                $queueFooter .= '<li>#' . osc_esc_html($job['pk_i_id']) . ' &mdash; '
                    . osc_esc_html($job['s_type']) . ' (' . osc_esc_html($job['s_last_error']) . ')</li>';
            }
            $queueFooter .= '</ul></div>';
        }

        osc_admin_action_section(array(
            'title'       => __('Storage queue'),
            'body_html'   => $queueBody,
            'actions'     => array(
                array(
                    'label'   => __('Process queue now'),
                    'page'    => 'settings',
                    'action'  => 'storage_queue_run',
                    'name'    => 'storage_queue_form',
                    'variant' => 'dim',
                ),
            ),
            'footer_html' => $queueFooter,
        ));

        $migrationActions = array(
            array(
                'label'   => __('Offload all local images to remote storage'),
                'variant' => 'dim',
                'confirm' => '#storage-offload-dialog',
                'help'    => __('Backfills every image still on local disk to the active remote storage backend. '
                                . 'Existing images are queued for upload; new uploads are already handled automatically.'),
            ),
            array(
                'label'   => __('Download all remote images back to local (offline copy)'),
                'variant' => 'dim',
                'confirm' => '#storage-restore-dialog',
                'help'    => __('Brings every remote image back to local disk and switches it back to local storage. '
                                . 'Use this to keep a local copy, or before disabling remote storage.'),
            ),
        );
        if ($betterS3Configured) {
            $migrationActions[] = array(
                'label'   => __('Adopt existing Better S3 images'),
                'variant' => 'dim',
                'confirm' => '#storage-adopt-dialog',
                'help'    => __('Imports your Better S3 connection settings and marks images already uploaded to that '
                                . 'bucket as remote, without re-uploading them.'),
            );
        }

        osc_admin_action_section(array(
            'title'   => __('Migration'),
            'intro'   => __('Backfill existing images between local disk and remote storage. Each action queues '
                            . 'jobs processed by the storage queue above (or by cron) rather than running immediately.'),
            'actions' => $migrationActions,
        ));
        ?>
    </div>

<?php
osc_admin_confirm_dialog(array(
    'id'      => 'storage-offload-dialog',
    'tone'    => 'plain',
    'fields'  => array('page' => 'settings', 'action' => 'storage_migrate_post', 'op' => 'offload_all'),
    'title'   => __('Queue every local image for upload?'),
    'text'    => __('Each image still on local disk is queued for upload to the active remote backend. '
                    . 'The jobs run through the storage queue rather than immediately, and a local file is '
                    . 'only dropped once its upload has succeeded.'),
    'confirm' => __('Queue uploads'),
));
osc_admin_confirm_dialog(array(
    'id'      => 'storage-restore-dialog',
    'tone'    => 'plain',
    'fields'  => array('page' => 'settings', 'action' => 'storage_migrate_post', 'op' => 'restore_all'),
    'title'   => __('Download every remote image back to local disk?'),
    'text'    => __('Each remote image is queued for download and switched back to local storage. '
                    . 'Check this server has room for them first.'),
    'confirm' => __('Queue downloads'),
));
osc_admin_confirm_dialog(array(
    'id'      => 'storage-adopt-dialog',
    'tone'    => 'plain',
    'fields'  => array('page' => 'settings', 'action' => 'storage_migrate_post', 'op' => 'adopt_better_s3'),
    'title'   => __('Import Better S3 settings?'),
    'text'    => __('Your Better S3 configuration is copied into these settings and images already in that '
                    . 'bucket are adopted as remote. Nothing in the bucket is moved or deleted.'),
    'confirm' => __('Import settings'),
));
?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
