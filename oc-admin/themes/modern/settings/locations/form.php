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

use mindstellar\location\LocationAdminView;

/*
 * The add and edit drawers and the delete dialog. Rendered into the page for
 * ?form=…, and alone as ?partial=form for the script; both post the same type= fields.
 */
$loc  = __get('locations');
$form = __get('locationForm');
if (!is_array($form)) {
    return;
}

$level = $form['level'];
$base  = $loc['base'];
$url   = static fn (array $params = array()): string => $base . ($params === array() ? '' : '&' . http_build_query(
    array_filter($params, static fn ($v): bool => $v !== '' && $v !== null && $v !== 0)
));

$countryCode = $loc['country']['code'] ?? '';
$regionId    = $loc['region']['id'] ?? 0;
$keep        = array(
    'q'       => $loc['scope'] === 'level' ? $loc['q'] : '',
    'pageNum' => $loc['page'] > 1 ? $loc['page'] : null,
);
$view    = array('country' => $countryCode, 'region' => $regionId) + $keep;
$back    = $url(array('country' => $countryCode, 'region' => $regionId, 'q' => $loc['q'], 'scope' => $loc['scope'] === 'all' ? 'all' : '') + $keep);
$maxName = $level === 'country' ? 80 : 60;

$nouns = array(
    'country' => static fn (int $n): string => sprintf(_n('%s country', '%s countries', $n), number_format($n)),
    'region'  => static fn (int $n): string => sprintf(_n('%s region', '%s regions', $n), number_format($n)),
    'city'    => static fn (int $n): string => sprintf(_n('%s city', '%s cities', $n), number_format($n)),
);

$errorOnly = static function (string $title, string $message) use ($back): void { ?>
    <div class="loc-form" data-loc-surface="dialog">
        <div class="osc-dialog-body">
            <h2 class="osc-dialog-title"><?php echo osc_esc_html($title); ?></h2>
            <p class="loc-form-error" role="alert"><?php echo osc_esc_html($message); ?></p>
        </div>
        <div class="osc-dialog-actions">
            <a class="btn btn-secondary btn-sm" href="<?php echo osc_esc_html($back); ?>" data-loc-cancel><?php _e('Close'); ?></a>
        </div>
    </div>
<?php };

// Head of a drawer: the title names the record, the line beneath says where it lives.
$drawerHead = static function (string $title, string $hiddenPrefix, string $subtitle) use ($back): void { ?>
    <header class="osc-drawer-head">
        <div>
            <h2 class="osc-drawer-title" id="loc-drawer-title">
                <?php if ($hiddenPrefix !== '') { ?>
                    <span class="visually-hidden"><?php echo osc_esc_html($hiddenPrefix); ?></span>
                <?php } ?>
                <?php echo osc_esc_html($title); ?>
            </h2>
            <?php if ($subtitle !== '') { ?>
                <p class="osc-drawer-subtitle"><?php echo osc_esc_html($subtitle); ?></p>
            <?php } ?>
        </div>
        <a class="osc-drawer-close" href="<?php echo osc_esc_html($back); ?>" data-loc-cancel
           aria-label="<?php echo osc_esc_html(__('Close')); ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></a>
    </header>
<?php };

$drawerActions = static function (string $submit) use ($back): void { ?>
    <div class="osc-drawer-actions">
        <button type="submit" class="btn btn-submit"><?php echo osc_esc_html($submit); ?></button>
        <a class="btn btn-secondary" href="<?php echo osc_esc_html($back); ?>" data-loc-cancel><?php _e('Cancel'); ?></a>
    </div>
<?php };

