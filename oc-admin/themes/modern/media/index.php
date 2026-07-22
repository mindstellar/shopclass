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
 * Search needles that identify a media file inside page content. Covers both the
 * relative storage key ("<path><id>", e.g. .../library/0/1005) and the file's full
 * public URL stem, so a CDN/offloaded URL with a rewritten host still matches. The
 * stem has its extension stripped so it matches any variant (_thumbnail/_preview).
 *
 * @param array $row A normalised media library row.
 *
 * @return string[]
 */
function mediaFileNeedles(array $row)
{
    $needles = array();

    $key = trim((string) ($row['s_path'] ?? '')) . (int) $row['id'];
    if ($key !== '' && $key !== (string) (int) $row['id']) {
        $needles[] = $key;
    }

    $full = (string) osc_get_resource_url(array(
        'pk_i_id'        => $row['id'],
        's_path'         => $row['s_path'] ?? '',
        's_extension'    => $row['s_extension'] ?? '',
        's_storage'      => $row['s_storage'] ?? 'local',
        's_content_type' => $row['s_content_type'] ?? '',
        's_owner_type'   => $row['owner_type'] ?? '',
        'i_owner_id'     => $row['owner_id'] ?? 0,
    ));
    if ($full !== '') {
        $needles[] = preg_replace('/\.[a-z0-9]{1,5}(\?.*)?$/i', '', $full);
    }

    return array_values(array_unique(array_filter($needles)));
}

/**
 * Resolve where every media row is used in a fixed number of queries (no N+1):
 *  - explicit owners (listing/user/page) are batched into one title lookup per type;
 *  - library uploads are matched against every page's content, which is loaded once.
 *
 * @param array $rows Normalised media rows.
 *
 * @return array<string,array|null> Keyed by "<src>:<id>": usage array or null (unattached).
 */
function mediaResolveUsageBatch(array $rows)
{
    $p       = DB_TABLE_PREFIX;
    $labels  = array('item' => __('Listing'), 'user' => __('User'), 'page' => __('Page'));
    $itemIds = $userIds = $pageIds = array();
    $scan    = array();
    $result  = array();

    foreach ($rows as $row) {
        $rid = $row['src'] . ':' . (int) $row['id'];
        $ot  = (string) ($row['owner_type'] ?? '');
        $oid = (int) ($row['owner_id'] ?? 0);
        if ($oid > 0 && isset($labels[$ot])) {
            $result[$rid] = array('type' => $ot, 'id' => $oid, 'name' => '');
            ${$ot . 'Ids'}[$oid] = true;
        } else {
            $result[$rid] = null;
            $needles = mediaFileNeedles($row);
            if (!empty($needles)) {
                $scan[$rid] = $needles;
            }
        }
    }

    // Library uploads: load every page once and match in PHP.
    if (!empty($scan)) {
        $pages = osc_db_select(
            "SELECT fk_i_pages_id AS id, s_title AS title, s_text AS text FROM {$p}t_pages_description"
        );
        foreach ($scan as $rid => $needles) {
            foreach ($pages as $pg) {
                $text = (string) $pg['text'];
                foreach ($needles as $n) {
                    if (strpos($text, $n) !== false) {
                        $result[$rid] = array('type' => 'page', 'id' => (int) $pg['id'], 'name' => (string) $pg['title']);
                        break 2;
                    }
                }
            }
        }
    }

    // One title lookup per owner type for the explicit owners.
    $itemTitles = mediaBatchTitles("{$p}t_item_description", 'fk_i_item_id', 's_title', array_keys($itemIds));
    $userTitles = mediaBatchTitles("{$p}t_user", 'pk_i_id', "COALESCE(NULLIF(s_name, ''), s_email)", array_keys($userIds));
    $pageTitles = mediaBatchTitles("{$p}t_pages_description", 'fk_i_pages_id', 's_title', array_keys($pageIds));

    foreach ($result as $rid => $u) {
        if ($u === null) {
            continue;
        }
        $id   = (int) $u['id'];
        $type = $u['type'];
        $name = $u['name'];
        if ($name === '') {
            $name = ($type === 'item' ? ($itemTitles[$id] ?? '')
                : ($type === 'user' ? ($userTitles[$id] ?? '') : ($pageTitles[$id] ?? '')));
        }
        $result[$rid] = array(
            'type'  => $type,
            'label' => $labels[$type],
            'id'    => $id,
            'url'   => mediaOwnerUrl($type, $id),
            'name'  => $name,
        );
    }

    return $result;
}

/**
 * id => display value for a set of ids, in one query. Description tables carry a row
 * per locale, so the first non-empty value per id wins.
 *
 * @param string $table
 * @param string $keyCol
 * @param string $valExpr
 * @param int[]  $ids
 *
 * @return array<int,string>
 */
function mediaBatchTitles($table, $keyCol, $valExpr, array $ids)
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (empty($ids)) {
        return array();
    }
    $rows = osc_db_select(
        "SELECT {$keyCol} AS k, {$valExpr} AS v FROM {$table} WHERE {$keyCol} IN (" . implode(',', $ids) . ')'
    );
    $out = array();
    foreach ($rows as $r) {
        $k = (int) $r['k'];
        if (!isset($out[$k]) && (string) $r['v'] !== '') {
            $out[$k] = (string) $r['v'];
        }
    }

    return $out;
}

/**
 * The image's pixel dimensions ("WxH") for a local file, or '' when offloaded or
 * unreadable. Only the current page's rows are checked, so this is bounded.
 *
 * @param array $row
 *
 * @return string
 */
