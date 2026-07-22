<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

function addHelp()
{
    echo '<p>'
         . __('Every image uploaded across your site — listing photos, user avatars and page images — in one '
              . 'place. Filter by type, and delete a file without removing the listing, user or page it belongs to.')
         . '</p>';
}

osc_add_hook('help_box', 'addHelp');

function customPageHeader()
{
    ?>
    <h1><?php _e('Media'); ?>
        <a href="<?php echo osc_admin_base_url(true) . '?page=settings&action=media'; ?>"
           class="ms-1 float-end" title="<?php echo osc_esc_html(__('Settings')); ?>"><i class="bi bi-gear-fill"></i></a>
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
    return sprintf(__('Media &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

osc_add_filter('render-wrapper', static function () {
    return 'row-offset';
});

/**
 * Admin URL of the owner a media file belongs to, or '' when it has none.
 *
 * @param string $ownerType
 * @param int    $ownerId
 *
 * @return string
 */
function mediaOwnerUrl($ownerType, $ownerId)
{
    switch ($ownerType) {
        case 'item':
            return osc_admin_base_url(true) . '?page=items&action=edit&id=' . (int) $ownerId;
        case 'user':
            return osc_admin_base_url(true) . '?page=users&action=edit&id=' . (int) $ownerId;
        case 'page':
            return osc_admin_base_url(true) . '?page=pages&action=edit&id=' . (int) $ownerId;
        default:
            return '';
    }
}

/**
 * Human name of the owner a media file belongs to (listing/page title, user
 * name), or '' when it can't be resolved or the file isn't attached to one.
 *
 * @param string $ownerType
 * @param int    $ownerId
 *
 * @return string
 */
function mediaOwnerName($ownerType, $ownerId)
{
    $ownerId = (int) $ownerId;
    if ($ownerId <= 0) {
        return '';
    }
    $p = DB_TABLE_PREFIX;
    switch ($ownerType) {
        case 'item':
            return (string) osc_db_scalar(
                "SELECT s_title FROM {$p}t_item_description WHERE fk_i_item_id = ? AND s_title <> '' LIMIT 1",
                array($ownerId)
            );
        case 'user':
            return (string) osc_db_scalar(
                "SELECT COALESCE(NULLIF(s_name, ''), s_email) FROM {$p}t_user WHERE pk_i_id = ?",
                array($ownerId)
            );
        case 'page':
            return (string) osc_db_scalar(
                "SELECT s_title FROM {$p}t_page_description WHERE fk_i_page_id = ? AND s_title <> '' LIMIT 1",
                array($ownerId)
            );
        default:
            return '';
    }
}

$mediaType    = __get('mediaType');
$mediaFilters = __get('mediaFilters');
$mediaRows    = __get('mediaRows');
$mediaTotal   = (int) __get('mediaTotal');
$mediaPerPage = (int) __get('mediaPerPage');
$mediaPage    = (int) __get('mediaPage');
$mediaMaxPage = max(1, (int) ceil($mediaTotal / max(1, $mediaPerPage)));

$ownerLabels = array('item' => __('Listing'), 'user' => __('User'), 'page' => __('Page'));
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
<div id="media-library" class="col-12">
    <div class="media-library-head">
        <h2 class="render-title"><?php _e('Media library'); ?></h2>
        <label class="btn btn-submit btn-sm media-upload-btn">
            <i class="bi bi-upload" aria-hidden="true"></i> <?php _e('Upload'); ?>
            <input type="file" accept="image/*" hidden id="media-upload-input"
                   data-url="<?php echo osc_esc_html(osc_admin_base_url(true)
                       . '?page=ajax&action=resource_upload&owner_type=library&owner_id=0&'
                       . osc_csrf_token_url()); ?>"/>
        </label>
    </div>
    <div class="media-filters">
        <?php foreach ($mediaFilters as $filter) {
            $active = ($filter['type'] === $mediaType) ? ' active' : ''; ?>
            <a class="media-filter<?php echo $active; ?>"
               href="<?php echo osc_esc_html(osc_admin_base_url(true) . '?page=media&type='
                   . urlencode($filter['type'])); ?>">
                <?php echo osc_esc_html($filter['label']); ?>
            </a>
        <?php } ?>
    </div>

    <?php if (count($mediaRows) === 0) { ?>
        <div class="media-empty">
            <i class="bi bi-images" aria-hidden="true"></i>
            <p><?php _e('No media here yet.'); ?></p>
        </div>
    <?php } else { ?>
        <div id="media-table" class="table-contains-actions">
            <table class="table" cellpadding="0" cellspacing="0">
                <thead>
                    <tr>
                        <th class="media-col-preview"><?php _e('Preview'); ?></th>
                        <th><?php _e('Name'); ?></th>
                        <th><?php _e('Used in'); ?></th>
                        <th><?php _e('Type'); ?></th>
                        <th><?php _e('Uploaded'); ?></th>
                        <th class="text-end"><?php _e('Actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($mediaRows as $row) {
                    // Normalise to the shape osc_get_resource_url() reads (it applies the
                    // storage-aware URL filters, so offloaded files resolve correctly).
                    $res = array(
                        'pk_i_id'        => $row['id'],
                        's_path'         => $row['s_path'],
                        's_extension'    => $row['s_extension'],
                        's_storage'      => $row['s_storage'],
                        's_content_type' => $row['s_content_type'],
                        's_owner_type'   => $row['owner_type'],
                        'i_owner_id'     => $row['owner_id'],
                    );
                    $thumb      = osc_get_resource_url($res, 'thumbnail');
                    $full       = osc_get_resource_url($res);
                    $ownerType  = (string) $row['owner_type'];
                    $ownerLabel = $ownerLabels[$ownerType] ?? ucfirst($ownerType);
                    $ownerUrl   = mediaOwnerUrl($ownerType, (int) $row['owner_id']);
                    $ownerName  = mediaOwnerName($ownerType, (int) $row['owner_id']);
                    $typeLabel  = strtoupper((string) $row['s_extension']);
                    $uploaded   = !empty($row['dt']) ? date('Y-m-d', strtotime((string) $row['dt'])) : '—';
                    $deleteUrl  = osc_admin_base_url(true) . '?page=media&action=delete&src=' . urlencode($row['src'])
                        . '&id=' . (int) $row['id'] . '&type=' . urlencode($mediaType) . '&' . osc_csrf_token_url();
                    ?>
                    <tr>
                        <td class="media-col-preview" data-col-name="<?php echo osc_esc_html(__('Preview')); ?>">
                            <a class="media-thumb" href="<?php echo osc_esc_html($full); ?>" target="_blank" rel="noopener">
                                <img src="<?php echo osc_esc_html($thumb); ?>" loading="lazy"
                                     alt="<?php echo osc_esc_html((string) $row['s_name']); ?>"/>
                            </a>
                        </td>
                        <td class="media-col-name" data-col-name="<?php echo osc_esc_html(__('Name')); ?>">
                            <?php echo osc_esc_html((string) $row['s_name']); ?>
                        </td>
                        <td data-col-name="<?php echo osc_esc_html(__('Used in')); ?>">
                            <span class="media-owner-tag media-owner-<?php echo osc_esc_html($ownerType); ?>">
                                <?php echo osc_esc_html($ownerLabel); ?>
                            </span>
                            <?php if ($ownerUrl !== '') { ?>
                                <a class="media-owner-link" href="<?php echo osc_esc_html($ownerUrl); ?>">
                                    <?php echo $ownerName !== ''
                                        ? osc_esc_html($ownerName)
                                        : '#' . (int) $row['owner_id']; ?>
                                </a>
                            <?php } else { ?>
                                <span class="media-owner-unattached"><?php _e('Not attached'); ?></span>
                            <?php } ?>
                        </td>
                        <td data-col-name="<?php echo osc_esc_html(__('Type')); ?>"><?php echo osc_esc_html($typeLabel); ?></td>
                        <td data-col-name="<?php echo osc_esc_html(__('Uploaded')); ?>"><?php echo osc_esc_html($uploaded); ?></td>
                        <td class="text-end media-row-actions" data-col-name="<?php echo osc_esc_html(__('Actions')); ?>">
                            <a class="media-action-view" href="<?php echo osc_esc_html($full); ?>" target="_blank"
                               rel="noopener" title="<?php echo osc_esc_html(__('View full image')); ?>"
                               aria-label="<?php echo osc_esc_html(__('View full image')); ?>">
                                <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
                            </a>
                            <a class="media-delete" href="<?php echo osc_esc_html($deleteUrl); ?>"
                               data-confirm="<?php echo osc_esc_html(
                                   __('Delete this media file? The listing, user or page it belongs to is not affected.')
                               ); ?>" title="<?php echo osc_esc_html(__('Delete')); ?>"
                               aria-label="<?php echo osc_esc_html(__('Delete')); ?>">
                                <i class="bi bi-trash" aria-hidden="true"></i>
                            </a>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>

        <?php if ($mediaMaxPage > 1) {
            $pageBase = osc_admin_base_url(true) . '?page=media&type=' . urlencode($mediaType) . '&iPage='; ?>
            <nav class="media-pagination" aria-label="<?php echo osc_esc_html(__('Pagination')); ?>">
                <?php if ($mediaPage > 1) { ?>
                    <a class="btn btn-secondary btn-sm"
                       href="<?php echo osc_esc_html($pageBase . ($mediaPage - 1)); ?>"><?php _e('Previous'); ?></a>
                <?php } ?>
                <span class="media-page-count">
                    <?php printf(__('Page %1$d of %2$d'), $mediaPage, $mediaMaxPage); ?>
                </span>
                <?php if ($mediaPage < $mediaMaxPage) { ?>
                    <a class="btn btn-secondary btn-sm"
                       href="<?php echo osc_esc_html($pageBase . ($mediaPage + 1)); ?>"><?php _e('Next'); ?></a>
                <?php } ?>
            </nav>
        <?php } ?>
    <?php } ?>
</div>
<script>
    document.querySelectorAll('.media-delete').forEach(function (link) {
        link.addEventListener('click', function (e) {
            if (!window.confirm(link.getAttribute('data-confirm') || 'Delete?')) {
                e.preventDefault();
            }
        });
    });

    // Upload straight to the library, then reload to show it under the Library filter.
    var mediaUpload = document.getElementById('media-upload-input');
    if (mediaUpload) {
        mediaUpload.addEventListener('change', function () {
            var file = mediaUpload.files && mediaUpload.files[0];
            if (!file) { return; }
            var data = new FormData();
            data.append('file', file);
            var btn = document.querySelector('.media-upload-btn');
            if (btn) { btn.classList.add('is-busy'); }
            fetch(mediaUpload.getAttribute('data-url'), {
                method: 'POST', credentials: 'same-origin', body: data
            }).then(function (r) { return r.json(); }).then(function (j) {
                if (j && j.location) {
                    window.location.href = '<?php echo osc_esc_js(osc_admin_base_url(true)
                        . '?page=media&type=library'); ?>';
                } else if (btn) {
                    btn.classList.remove('is-busy');
                }
            }).catch(function () { if (btn) { btn.classList.remove('is-busy'); } });
        });
    }
</script>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
