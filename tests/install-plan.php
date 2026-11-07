<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Pins the task list of the installer's progress step.
 *
 * DB-free.  Usage: php tests/install-plan.php
 */

namespace {
    define('ABS_PATH', dirname(__DIR__) . '/');
    define('LIB_PATH', ABS_PATH . 'oc-includes/');

    function __($text)
    {
        return $text;
    }

    require_once LIB_PATH . 'classes/core/install/InstallPlan.php';
    require_once __DIR__ . '/lib/harness.php';

    use mindstellar\install\InstallPlan;

    $ids   = static fn (array $plan): array => array_column($plan, 'id');
    $units = static function (array $plan): array {
        $all = array();
        foreach ($plan as $task) {
            foreach ($task['units'] as $unit) {
                $all[] = $unit;
            }
        }

        return $all;
    };
    $byId = static function (array $plan, string $id): array {
        foreach ($plan as $task) {
            if ($task['id'] === $id) {
                return $task;
            }
        }

        return array();
    };

    harness_section('classifieds, no example content');
    $plan = InstallPlan::build('classifieds', false, 0, array(), false);
    check('tasks are account, starter, locations', $ids($plan) === array('account', 'starter', 'locations'));
    check('account stops the run on failure', ($plan[0]['stop'] ?? false) === true && !isset($plan[1]['stop']));
    check('places task is dropped when no country is picked', ($plan[2]['when'] ?? '') === 'country');
    check('account unit is not recorded as lost', $plan[0]['units'][0]['lost'] === '');

    harness_section('storefront');
    $plan = InstallPlan::build('classifieds', false, 0, array(), true);
    check('storefront follows places', $ids($plan) === array('account', 'starter', 'locations', 'storefront'));

    harness_section('classifieds with examples');
    $plan = InstallPlan::build('classifieds', true, 3, array(), false);
    check('photos and examples come after places', $ids($plan) === array('account', 'starter', 'locations', 'photos', 'examples'));
    $examples = $byId($plan, 'examples');
    check('examples has one unit per batch', count($examples['units']) === 3);
    check('batch numbers run 0..2', array_map(static fn ($u) => $u['fields']['batch'], $examples['units']) === array('0', '1', '2'));
    check('unit label names the batch', $examples['units'][1]['label'] === 'batch 2 of 3');
    check('classifieds label says listings', $examples['label'] === 'Add example listings');

    harness_section('examples chosen but no batches');
    $plan = InstallPlan::build('classifieds', true, 0, array(), false);
    check('no photos or examples task without batches', $ids($plan) === array('account', 'starter', 'locations'));

    harness_section('shop');
    $steps = array(
        array('action' => 'install_package', 'type' => 'theme', 'slug' => 'aisle', 'name' => 'Aisle'),
        array('action' => 'install_package', 'type' => 'plugin', 'slug' => 'paypal', 'name' => 'PayPal'),
        array('action' => 'install_package', 'type' => 'plugin', 'slug' => 'stripe', 'name' => 'Stripe'),
        array('action' => 'activate_theme', 'type' => 'theme', 'slug' => 'aisle', 'name' => 'Aisle'),
    );
    $plan = InstallPlan::build('shop', true, 3, $steps, true);
    check('run order', $ids($plan) === array('account', 'starter', 'locations', 'storefront', 'photos', 'examples', 'theme', 'plugins', 'shopmode'));
    check('shop label says products', $byId($plan, 'examples')['label'] === 'Add example products');
    $plugins = $byId($plan, 'plugins');
    check('two plugin units named', array_column($plugins['units'], 'label') === array('PayPal', 'Stripe'));
    check('lost tokens', array_column($units($plan), 'lost') === array('', 'starter', 'locations', 'storefront', 'sample_photos', 'sample_batch', 'sample_batch', 'sample_batch', 'theme:aisle', 'plugin:paypal', 'plugin:stripe', 'activate'));
    $theme = $byId($plan, 'theme')['units'][0];
    check('theme unit posts type and slug', $theme['action'] === 'install_package' && $theme['fields'] === array('type' => 'theme', 'slug' => 'aisle'));
    $mode = $byId($plan, 'shopmode')['units'][0];
    check('shop mode unit activates the theme', $mode['action'] === 'activate_theme' && $mode['lost'] === 'activate');

    harness_section('endpoint actions');
    $known = array('account', 'starter', 'locations', 'storefront', 'sample_photos', 'sample_batch', 'install_package', 'activate_theme');
    $bad   = array_filter($units($plan), static fn ($u) => !in_array($u['action'], $known, true));
    check('every unit action is one the endpoint accepts', $bad === array());
    check('total units is 1+1+1+1+1+3+1+2+1', count($units($plan)) === 12);

    harness_section('shop without plugins');
    $plan = InstallPlan::build('shop', false, 0, array($steps[0], $steps[3]), false);
    check('no plugins task when none are ticked', $ids($plan) === array('account', 'starter', 'locations', 'theme', 'shopmode'));

    exit(harness_result());
}
