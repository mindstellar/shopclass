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
 * The installed plugins as an admin data table: status filter, search, sort and paging over
 * the plugin records that packages() reads from disk.
 *
 * @package    Shopclass
 * @subpackage classes
 */
class PluginsDataTable extends DataTable
{
    /** The status filters, each with the state it keeps; 'update' keeps rows with an update. */
    public const STATUSES = array('active', 'disabled', 'uninstalled', 'update');

    /** Header column id => record field it sorts by. */
    private const SORTABLE = array('name' => 'name', 'status' => 'state');

    /** Where each state sorts when ordered by status. */
    private const STATE_ORDER = array('active' => 0, 'disabled' => 1, 'uninstalled' => 2);

    /** @var array<int,array<string,mixed>> */
    private array $packages;

    /** @var array<string,int> */
    private array $counts = array();

    /**
     * @param array<int,array<string,mixed>> $packages one record per plugin, as packages() builds them
     */
    public function __construct(array $packages)
    {
        parent::__construct();
        $this->packages = $packages;
    }

    /**
     * Filters, sorts and pages the plugins.
     *
     * @param array<string,mixed> $params status, sSearch, sort, direction, iPage, iDisplayLength
     *
     * @return array<string,mixed> The getData() payload
     */
    public function table($params)
    {
        $status = is_string($params['status'] ?? null) && in_array($params['status'], self::STATUSES, true) ? $params['status'] : '';
        $search = is_string($params['sSearch'] ?? null) ? trim($params['sSearch']) : '';
        $order  = $this->resolveOrder($params, self::SORTABLE, 'state', 'ASC');

        $this->addTableHeader($params, $order);

        $this->counts = array('all' => 0) + array_fill_keys(self::STATUSES, 0);
        $rows         = array();
        foreach ($this->packages as $pkg) {
            $matches = $search === '' || mb_stripos((string) $pkg['name'], $search) !== false
                || mb_stripos((string) $pkg['description'], $search) !== false;
            if (!$matches) {
                continue;
            }
            $this->counts['all']++;
            $this->counts[$pkg['state']] = ($this->counts[$pkg['state']] ?? 0) + 1;
            if (!empty($pkg['update'])) {
                $this->counts['update']++;
            }
            if ($status === '' || $pkg['state'] === $status || ($status === 'update' && !empty($pkg['update']))) {
                $rows[] = $pkg;
            }
        }

        usort($rows, static function (array $a, array $b) use ($order): int {
            $cmp = $order['column_name'] === 'state'
                ? (self::STATE_ORDER[$a['state']] ?? 3) <=> (self::STATE_ORDER[$b['state']] ?? 3)
                : 0;
            $cmp = $cmp !== 0 ? $cmp : strnatcasecmp((string) $a['name'], (string) $b['name']);

            return $order['type'] === 'DESC' ? -$cmp : $cmp;
        });

        $this->limit         = max(1, (int) ($params['iDisplayLength'] ?? 25));
        $this->iPage         = max(1, (int) ($params['iPage'] ?? 1));
        $this->start         = ($this->iPage - 1) * $this->limit;
        $this->total         = count($this->packages);
        $this->totalFiltered = count($rows);

        foreach (array_slice($rows, $this->start, $this->limit) as $pkg) {
            $this->addRow($this->row($pkg));
            $this->rawRows[] = $pkg;
        }

        return $this->getData();
    }

    /**
     * How many plugins each status tab holds, after the search: all, active, disabled,
     * uninstalled, update.
     *
     * @return array<string,int>
     */
    public function counts(): array
    {
        return $this->counts;
    }

    /**
     * Registers the columns; Name and Status are header links that sort.
     *
     * @param array<string,mixed>                     $params
     * @param array{column_name:string,type:string} $order
     *
     * @return void
     */
    private function addTableHeader(array $params, array $order): void
    {
        $base = osc_admin_base_url(true) . '?page=plugins';
        foreach (array('status', 'sSearch') as $keep) {
            if (is_string($params[$keep] ?? null) && $params[$keep] !== '') {
                $base .= '&' . $keep . '=' . urlencode($params[$keep]);
            }
        }
        $sortLink = static function (string $id, string $label) use ($base, $order): string {
            $current = self::SORTABLE[$id] === $order['column_name'];
            $next    = $current && $order['type'] === 'ASC' ? 'desc' : 'asc';

            return '<a href="' . osc_esc_html($base . '&sort=' . $id . '&direction=' . $next) . '">' . osc_esc_html($label) . '</a>';
        };

        $this->addColumn('bulkactions', '<input id="check_all" type="checkbox" aria-label="' . osc_esc_html(__('Select all')) . '" />');
        $this->addColumn('status', $sortLink('status', __('Status')));
        $this->addColumn('name', $sortLink('name', __('Name')));
        $this->addColumn('version', osc_esc_html(__('Version')));
    }

