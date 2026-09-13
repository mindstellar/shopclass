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

/*
 * The add, edit, delete and import forms. Rendered inline above the list for ?form=…, and
 * alone as ?partial=form for the dialog; both post the same type= fields.
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
$view        = array('country' => $countryCode, 'region' => $regionId, 'pageNum' => $loc['page'] > 1 ? $loc['page'] : null);
$back        = $url($view);
$route       = array('pageNum' => $view['pageNum']);
$maxName     = $level === 'country' ? 80 : 60;

$nouns = array(
    'country' => static fn (int $n): string => sprintf(_n('%s country', '%s countries', $n), number_format($n)),
    'region'  => static fn (int $n): string => sprintf(_n('%s region', '%s regions', $n), number_format($n)),
    'city'    => static fn (int $n): string => sprintf(_n('%s city', '%s cities', $n), number_format($n)),
);

$cancel = static function () use ($back): void { ?>
    <a class="btn btn-secondary btn-sm" href="<?php echo osc_esc_html($back); ?>" data-loc-cancel><?php _e('Cancel'); ?></a>
<?php };

$errorOnly = static function (string $title, string $message) use ($back): void { ?>
    <div class="loc-form">
        <div class="osc-dialog-body">
            <h2 class="osc-dialog-title"><?php echo osc_esc_html($title); ?></h2>
            <p class="loc-form-error" role="alert"><?php echo osc_esc_html($message); ?></p>
        </div>
        <div class="osc-dialog-actions">
            <a class="btn btn-secondary btn-sm" href="<?php echo osc_esc_html($back); ?>" data-loc-cancel><?php _e('Close'); ?></a>
        </div>
    </div>
<?php };

switch ($form['kind']) {
    case 'add':
        if ($level === 'country') {
            $title  = __('Add country');
            $fields = array('type' => 'add_country', 'c_manual' => '1');
        } elseif ($level === 'region') {
            $title  = sprintf(__('Add region to %s'), $loc['country']['name']);
            $fields = array(
                'type'             => 'add_region',
                'country_c_parent' => $countryCode,
                'country_parent'   => $loc['country']['name'],
                'r_manual'         => '1',
            );
        } else {
            $title  = sprintf(__('Add city to %s'), $loc['region']['name']);
            $fields = array(
                'type'             => 'add_city',
                'country_c_parent' => $countryCode,
                'country_parent'   => $loc['country']['name'] ?? '',
                'region_parent'    => $regionId,
                'ci_manual'        => '1',
            );
        }
        $nameField = array('country' => 'country', 'region' => 'region', 'city' => 'city')[$level];

        osc_admin_form_open(array(
            'page'       => 'settings',
            'action'     => 'locations',
            'fields'     => $fields + $route,
            'class'      => 'loc-form',
            'horizontal' => false,
        )); ?>
            <div class="osc-dialog-body">
                <h2 class="osc-dialog-title"><?php echo osc_esc_html($title); ?></h2>
                <p class="loc-form-error" role="alert" hidden></p>
                <div class="loc-field">
                    <label class="form-label" for="loc-f-name"><?php _e('Name'); ?></label>
                    <input class="form-control" id="loc-f-name" name="<?php echo $nameField; ?>" type="text"
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
                <?php } ?>
            </div>
            <div class="osc-dialog-actions">
                <?php $cancel(); ?>
                <button type="submit" class="btn btn-submit btn-sm"><?php echo osc_esc_html(array('country' => __('Add country'), 'region' => __('Add region'), 'city' => __('Add city'))[$level]); ?></button>
            </div>
        <?php osc_admin_form_close(null, array('horizontal' => false));
        break;

    case 'edit':
        $titles = array('country' => __('Edit country'), 'region' => __('Edit region'), 'city' => __('Edit city'));
        $record = $form['record'];
        if ($record === null) {
            $errorOnly($titles[$level], (string) $form['error']);
            break;
        }
        $path = array_filter(array(
            $record['country']['name'] ?? $record['country']['code'] ?? null,
            $record['region']['name'] ?? null,
        ));
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

        osc_admin_form_open(array(
            'page'       => 'settings',
            'action'     => 'locations',
            'fields'     => $fields + $route,
            'class'      => 'loc-form',
            'horizontal' => false,
        )); ?>
            <div class="osc-dialog-body">
                <h2 class="osc-dialog-title"><?php echo osc_esc_html($titles[$level]); ?></h2>
                <?php if ($path !== array()) { ?>
                    <p class="loc-form-context"><?php echo osc_esc_html(implode(' › ', $path)); ?></p>
                <?php } ?>
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
                           autocomplete="off" aria-describedby="loc-f-slug-help"/>
                    <p class="form-text" id="loc-f-slug-help">
                        <?php _e('Used in the address of search pages. Leave blank to make one from the name; a slug already taken gets a number added.'); ?>
                    </p>
                </div>
            </div>
            <div class="osc-dialog-actions">
                <?php $cancel(); ?>
                <button type="submit" class="btn btn-submit btn-sm"><?php _e('Save changes'); ?></button>
            </div>
            <div class="loc-form-aside">
                <a class="loc-delete-link" href="<?php echo osc_esc_html($url($view + array('form' => 'delete', 'id' => $record['id']))); ?>"
                   data-loc-form><?php echo osc_esc_html($deleteLabels[$level]); ?></a>
            </div>
        <?php osc_admin_form_close(null, array('horizontal' => false));
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

        $fields = array('type' => 'delete_' . $level);
        osc_admin_form_open(array(
            'page'       => 'settings',
            'action'     => 'locations',
            'fields'     => $fields + $route + array('country' => $countryCode, 'region' => $regionId),
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
                <?php foreach ($lines as $line) { ?>
                    <p class="osc-dialog-text"><?php echo osc_esc_html($line); ?></p>
                <?php } ?>
                <p class="osc-dialog-text"><strong><?php _e('This cannot be undone.'); ?></strong></p>
            </div>
            <div class="osc-dialog-actions">
                <?php $cancel(); ?>
                <button type="submit" class="btn btn-danger btn-sm"><?php echo osc_esc_html($confirm[$level]); ?></button>
            </div>
        <?php osc_admin_form_close(null, array('horizontal' => false));
        break;

    case 'import':
        $groups = array(__('Update available') => array(), __('Not installed') => array());
        foreach ($form['catalog'] as $row) {
            $groups[$row['installed'] ? __('Update available') : __('Not installed')][] = $row;
        }

        osc_admin_form_open(array(
            'page'       => 'settings',
            'action'     => 'locations',
            'fields'     => array('type' => 'locations_import'),
            'class'      => 'loc-form',
            'horizontal' => false,
        )); ?>
            <div class="osc-dialog-body">
                <h2 class="osc-dialog-title"><?php _e('Import locations'); ?></h2>
                <p class="loc-form-error" role="alert" hidden></p>
                <p class="osc-dialog-text">
                    <?php _e('Import a country with its regions and cities. Countries you already have appear only when newer data is available for them.'); ?>
                </p>
                <?php if ($form['catalog'] === array()) { ?>
                    <p class="loc-form-notice"><?php _e('No countries available right now'); ?></p>
                <?php } else { ?>
                    <div class="loc-field">
                        <label class="form-label" for="loc-f-import"><?php _e('Country'); ?></label>
                        <select class="form-select" id="loc-f-import" name="location" required>
                            <option value=""><?php _e('Select option'); ?></option>
                            <?php foreach ($groups as $label => $rows) {
                                if ($rows === array()) {
                                    continue;
                                } ?>
                                <optgroup label="<?php echo osc_esc_html($label); ?>">
                                    <?php foreach ($rows as $row) { ?>
                                        <option value="<?php echo osc_esc_html($row['code']); ?>"><?php echo osc_esc_html($row['name']); ?></option>
                                    <?php } ?>
                                </optgroup>
                            <?php } ?>
                        </select>
                    </div>
                <?php } ?>
            </div>
            <div class="osc-dialog-actions">
                <?php $cancel(); ?>
                <?php if ($form['catalog'] !== array()) { ?>
                    <button type="submit" class="btn btn-submit btn-sm" data-loc-busy="<?php echo osc_esc_html(__('Importing…')); ?>">
                        <?php _e('Import'); ?>
                    </button>
                <?php } ?>
            </div>
        <?php osc_admin_form_close(null, array('horizontal' => false));
        break;
}
