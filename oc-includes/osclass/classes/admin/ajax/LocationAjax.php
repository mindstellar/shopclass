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

namespace mindstellar\admin\ajax;

use City;
use Country;
use InvalidArgumentException;
use mindstellar\database\DbException;
use mindstellar\location\LocationAdminQuery;
use mindstellar\location\LocationAdminView;
use mindstellar\location\LocationCatalog;
use mindstellar\utility\AjaxResponse;
use Params;
use Region;

/**
 * Location lookups, slug checks, the import catalog and the Locations screen reads.
 */
final class LocationAjax extends AjaxHandler
{
    /** Regions of a country. */
    public function regions(): void
    {
        AjaxResponse::json(Region::getInstance()->findByCountry(Params::getParam('countryId')));
    }

    /** Cities of a region. */
    public function cities(): void
    {
        AjaxResponse::json(City::getInstance()->findByRegion(Params::getParamInt('regionId')));
    }

    /** The city autocomplete. */
    public function cityLookup(): void
    {
        AjaxResponse::json(City::getInstance()->ajax(Params::getParam('term')));
    }

    /** Countries the published catalog offers for import. */
    public function catalog(): void
    {
        // Read through the catalog: the published URL names the current release rather
        // than listing countries, and following it is server-side work cached here.
        $catalog = new LocationCatalog();
        $rows    = array();
        foreach ($catalog->status(Params::getParamInt('refresh') === 1) as $row) {
            if ($row['file'] === '' && $row['ndjson'] === '') {
                continue;
            }
            // Only what the dialog draws, named by code rather than by file path.
            $rows[] = array(
                'code'      => $row['code'],
                'name'      => $row['name'],
                'installed' => (bool) $row['installed'],
                'current'   => (bool) $row['current'],
                'rows'      => (int) $row['rows'],
                'regions'   => (int) ($row['regions'] ?? 0),
            );
        }
        AjaxResponse::json(array(
            'release'   => $catalog->release(),
            'countries' => $rows,
        ));
    }

    public function countrySlug(): void
    {
        self::slugTaken(Country::getInstance()->findBySlug(Params::getParam('slug')), 'country');
    }

    public function regionSlug(): void
    {
        self::slugTaken(Region::getInstance()->findBySlug(Params::getParam('slug')), 'region');
    }

    public function citySlug(): void
    {
        self::slugTaken(City::getInstance()->findBySlug(Params::getParam('slug')), 'city');
    }

    /** One batch of the location stats recount; answers whether more is left. */
    public function stats(): void
    {
        $workToDo = osc_update_location_stats();
        if ($workToDo > 0) {
            $array['status']  = 'more';
            $array['pending'] = $workToDo;
        } else {
            $array['status'] = 'done';
        }
        AjaxResponse::json($array);
    }

    /**
     * The Locations screen reads: search, delete impact or a single record.
     *
     * @param string $action location_search|location_impact|location_record
     */
    public function read(string $action): void
    {
        AjaxResponse::json(self::readResult($action));
    }

    /**
     * @param mixed $exists the row findBySlug() returned
     */
    private static function slugTaken(mixed $exists, string $key): void
    {
        if (isset($exists['s_slug'])) {
            AjaxResponse::json(array('error' => 1, $key => $exists));
        } else {
            AjaxResponse::json(array('error' => 0));
        }
    }

    /**
     * @return array<string,mixed> the result, or ['error' => message]
     */
    private static function readResult(string $action): array
    {
        $query = new LocationAdminQuery();
        // Values are bound, never rendered, so they are read without the tag filter.
        $level = (string) Params::getParamString('level', false, false, false);

        try {
            switch ($action) {
                case 'location_search':
                    return $query->searchAll(
                        LocationAdminView::search((string) Params::getParamString('q', false, false, false), 'all')['q'],
                        Params::getParamInt('per', 10)
                    );
                case 'location_impact':
                    // id[] for a selection, id for one row.
                    $ids = Params::getParamArray('id', false, false, false);
                    if ($ids === array() && Params::getParamString('id', false, false, false) !== '') {
                        $ids = array(Params::getParamString('id', false, false, false));
                    }
                    if ($ids === array()) {
                        return array('error' => __('No locations selected'));
                    }
                    $impact = $query->impact($level, $ids);
                    if ($impact['found'] < $impact['requested']) {
                        return array('error' => __('Some of the selected locations no longer exist'));
                    }

                    return $impact;
                default:
                    $record = $query->record($level, Params::getParamString('id', false, false, false));

                    return $record ?? array('error' => __('Location not found'));
            }
        } catch (InvalidArgumentException $e) {
            switch ($e->getCode()) {
                case LocationAdminQuery::ERR_BAD_ID:
                    return array('error' => __('Invalid location id'));
                case LocationAdminQuery::ERR_TOO_MANY:
                    return array('error' => __('Too many locations selected'));
                default:
                    return array('error' => __('Unknown location level'));
            }
        } catch (DbException $e) {
            return array('error' => __('Locations could not be read'));
        }
    }
}
