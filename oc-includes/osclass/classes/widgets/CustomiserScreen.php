<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\widgets;

use mindstellar\settings\SettingsImage;
use mindstellar\settings\SettingsPageRegistry;

/**
 * What the Appearance > Customise screen shows: each customisable location's rows, the
 * section types it may hold, and the look fields with their values. The admin's draft wins
 * over the published rows and values wherever it has drafted something.
 */
final class CustomiserScreen
{
    private function __construct()
    {
    }

    /**
     * @param array<string,mixed>      $spec  what osc_customiser_spec() returned
     * @param array<string,mixed>|null $draft what CustomiserDraft::read() returned
     *
     * @return array{locations:array<string,array<string,mixed>>,types:array<string,array<string,string>>,look:array<string,array<string,mixed>>,swatches:array<string,array<string,string>>,live:array<string,string>,page:string,updated_at:int}
     */
    public static function build(array $spec, ?array $draft): array
    {
        $registry  = WidgetRegistry::getInstance();
        $types     = array();
        $locations = array();
        foreach ($spec['locations'] as $slug => $location) {
            $location['types'] = array_values(array_filter(
                $location['types'],
                static fn ($typeId): bool => $registry->fits((string) $typeId, (string) $slug)
            ));
            $drafted = is_array($draft['locations'][$slug] ?? null);
            $source  = $drafted ? $draft['locations'][$slug] : \Widget::getInstance()->findByLocation($slug);
            $rows    = array();
            $other   = 0;
            foreach ($source as $row) {
                $row = is_array($row) ? self::row($row) : null;
                if ($row === null || !in_array($row['type'], $location['types'], true)) {
                    $other++;
                    continue;
                }
                $rows[] = $row;
            }
            foreach ($location['types'] as $typeId) {
                $type = $registry->get($typeId);
                if ($type !== null) {
                    $types[$typeId] = array(
                        'label'       => (string) $type['label'],
                        'description' => (string) ($type['description'] ?? ''),
                    );
                }
            }
            $locations[$slug] = array(
                'label'       => $location['label'],
                'preview_url' => osc_customiser_preview_url((string) $slug),
                'types'       => $location['types'],
                'rows'        => $rows,
                'other'       => $other,
            );
        }

        return array(
            'locations'  => $locations,
            'types'      => $types,
            'look'       => self::look($spec, $draft),
            'swatches'   => $spec['swatches'],
            'live'       => $spec['live'],
            'page'       => (string) $spec['settings']['page'],
            'updated_at' => (int) ($draft['updated_at'] ?? 0),
        );
    }

    /**
     * One row as the screen keeps it, from a t_widget row or a drafted row; null when it has
     * no type.
     *
     * @param array<string,mixed> $row
     *
     * @return array{ref:string,id:int|null,key:string,type:string,description:string,enabled:bool,config:array<string,mixed>}|null
     */
    private static function row(array $row): ?array
    {
        if (array_key_exists('pk_i_id', $row)) {
            $config = json_decode((string) ($row['s_config'] ?? ''), true);
            $row    = array(
                'id'          => (int) $row['pk_i_id'],
                'key'         => '',
                'type'        => (string) ($row['s_type'] ?? ''),
                'description' => (string) ($row['s_description'] ?? ''),
                'config'      => is_array($config) ? $config : array(),
            );
        }
        $type = is_string($row['type'] ?? null) ? $row['type'] : '';
        if ($type === '') {
            return null;
        }
        $id     = isset($row['id']) && is_scalar($row['id']) && (int) $row['id'] > 0 ? (int) $row['id'] : null;
        $key    = is_string($row['key'] ?? null) ? $row['key'] : '';
        $config = is_array($row['config'] ?? null) ? $row['config'] : array();

        $enabled = WidgetConfig::enabled($config);
        unset($config[WidgetConfig::ENABLED]);

        return array(
            'ref'         => $id !== null ? (string) $id : $key,
            'id'          => $id,
            'key'         => $key,
            'type'        => $type,
            'description' => (string) ($row['description'] ?? ''),
            'enabled'     => $enabled,
            'config'      => $config,
        );
    }

    /**
     * The look fields in the order the theme named them, each with the value to show: the
     * drafted one, else the published one.
     *
     * @param array<string,mixed>      $spec
     * @param array<string,mixed>|null $draft
     *
     * @return array<string,array{field:array<string,mixed>,value:mixed,preview_url:string}>
     */
    private static function look(array $spec, ?array $draft): array
    {
        $page   = (string) $spec['settings']['page'];
        $fields = $page === '' ? array() : SettingsPageRegistry::getInstance()->fields($page);
        $stored = $fields === array() ? array() : osc_settings_values($page);
        $look   = array();
        foreach ($spec['settings']['fields'] as $name) {
            if (!isset($fields[$name])) {
                continue;
            }
            $value = is_array($draft) && array_key_exists($name, $draft['settings']) ? $draft['settings'][$name] : ($stored[$name] ?? '');
            $look[$name] = array(
                'field'       => $fields[$name],
                'value'       => $value,
                'preview_url' => $fields[$name]['type'] === 'image' && (string) $value !== '' ? SettingsImage::url($value, 'thumbnail') : '',
            );
        }

        return $look;
    }
}
