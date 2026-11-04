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
     * @param array<string,mixed>      $spec      what osc_customiser_spec() or Customiser::pageSpec() returned
     * @param array<string,mixed>|null $draft     what CustomiserDraft::read() returned
     * @param bool                     $moderator whether the admin is a moderator
     * @param int|null                 $pageId    the Page builder page being edited, or null for theme mode
     *
     * @return array{mode:string,target:array{id:int,title:string,edit_url:string}|null,moderator:bool,locations:array<string,array<string,mixed>>,types:array<string,array<string,mixed>>,look:array<string,array<string,mixed>>,swatches:array<string,array<string,string>>,live:array<string,string>,page:string,page_text:array{locales:array<string,string>,values:array<string,string>}|null,updated_at:int}
     */
    public static function build(array $spec, ?array $draft, bool $moderator = false, ?int $pageId = null): array
    {
        $pageMode  = $pageId !== null;
        $registry  = WidgetRegistry::getInstance();
        $types     = array();
        $locations = array();
        $target    = null;
        foreach ($spec['locations'] as $slug => $location) {
            $location['types'] = array_values(array_filter(
                $location['types'],
                static fn ($typeId): bool => $registry->fits((string) $typeId, (string) $slug, true)
            ));
            $drafted = is_array($draft['locations'][$slug] ?? null);
            $source  = $drafted ? $draft['locations'][$slug] : \Widget::getInstance()->findByLocation($slug);
            $rows    = array();
            $other   = 0;
            foreach ($source as $row) {
                $row = is_array($row) ? self::row($row, $pageMode, $location['types'], $moderator) : null;
                if ($row === null) {
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
                        'icon'        => (string) ($type['icon'] ?? ''),
                        'group'       => self::group($type),
                        'locked'      => $pageMode && Customiser::locked($type, $moderator),
                    );
                }
            }
            $locations[$slug] = array(
                'label'       => $location['label'],
                'preview_url' => self::previewUrl((string) ($location['preview'] ?? osc_base_url()), $pageMode),
                'types'       => $location['types'],
                'rows'        => $rows,
                'other'       => $other,
            );
            if ($pageMode) {
                $target = array(
                    'id'       => $pageId,
                    'title'    => (string) $location['label'],
                    'edit_url' => osc_admin_base_url(true) . '?page=pages&action=edit&id=' . $pageId,
                );
            }
        }

        return array(
            'mode'       => $pageMode ? 'page' : 'theme',
            'target'     => $target,
            'moderator'  => $moderator,
            'locations'  => $locations,
            'types'      => $types,
            'look'       => self::look($spec, $draft),
            'swatches'   => $spec['swatches'],
            'live'       => $spec['live'],
            'page'       => (string) $spec['settings']['page'],
            'page_text'  => $pageMode ? self::pageText((int) $pageId, $draft) : null,
            'updated_at' => (int) ($draft['updated_at'] ?? 0),
        );
    }

    /**
     * The page's text per site language for the Page text panel: the drafted text, else the
     * live one.
     *
     * @param array<string,mixed>|null $draft
     *
     * @return array{locales:array<string,string>,values:array<string,string>}
     * @throws \mindstellar\database\DbException
     */
    private static function pageText(int $pageId, ?array $draft): array
    {
        $live    = (new \mindstellar\pages\PageQuery())->texts(array($pageId))[$pageId] ?? array();
        $drafted = is_array($draft['page_text'] ?? null) ? $draft['page_text'] : array();
        $locales = array();
        $values  = array();
        foreach ((array) osc_get_locales() as $locale) {
            $code           = (string) $locale['pk_c_code'];
            $locales[$code] = (string) $locale['s_name'];
            $values[$code]  = is_string($drafted[$code] ?? null) ? $drafted[$code] : (string) ($live[$code]['s_text'] ?? '');
        }

        return array('locales' => $locales, 'values' => $values);
    }

    /**
     * The preview address with the preview flag the theme's front end looks for. Page mode also
     * adds osc_blocks=1, which turns on the block marks and the frame script.
     */
    public static function previewUrl(string $url, bool $blocks = false): string
    {
        return $url . (strpos($url, '?') === false ? '?' : '&') . 'osc_preview=1' . ($blocks ? '&osc_blocks=1' : '');
    }

    /**
     * The tile group of a type: advanced for one only a super admin may author, layout for a
     * Page builder block, else content.
     *
     * @param array<string,mixed> $type
     */
    private static function group(array $type): string
    {
        if (($type['capability'] ?? 'admin') === 'super_admin') {
            return 'advanced';
        }

        return !empty($type['builder']) ? 'layout' : 'content';
    }

    /**
     * How a page block reads in a list, from a t_widget row or a drafted row: its name (the
     * description, else the type's label, else Custom HTML for a legacy row), the type's label and
     * icon, and whether it is hidden on the site (switched off, or a type no longer registered).
     *
     * @param array<string,mixed> $row
     *
     * @return array{name:string,type:string,icon:string,hidden:bool}
     */
    public static function blockLabel(array $row): array
    {
        $row    = self::drafted($row);
        $typeId = is_string($row['type'] ?? null) ? $row['type'] : '';
        $spec   = $typeId === '' ? null : WidgetRegistry::getInstance()->get($typeId);
        $label  = $spec !== null ? (string) $spec['label'] : __('Custom HTML');
        $name   = trim((string) ($row['description'] ?? ''));

        return array(
            'name'   => $name !== '' ? $name : $label,
            'type'   => $label,
            'icon'   => $spec !== null ? (string) ($spec['icon'] ?? '') : '',
            'hidden' => ($typeId !== '' && $spec === null) || !WidgetConfig::enabled(is_array($row['config'] ?? null) ? $row['config'] : array()),
        );
    }

    /**
     * A t_widget row in the drafted shape; a drafted row as it is.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private static function drafted(array $row): array
    {
        if (!array_key_exists('pk_i_id', $row)) {
            return $row;
        }
        $config = json_decode((string) ($row['s_config'] ?? ''), true);

        return array(
            'id'          => (int) $row['pk_i_id'],
            'key'         => '',
            'type'        => (string) ($row['s_type'] ?? ''),
            'description' => (string) ($row['s_description'] ?? ''),
            'config'      => is_array($config) ? $config : array(),
        );
    }

    /**
     * One row as the screen keeps it, from a t_widget row or a drafted row. Null when it is
     * not shown: in theme mode a row with no type or a type the location does not offer; on a
     * page a type the location does not offer. A page row with no type, or a type no longer
     * registered, is shown as legacy and locked.
     *
     * @param array<string,mixed> $row
     * @param string[]            $allowed the type ids the location offers
     *
     * @return array{ref:string,id:int|null,key:string,type:string,description:string,enabled:bool,config:array<string,mixed>,locked:bool,legacy:bool}|null
     */
    private static function row(array $row, bool $pageMode, array $allowed, bool $moderator): ?array
    {
        $row    = self::drafted($row);
        $type   = is_string($row['type'] ?? null) ? $row['type'] : '';
        $spec   = $type === '' ? null : WidgetRegistry::getInstance()->get($type);
        $legacy = $pageMode && $spec === null;
        if (!$legacy && ($type === '' || !in_array($type, $allowed, true))) {
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
            'locked'      => $pageMode && Customiser::locked($spec, $moderator),
            'legacy'      => $legacy,
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
