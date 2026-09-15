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

/*
 * What importing one catalog country would change, from a dry run that was rolled back.
 * Shown in the page after a plain POST, or in the dialog when the script asked for it.
 */
$preview = __get('locationPreview');
if (!is_array($preview)) {
    return;
}

$dataUrl   = osc_admin_base_url(true) . '?page=settings&action=locations&tab=data';
$name      = (string) $preview['name'];
$installed = !empty($preview['installed']);
$levels    = $preview['levels'];

$nouns = array(
    'regions' => static fn (int $n): string => sprintf(_n('%s region', '%s regions', $n), number_format($n)),
    'cities'  => static fn (int $n): string => sprintf(_n('%s city', '%s cities', $n), number_format($n)),
);
$columns = array(
    'inserted' => __('Added'),
    'updated'  => __('Changed'),
    'renamed'  => __('New address'),
    'hidden'   => __('Hidden'),
);
$submit = $installed ? sprintf(__('Update %s'), $name) : sprintf(__('Install %s'), $name);

osc_admin_form_open(array(
    'page'       => 'settings',
    'action'     => 'locations',
    'fields'     => array('type' => 'locations_import', 'location' => $preview['code'], 'tab' => 'data'),
    'class'      => 'loc-form loc-preview',
    'horizontal' => false,
    'csrf'       => false,
));
// The token is written here because this form also travels inside a JSON answer.
echo osc_csrf_token_form(); ?>
    <div class="osc-dialog-body">
        <h2 class="osc-dialog-title" tabindex="-1"><?php echo osc_esc_html(sprintf(__('What updating %s would change'), $name)); ?></h2>
        <p class="loc-form-error" role="alert" hidden></p>
        <p class="osc-dialog-text">
            <?php _e('Nothing has been changed yet. This compares the catalog with the locations you have now.'); ?>
        </p>

        <?php if (!$preview['changes']) { ?>
            <p class="loc-form-notice loc-preview-same">
                <?php echo osc_esc_html(sprintf(__('%s already matches the catalog. Updating only records that this site holds the latest release.'), $name)); ?>
            </p>
        <?php } else { ?>
            <div class="loc-table-wrap">
                <table class="table loc-preview-table">
                    <caption class="visually-hidden"><?php _e('Changes by level'); ?></caption>
                    <thead>
                    <tr>
                        <th scope="col"><span class="visually-hidden"><?php _e('Level'); ?></span></th>
                        <?php foreach ($columns as $label) { ?>
                            <th scope="col" class="col-numeric"><?php echo osc_esc_html($label); ?></th>
                        <?php } ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array('regions' => __('Regions'), 'cities' => __('Cities')) as $level => $label) { ?>
                        <tr>
                            <th scope="row"><?php echo osc_esc_html($label); ?></th>
                            <?php foreach (array_keys($columns) as $key) {
                                $n = (int) $levels[$level][$key]; ?>
                                <td class="col-numeric<?php echo $n === 0 ? ' loc-preview-zero' : ''; ?>"><?php echo number_format($n); ?></td>
                            <?php } ?>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <ul class="loc-preview-notes">
                <li>
                    <?php echo osc_esc_html(sprintf(
                        __('%1$s and %2$s stay as they are.'),
                        $nouns['regions']($levels['regions']['unchanged']),
                        $nouns['cities']($levels['cities']['unchanged'])
                    )); ?>
                </li>
                <?php if ($preview['countryRenamed']) { ?>
                    <li><?php echo osc_esc_html(sprintf(__('The name or address of %s itself changes.'), $name)); ?></li>
                <?php } ?>
                <?php if ($levels['regions']['hidden'] + $levels['cities']['hidden'] > 0) { ?>
                    <li><?php _e('Hidden locations are no longer in the catalog and hold no listings. They stay in the database.'); ?></li>
                <?php } ?>
                <?php if ($preview['kept'] > 0) {
                    $kept = array_values(array_filter(array(
                        $levels['regions']['kept'] > 0 ? $nouns['regions']($levels['regions']['kept']) : null,
                        $levels['cities']['kept'] > 0 ? $nouns['cities']($levels['cities']['kept']) : null,
                    ))); ?>
                    <li>
                        <?php echo osc_esc_html(count($kept) === 2
                            ? sprintf(__('Kept visible though the catalog dropped them, because they hold listings: %1$s and %2$s.'), $kept[0], $kept[1])
                            : sprintf(__('Kept visible though the catalog dropped it, because it holds listings: %s.'), $kept[0])); ?>
                    </li>
                <?php } ?>
                <?php if ($preview['fellBack']) { ?>
                    <li><?php _e('The streamed copy of this country could not be verified, so the full file was read instead.'); ?></li>
                <?php } ?>
            </ul>

            <?php if ($preview['renames'] !== array()) { ?>
                <section class="loc-renames" aria-labelledby="loc-renames-title">
                    <h3 class="loc-renames-title" id="loc-renames-title"><?php _e('New addresses'); ?></h3>
                    <p class="loc-renames-text"><?php _e('Links to the old address keep working.'); ?></p>
                    <ol class="loc-renames-list">
                        <?php foreach ($preview['renames'] as $rename) { ?>
                            <li>
                                <span class="loc-renames-name">
                                    <?php if ($rename['name'] !== null) {
                                        echo osc_esc_html($rename['name']);
                                    } ?>
                                    <span class="loc-renames-level"><?php echo osc_esc_html($rename['level'] === 'region' ? __('Region') : __('City')); ?></span>
                                </span>
                                <span class="loc-renames-slugs osc-mono">
                                    <span><?php echo osc_esc_html($rename['from']); ?></span>
                                    <i class="bi bi-arrow-right" aria-hidden="true"></i>
                                    <span class="visually-hidden"><?php _e('becomes'); ?></span>
                                    <span><?php echo osc_esc_html($rename['to']); ?></span>
                                </span>
                            </li>
                        <?php } ?>
                    </ol>
                    <?php if ($preview['renamesMore'] > 0) { ?>
                        <p class="loc-renames-text">
                            <?php echo osc_esc_html(sprintf(
                                _n('And %s more.', 'And %s more.', $preview['renamesMore']),
                                number_format($preview['renamesMore'])
                            )); ?>
                        </p>
                    <?php } ?>
                </section>
            <?php } ?>
        <?php } ?>
    </div>
    <div class="osc-dialog-actions">
        <a class="btn btn-secondary btn-sm" href="<?php echo osc_esc_html($dataUrl); ?>" data-loc-cancel><?php _e('Close'); ?></a>
        <button type="submit" class="btn btn-submit btn-sm"
                data-loc-busy="<?php echo osc_esc_html($installed ? __('Updating…') : __('Installing…')); ?>">
            <?php echo osc_esc_html($submit); ?>
        </button>
    </div>
<?php osc_admin_form_close(null, array('horizontal' => false));