function mediaImageSize(array $row)
{
    $storage = (string) ($row['s_storage'] ?? 'local');
    if ($storage !== '' && $storage !== 'local') {
        return '';
    }
    $path = osc_base_path() . (string) ($row['s_path'] ?? '') . (int) $row['id'] . '.' . (string) ($row['s_extension'] ?? '');
    if (!is_file($path)) {
        return '';
    }
    $info = @getimagesize($path);

    return $info !== false ? ($info[0] . '×' . $info[1]) : '';
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
    <?php } else {
        // Resolve where every row is used up front, in a fixed number of queries.
        $usageMap = mediaResolveUsageBatch($mediaRows); ?>
        <div id="media-table" class="table-contains-actions">
            <table class="table" cellpadding="0" cellspacing="0">
                <thead>
                    <tr>
                        <th class="media-col-preview"><?php _e('Preview'); ?></th>
                        <th><?php _e('Name'); ?></th>
                        <th><?php _e('Used in'); ?></th>
                        <th><?php _e('Type'); ?></th>
                        <th><?php _e('Size'); ?></th>
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
                    $usage      = $usageMap[$row['src'] . ':' . (int) $row['id']] ?? null;
                    $usageName  = $usage !== null
                        ? ($usage['name'] !== '' ? $usage['name'] : '#' . (int) $usage['id'])
                        : '';
                    $usageText  = $usage !== null ? $usage['label'] . ' — ' . $usageName : __('Not attached');
                    $typeLabel  = strtoupper((string) $row['s_extension']);
                    $imageSize  = mediaImageSize($row);
                    $uploaded   = !empty($row['dt']) ? date('Y-m-d', strtotime((string) $row['dt'])) : '—';
                    $deleteUrl  = osc_admin_base_url(true) . '?page=media&action=delete&src=' . urlencode($row['src'])
                        . '&id=' . (int) $row['id'] . '&type=' . urlencode($mediaType) . '&' . osc_csrf_token_url();
                    ?>
                    <tr>
                        <td class="media-col-preview" data-col-name="<?php echo osc_esc_html(__('Preview')); ?>">
                            <a class="media-thumb" href="<?php echo osc_esc_html($full); ?>" target="_blank" rel="noopener"
                               data-media-preview
                               data-full="<?php echo osc_esc_html($full); ?>"
                               data-name="<?php echo osc_esc_html((string) $row['s_name']); ?>"
                               data-usage="<?php echo osc_esc_html($usageText); ?>"
                               title="<?php echo osc_esc_html(__('Preview')); ?>">
                                <img src="<?php echo osc_esc_html($thumb); ?>" loading="lazy"
                                     alt="<?php echo osc_esc_html((string) $row['s_name']); ?>"/>
                            </a>
                        </td>
                        <td class="media-col-name" data-col-name="<?php echo osc_esc_html(__('Name')); ?>">
                            <?php echo osc_esc_html((string) $row['s_name']); ?>
                        </td>
                        <td data-col-name="<?php echo osc_esc_html(__('Used in')); ?>">
                            <?php if ($usage !== null) { ?>
                                <span class="media-owner-tag media-owner-<?php echo osc_esc_html($usage['type']); ?>">
                                    <?php echo osc_esc_html($usage['label']); ?>
                                </span>
                                <?php if ($usage['url'] !== '') { ?>
                                    <a class="media-owner-link" href="<?php echo osc_esc_html($usage['url']); ?>">
                                        <?php echo osc_esc_html($usageName); ?>
                                    </a>
                                <?php } else { ?>
                                    <?php echo osc_esc_html($usageName); ?>
                                <?php } ?>
                            <?php } else { ?>
                                <span class="media-owner-unattached"><?php _e('Not attached'); ?></span>
                            <?php } ?>
                        </td>
                        <td data-col-name="<?php echo osc_esc_html(__('Type')); ?>"><?php echo osc_esc_html($typeLabel); ?></td>
                        <td data-col-name="<?php echo osc_esc_html(__('Size')); ?>"><?php echo $imageSize !== '' ? osc_esc_html($imageSize) : '—'; ?></td>
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
<dialog id="media-preview" class="osc-dialog osc-dialog-wide media-preview-dialog">
    <div class="osc-dialog-body">
        <p class="osc-dialog-title" id="media-preview-name"></p>
        <div class="media-preview-figure">
            <img id="media-preview-img" src="" alt=""/>
        </div>
        <p class="osc-dialog-text" id="media-preview-usage"></p>
    </div>
    <div class="osc-dialog-actions">
        <a id="media-preview-open" class="btn btn-secondary btn-sm" href="#" target="_blank" rel="noopener">
            <?php _e('View original'); ?>
        </a>
        <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Close'); ?></button>
    </div>
</dialog>
<script>
    // Preview modal: clicking a thumbnail opens the full image in a dialog
    // instead of navigating away. The href stays as a no-JS fallback.
    (function () {
        var dialog = document.getElementById('media-preview');
        if (!dialog || typeof dialog.showModal !== 'function') { return; }
        var img   = document.getElementById('media-preview-img');
        var name  = document.getElementById('media-preview-name');
        var usage = document.getElementById('media-preview-usage');
        var open  = document.getElementById('media-preview-open');
        document.querySelectorAll('[data-media-preview]').forEach(function (trigger) {
            trigger.addEventListener('click', function (e) {
                e.preventDefault();
                img.src = trigger.getAttribute('data-full') || '';
                img.alt = trigger.getAttribute('data-name') || '';
                name.textContent = trigger.getAttribute('data-name') || '';
                usage.textContent = trigger.getAttribute('data-usage') || '';
                open.href = trigger.getAttribute('data-full') || '#';
                dialog.showModal();
            });
        });
    })();

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
