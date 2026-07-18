<?php

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

/**
 * This class dynamically creates a XML Sitemap ready to send to Google, Yahoo and others.
 *
 * @author  Shopclass
 */
class Sitemap
{

    private $urls;
    private $validFrequencies = array('always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never');

    public function __construct()
    {
        $this->urls = array();
    }

    /**
     * @param        $loc
     * @param string $changeFreq
     * @param float  $priority
     * @param null   $lastMod
     */
    public function addURL($loc, $changeFreq = 'daily', $priority = 0.7, $lastMod = null)
    {
        $this->urls[] = array(
            'loc'        => $loc,
            'lastMod'    => $lastMod,
            'changeFreq' => $changeFreq,
            'priority'   => $priority
        );
    }

    public function toStdout()
    {
        header('Content-type: text/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?>', PHP_EOL;
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', PHP_EOL;

        foreach ($this->urls as $url) {
            echo '<url>', PHP_EOL;
            echo '<loc>', $url['loc'], '</loc>', PHP_EOL;
            echo '<lastmod>', $url['lastMod'], '</lastmod>', PHP_EOL;
            echo '<changefreq>', $url['changeFreq'], '</changefreq>', PHP_EOL;
            echo '<priority>', $url['priority'], '</priority>', PHP_EOL;
            echo '</url>', PHP_EOL;
        }

        echo '</urlset>', PHP_EOL;
    }
}