    /**
     * One plugin's cells. Status is the plain state; the theme draws its badge.
     *
     * @param array<string,mixed> $pkg
     *
     * @return array<string,string>
     */
    private function row(array $pkg): array
    {
        $url  = osc_admin_base_url(true) . '?page=plugins&amp;action=';
        $csrf = '&amp;' . osc_csrf_token_url();
        $file = urlencode((string) $pkg['file']);
        $js   = static fn ($s): string => osc_esc_html(osc_esc_js((string) $s));

        $options = array();
        if (!empty($pkg['configurable'])) {
            $options[] = '<a href="' . $url . 'admin&amp;plugin=' . $file . $csrf . '">' . osc_esc_html(__('Settings')) . '</a>';
        }
        if ($pkg['installed']) {
            $options[] = $pkg['enabled']
                ? '<a href="' . $url . 'disable&amp;plugin=' . $file . $csrf . '">' . osc_esc_html(__('Disable')) . '</a>'
                : '<a href="' . $url . 'enable&amp;plugin=' . $file . $csrf . '">' . osc_esc_html(__('Enable')) . '</a>';
        } else {
            $options[] = '<a href="' . $url . 'install&amp;plugin=' . $file . $csrf . '">' . osc_esc_html(__('Install')) . '</a>';
        }
        $options[] = '<a href="#" data-market-open-detail>' . osc_esc_html(__('Details')) . '</a>';
        if ($pkg['plugin_uri'] !== '') {
            $options[] = '<a target="_blank" rel="noopener" href="' . osc_esc_html(osc_sanitize_url($pkg['plugin_uri'])) . '">'
                . osc_esc_html(__('Website')) . '</a>';
        }
        if ($pkg['support_uri'] !== '') {
            $options[] = '<a target="_blank" rel="noopener" href="' . osc_esc_html(osc_sanitize_url($pkg['support_uri'])) . '">'
                . osc_esc_html(__('Support')) . '</a>';
        }
        $options[] = $pkg['installed']
            ? '<a class="row-action-danger" href="' . $url . 'uninstall&amp;plugin=' . $file . $csrf
                . '" onclick="return uninstall_dialog(\'' . $js($pkg['file']) . '\', \'' . $js($pkg['name']) . '\');">'
                . osc_esc_html(__('Uninstall')) . '</a>'
            : '<a class="row-action-danger" href="#" onclick="return delete_plugin(\'' . $js($pkg['file']) . '\');">'
                . osc_esc_html(__('Delete files')) . '</a>';

        $actions = '<div class="actions"><ul>';
        foreach ($options as $option) {
            $actions .= '<li>' . $option . '</li>';
        }
        $actions .= '</ul></div>';

        return array(
            'bulkactions' => '<input type="checkbox" name="plugin[]" value="' . osc_esc_html($pkg['file']) . '"'
                . ' aria-label="' . osc_esc_html(sprintf(__('Select %s'), $pkg['name'])) . '" />',
            'status'      => osc_esc_html($pkg['state']),
            'name'        => '<strong class="plugin-name" dir="auto">' . osc_esc_html($pkg['name']) . '</strong>'
                . self::shortDescription((string) $pkg['description']) . $actions,
            'version'     => osc_esc_html($pkg['version']),
        );
    }

    /**
     * One line of the description, cut near 80 characters; the full text is its title.
     */
    private static function shortDescription(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return '';
        }
        $short = mb_strlen($text) > 80 ? rtrim(mb_substr($text, 0, 79)) . '…' : $text;

        return '<span class="plugin-desc" dir="auto" title="' . osc_esc_html($text) . '">' . osc_esc_html($short) . '</span>';
    }

    /**
     * Every plugin on disk as a record: file, slug, name, version, author, links,
     * description, state (active, disabled, uninstalled), installed, enabled, update,
     * configurable and modes.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function packages(): array
    {
        $active   = osc_get_plugins();
        $toUpdate = osc_update_check_state('plugins')['to_update'];
        $toUpdate = is_array($toUpdate) ? $toUpdate : array();
        // Catalog updates are keyed by slug and read from the cached catalog only.
        try {
            $pending = \mindstellar\market\PackageIndex::forPlugins()->pendingUpdates();
        } catch (\Throwable $e) {
            $pending = array();
        }

        $packages = array();
        foreach (Plugins::listAll() as $plugin) {
            $info      = osc_plugin_get_info($plugin);
            $slug      = dirname($plugin) !== '.' ? dirname($plugin) : $plugin;
            $installed = (bool) osc_plugin_is_installed($plugin);
            $enabled   = (bool) osc_plugin_is_enabled($plugin);
            $updateUri = (string) ($info['plugin_update_uri'] ?? '');

            $packages[] = array(
                'slug'         => $slug,
                'file'         => (string) $info['filename'],
                'name'         => (string) $info['plugin_name'],
                'version'      => (string) $info['version'],
                'author'       => (string) $info['author'],
                'author_uri'   => (string) $info['author_uri'],
                'plugin_uri'   => (string) $info['plugin_uri'],
                'support_uri'  => (string) $info['support_uri'],
                'description'  => (string) $info['description'],
                'state'        => $installed ? ($enabled ? 'active' : 'disabled') : 'uninstalled',
                'installed'    => $installed,
                'enabled'      => $enabled,
                'update'       => isset($pending[$slug]) || ($updateUri !== '' && in_array($updateUri, $toUpdate, true)),
                'configurable' => isset($active[$plugin . '_configure']),
                'modes'        => (array) ($info['modes'] ?? array()),
            );
        }

        return $packages;
    }
}