switch ($form['kind']) {
    case 'add':
        if ($level === 'country') {
            $title    = __('Add country');
            $subtitle = '';
            $fields   = array('type' => 'add_country', 'c_manual' => '1');
        } elseif ($level === 'region') {
            $title    = __('Add region');
            $subtitle = sprintf(__('In %s'), $loc['country']['name']);
            $fields   = array(
                'type'             => 'add_region',
                'country_c_parent' => $countryCode,
                'country_parent'   => $loc['country']['name'],
                'r_manual'         => '1',
            );
        } else {
            $title    = __('Add city');
            $subtitle = sprintf(__('In %s'), implode(' › ', array_filter(array($loc['country']['name'] ?? null, $loc['region']['name']))));
            $fields   = array(
                'type'             => 'add_city',
                'country_c_parent' => $countryCode,
                'country_parent'   => $loc['country']['name'] ?? '',
                'region_parent'    => $regionId,
                'ci_manual'        => '1',
            );
        } ?>
        <div class="loc-drawer-panel" data-loc-surface="drawer">
        <?php osc_admin_form_open(array(
            'page'       => 'settings',
            'action'     => 'locations',
            'fields'     => $fields + $keep,
            'class'      => 'loc-form loc-drawer-form',
            'horizontal' => false,
        ));
        $drawerHead($title, '', $subtitle); ?>
            <div class="osc-drawer-body">
                <?php if ($level === 'country') {
                    // First in the form, so Enter adds rather than imports.
                    ?>
                    <button type="submit" class="visually-hidden" tabindex="-1" aria-hidden="true"><?php echo osc_esc_html($title); ?></button>
                <?php } ?>
                <p class="loc-form-error" role="alert" hidden></p>
                <div class="loc-field">
                    <label class="form-label" for="loc-f-name"><?php _e('Name'); ?></label>
                    <input class="form-control" id="loc-f-name" name="<?php echo $level; ?>" type="text"
                           required maxlength="<?php echo $maxName; ?>" autocomplete="off"/>
                </div>
                <?php if ($level === 'country') { ?>
                    <div class="loc-field">
                        <label class="form-label" for="loc-f-code"><?php _e('Country code'); ?></label>
                        <input class="form-control loc-code-input" id="loc-f-code" name="c_country" type="text"
                               required minlength="2" maxlength="2" pattern="[A-Za-z]{2}" autocomplete="off"
                               aria-describedby="loc-f-code-help"/>
                        <p class="form-text" id="loc-f-code-help"><?php _e('Two letters, as in IN, DE or MT.'); ?></p>
                    </div>
                    <div class="loc-offer" data-loc-offer>
                        <p class="loc-offer-text" data-loc-offer-text aria-live="polite">
                            <?php _e('A country in the catalog can be imported with its regions and cities instead.'); ?>
                        </p>
                        <button type="submit" class="btn btn-secondary btn-sm" name="import_instead" value="1" formnovalidate
                                data-loc-offer-button data-loc-busy="<?php echo osc_esc_html(__('Importing…')); ?>">
                            <i class="bi bi-download" aria-hidden="true"></i>
                            <span><?php _e('Import from the catalog instead'); ?></span>
                        </button>
                    </div>
                <?php } else { ?>
                    <p class="form-text loc-form-note"><?php _e('The slug is made from the name. You can change it after saving.'); ?></p>
                <?php } ?>
                <?php osc_run_hook('admin_locations_drawer_fields', $level, null); ?>
            </div>
            <footer class="osc-drawer-foot">
                <?php $drawerActions($title); ?>
            </footer>
        <?php osc_admin_form_close(null, array('horizontal' => false)); ?>
        </div>
        <?php
        break;

    case 'edit':
        $titles = array('country' => __('Edit country'), 'region' => __('Edit region'), 'city' => __('Edit city'));
        $record = $form['record'];
        if ($record === null) {
            $errorOnly($titles[$level], (string) $form['error']);
            break;
        }
        $path = implode(' › ', array_filter(array(
            $record['country']['name'] ?? $record['country']['code'] ?? null,
            $record['region']['name'] ?? null,
        )));
        $subtitles = array(
            'country' => __('Country'),
            'region'  => $path === '' ? __('Region') : sprintf(__('Region in %s'), $path),
            'city'    => $path === '' ? __('City') : sprintf(__('City in %s'), $path),
        );
        $fields = array('type' => 'edit_' . $level);
        if ($level === 'country') {
            $fields['country_code'] = $record['id'];
        } else {
            $fields[$level . '_id'] = $record['id'];
        }
        $deleteLabels = array(
            'country' => __('Delete this country…'),
            'region'  => __('Delete this region…'),
            'city'    => __('Delete this city…'),
        );

        // Counts are left for the script when it asked for the form alone.
        $counts = $record['counts'];
        $count  = static function (string $key) use ($counts): string {
            if ($counts === null) {
                return '<span class="loc-fact-pending" data-loc-count="' . osc_esc_html($key) . '">'
                    . osc_esc_html(__('Counting…')) . '</span>';
            }

            return '<span data-loc-count="' . osc_esc_html($key) . '">' . number_format((int) $counts[$key]) . '</span>';
        };
        $facts = array();
        if ($level === 'country') {
            $facts[] = array('label' => __('Country code'), 'value' => $record['id'], 'mono' => true);
        } else {
            ob_start();
            $record['active'] ? osc_admin_status('active', __('Active')) : osc_admin_status('inactive', __('Hidden'));
            $facts[] = array('label' => __('Status'), 'value' => ob_get_clean(), 'html' => true);
        }
        if ($level === 'country') {
            $facts[] = array('label' => __('Regions'), 'value' => $count('regions'), 'html' => true);
        }
        if ($level !== 'city') {
            $facts[] = array('label' => __('Cities'), 'value' => $count('cities'), 'html' => true);
        }
        $facts[] = array('label' => __('Listings'), 'value' => $count('listings'), 'html' => true);
        $facts[] = array('label' => __('Users'), 'value' => $count('users'), 'html' => true);
        if ($record['lat'] !== null && $record['long'] !== null) {
            $facts[] = array(
                'label' => __('Coordinates'),
                'value' => sprintf('%s, %s', round((float) $record['lat'], 4), round((float) $record['long'], 4)),
                'mono'  => true,
            );
        }
        if ($record['source_id'] !== null) {
            $facts[] = array('label' => __('Source id'), 'value' => (string) $record['source_id'], 'mono' => true);
        } ?>
        <div class="loc-drawer-panel" data-loc-surface="drawer">
        <?php osc_admin_form_open(array(
            'page'       => 'settings',
            'action'     => 'locations',
            'fields'     => $fields + $keep,
            'class'      => 'loc-form loc-drawer-form',
            'horizontal' => false,
        ));
        $drawerHead($record['name'], $titles[$level] . ': ', $subtitles[$level]); ?>
            <div class="osc-drawer-body">
                <p class="loc-form-error" role="alert" hidden></p>
                <div class="loc-field">
                    <label class="form-label" for="loc-f-name"><?php _e('Name'); ?></label>
                    <input class="form-control" id="loc-f-name" name="e_<?php echo $level; ?>" type="text" required
                           maxlength="<?php echo $maxName; ?>" value="<?php echo osc_esc_html($record['name']); ?>"
                           autocomplete="off"/>
                </div>
                <div class="loc-field">
                    <label class="form-label" for="loc-f-slug"><?php _e('Slug'); ?></label>
                    <input class="form-control osc-mono" id="loc-f-slug" name="e_<?php echo $level; ?>_slug" type="text"
                           maxlength="<?php echo $maxName; ?>" value="<?php echo osc_esc_html($record['slug']); ?>"
                           autocomplete="off" spellcheck="false" aria-describedby="loc-f-slug-error loc-f-slug-help"
                           data-loc-slug-check="<?php echo osc_esc_html($level); ?>"
                           data-loc-self="<?php echo osc_esc_html($record['id']); ?>"/>
                    <p class="loc-field-error" id="loc-f-slug-error" aria-live="polite" hidden></p>
                    <p class="form-text" id="loc-f-slug-help">
                        <?php _e('Used in the address of search pages. Leave blank to make one from the name; a slug already taken gets a number added.'); ?>
                    </p>
                </div>
                <?php osc_run_hook('admin_locations_drawer_fields', $level, $record); ?>
                <section class="loc-facts" aria-labelledby="loc-facts-title"
                         data-loc-record-level="<?php echo osc_esc_html($level); ?>"
                         data-loc-record-id="<?php echo osc_esc_html($record['id']); ?>"
                         <?php echo $counts === null ? 'data-loc-counts-pending aria-busy="true"' : ''; ?>>
                    <h3 class="loc-facts-title" id="loc-facts-title"><?php _e('Details'); ?></h3>
                    <?php osc_admin_definition($facts); ?>
                </section>
            </div>
            <footer class="osc-drawer-foot">
                <?php $drawerActions(__('Save changes')); ?>
                <div class="loc-drawer-danger">
                    <a class="loc-delete-link" href="<?php echo osc_esc_html($url($view + array('form' => 'delete', 'id' => $record['id']))); ?>"
                       data-loc-form><i class="bi bi-trash3" aria-hidden="true"></i><?php echo osc_esc_html($deleteLabels[$level]); ?></a>
                </div>
            </footer>
        <?php osc_admin_form_close(null, array('horizontal' => false)); ?>
        </div>
        <?php
        break;

    case 'delete':
        if ($form['error'] !== null) {
            $errorOnly(__('Delete locations'), (string) $form['error']);
            break;
        }
        $impact = $form['impact'];
        $count  = count($form['ids']);
        $single = $form['record'];
        $what   = $single !== null ? $single['name'] : $nouns[$level]($count);

        $lines = array();
        if ($level === 'country') {
            $lines[] = sprintf(
                __('This removes %1$s along with %2$s and %3$s.'),
                $what,
                $nouns['region']($impact['regions']),
                $nouns['city']($impact['cities'])
            );
        } elseif ($level === 'region') {
            $lines[] = sprintf(__('This removes %1$s along with %2$s.'), $what, $nouns['city']($impact['cities']));
        } else {
            $lines[] = sprintf(__('This removes %s.'), $what);
        }
        $lines[] = $impact['listings'] > 0
            ? sprintf(
                _n('%s listing placed there is deleted.', '%s listings placed there are deleted.', $impact['listings']),
                number_format($impact['listings'])
            )
            : __('No listings are placed there.');
        if ($impact['users'] > 0) {
            $lines[] = sprintf(
                _n(
                    '%s user keeps their account but loses their location.',
                    '%s users keep their account but lose their location.',
                    $impact['users']
                ),
                number_format($impact['users'])
            );
        }

        $confirm = array('country' => __('Delete country'), 'region' => __('Delete region'), 'city' => __('Delete city'));
        if ($count > 1) {
            $confirm[$level] = sprintf(__('Delete %s'), $nouns[$level]($count));
        }
        $phrase = $form['confirm'];

        $fields = array('type' => 'delete_' . $level);
        osc_admin_form_open(array(
            'page'       => 'settings',
            'action'     => 'locations',
            'fields'     => $fields + $keep + array('country' => $countryCode, 'region' => $regionId),
            'class'      => 'loc-form loc-form-danger',
            'horizontal' => false,
        ));
        foreach ($form['ids'] as $id) { ?>
            <input type="hidden" name="id[]" value="<?php echo osc_esc_html($id); ?>"/>
        <?php } ?>
            <div class="osc-dialog-body">
                <h2 class="osc-dialog-title">
                    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                    <?php echo osc_esc_html(sprintf(__('Delete %s?'), $what)); ?>
                </h2>
                <p class="loc-form-error" role="alert" hidden></p>
                <ul class="loc-consequences">
                    <?php foreach ($lines as $line) { ?>
                        <li><?php echo osc_esc_html($line); ?></li>
                    <?php } ?>
                </ul>
                <p class="osc-dialog-text"><strong><?php _e('This cannot be undone.'); ?></strong></p>
                <?php if ($phrase !== null) {
                    $shown = '<strong class="loc-confirm-phrase">'
                        . osc_esc_html($single !== null ? $phrase : number_format((int) $phrase)) . '</strong>'; ?>
                    <div class="loc-field loc-confirm">
                        <label class="form-label" for="loc-f-confirm">
                            <?php echo $single !== null
                                ? sprintf(osc_esc_html(__('Type %s to confirm')), $shown)
                                : sprintf(osc_esc_html(__('Type the number of listings, %s, to confirm')), $shown); ?>
                        </label>
                        <input class="form-control" id="loc-f-confirm" name="confirm_delete" type="text" required
                               autocomplete="off" spellcheck="false" autocapitalize="off"
                               <?php echo $single === null ? 'inputmode="numeric"' : ''; ?>
                               pattern="<?php echo osc_esc_html(LocationAdminView::confirmPattern($phrase)); ?>"
                               data-loc-confirm="<?php echo osc_esc_html($phrase); ?>"/>
                    </div>
                <?php } ?>
            </div>
            <div class="osc-dialog-actions">
                <a class="btn btn-secondary btn-sm" href="<?php echo osc_esc_html($back); ?>" data-loc-cancel><?php _e('Cancel'); ?></a>
                <button type="submit" class="btn btn-danger btn-sm"><?php echo osc_esc_html($confirm[$level]); ?></button>
            </div>
        <?php osc_admin_form_close(null, array('horizontal' => false));
        break;
}
