<?php

declare(strict_types=1);

/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\install;

/**
 * The list of jobs the installer's progress step runs, one request per unit.
 *
 * @package mindstellar\install
 *
 * @phpstan-type Unit array{action:string, fields:array<string,string>, lost:string, label:string}
 * @phpstan-type Task array{id:string, label:string, units:list<Unit>, stop?:bool, when?:string}
 */
final class InstallPlan
{
    /**
     * The tasks in run order.
     *
     * @param string                                                                         $type            'classifieds' or 'shop'
     * @param bool                                                                           $sample          example content chosen
     * @param int                                                                            $batches         example batches (SampleData::batches())
     * @param list<array{action:string, type:string, slug:string, name:string}>              $shopSteps       ShopSetup::steps(), empty for classifieds
     * @param bool                                                                           $needsStorefront the Storefront theme has to be downloaded
     *
     * @return list<Task>
     */
    public static function build(string $type, bool $sample, int $batches, array $shopSteps, bool $needsStorefront): array
    {
        $tasks   = [];
        $account = __('Create your admin account');

        $tasks[] = self::task('account', $account, [self::unit('account', [], '', $account)], ['stop' => true]);

        $label   = __('Add starter categories');
        $tasks[] = self::task('starter', $label, [self::unit('starter', [], 'starter', $label)]);

        $label   = __('Add places in %s');
        $tasks[] = self::task('locations', $label, [self::unit('locations', [], 'locations', $label)], ['when' => 'country']);

        if ($needsStorefront) {
            $label   = __('Download the Storefront theme');
            $tasks[] = self::task('storefront', $label, [self::unit('storefront', [], 'storefront', $label)]);
        }

        if ($sample && $batches > 0) {
            $label   = __('Download example photos');
            $tasks[] = self::task('photos', $label, [self::unit('sample_photos', [], 'sample_photos', $label)]);

            $units = [];
            for ($b = 0; $b < $batches; $b++) {
                $units[] = self::unit(
                    'sample_batch',
                    ['batch' => (string) $b],
                    'sample_batch',
                    sprintf(
                        /* translators: 1: batch number, 2: number of batches */
                        __('batch %1$s of %2$s'),
                        (string) ($b + 1),
                        (string) $batches
                    )
                );
            }
            $tasks[] = self::task('examples', $type === 'shop' ? __('Add example products') : __('Add example listings'), $units);
        }

        return array_merge($tasks, self::shopTasks($shopSteps));
    }

    /**
     * Theme, plugin and shop mode tasks from ShopSetup::steps().
     *
     * @param list<array{action:string, type:string, slug:string, name:string}> $steps
     *
     * @return list<Task>
     */
    private static function shopTasks(array $steps): array
    {
        $theme   = null;
        $plugins = [];
        $mode    = null;
        foreach ($steps as $step) {
            $fields = ['type' => $step['type'], 'slug' => $step['slug']];
            if ($step['action'] === 'activate_theme') {
                $mode = self::unit('activate_theme', $fields, 'activate', __('Turn on shop mode'));
            } elseif ($step['type'] === 'theme') {
                $theme = self::unit(
                    'install_package',
                    $fields,
                    'theme:' . $step['slug'],
                    sprintf(
                        /* translators: %s: theme name */
                        __('Download the %s shop theme'),
                        $step['name']
                    )
                );
            } else {
                $plugins[] = self::unit('install_package', $fields, 'plugin:' . $step['slug'], $step['name']);
            }
        }

        $tasks = [];
        if ($theme !== null) {
            $tasks[] = self::task('theme', $theme['label'], [$theme]);
        }
        if ($plugins !== []) {
            $tasks[] = self::task('plugins', __('Add shop plugins'), $plugins);
        }
        if ($mode !== null) {
            $tasks[] = self::task('shopmode', $mode['label'], [$mode]);
        }

        return $tasks;
    }

    /**
     * @param array<string,string> $fields
     *
     * @return Unit
     */
    private static function unit(string $action, array $fields, string $lost, string $label): array
    {
        return ['action' => $action, 'fields' => $fields, 'lost' => $lost, 'label' => $label];
    }

    /**
     * @param list<Unit>          $units
     * @param array<string,mixed> $extra
     *
     * @return Task
     */
    private static function task(string $id, string $label, array $units, array $extra = []): array
    {
        /** @var Task */
        return ['id' => $id, 'label' => $label] + $extra + ['units' => $units];
    }
}
