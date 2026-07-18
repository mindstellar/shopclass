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
 * Enqueued dependiences class.
 *
 * @since 3.1.1
 */
class Dependencies
{

    public $registered;
    public $queue;

    public $resolved = array();
    public $unresolved = array();
    public $error = array();

    public function __construct()
    {
        $this->registered = array();
        $this->queue      = array();
    }

    /**
     * Register url to be loaded
     *
     * @param $id
     * @param $url
     * @param $dependencies mixed, it could be an array or a string
     */
    public function register($id, $url, $dependencies)
    {
        if ($id && $url) {
            $this->registered[$id] = array(
                'key'          => $id,
                'url'          => $url,
                'dependencies' => $dependencies
            );
        }
    }

    /**
     * Remove url to not be loaded
     *
     * @param $id
     */
    public function unregister($id)
    {
        unset($this->registered[$id]);
    }

    /**
     * Try to order all script having in mind their dependencies
     */
    public function order()
    {
        $this->resolved   = array();
        $this->unresolved = array();
        $this->error      = array();

        foreach ($this->queue as $queue) {
            if (isset($this->registered[$queue])) {
                $node = $this->registered[$queue];
                if ($node['dependencies'] == null) {
                    $this->resolved[$node['key']] = $node['key'];
                } else {
                    $this->solveDeps($node);
                }
            } else {
                $this->error[$queue] = $queue;
            }
        }
        if (!empty($this->error)) {
            echo sprintf(__('ERROR: Some dependencies could not be loaded (%s)'), implode(', ', $this->error));
        }
    }

    /**
     * Algorithm to solve the dependencies of the scripts
     *
     * @param $node
     */
    private function solveDeps($node)
    {
        $error = false;
        if (!isset($this->resolved[$node['key']])) {
            $this->unresolved[$node['key']] = $node['key'];
            if ($node['dependencies'] != null) {
                if (is_array($node['dependencies'])) {
                    foreach ($node['dependencies'] as $dep) {
                        if (!in_array($dep, $this->resolved)) {
                            if (in_array($dep, $this->unresolved)) {
                                $this->error[$dep] = $dep;
                                $error             = true;
                            } elseif (isset($this->registered[$dep])) {
                                $this->solveDeps($this->registered[$dep]);
                            } else {
                                $this->error[$dep] = $dep;
                            }
                        }
                    }
                } elseif (!in_array($node['dependencies'], $this->resolved)) {
                    if (in_array($node['dependencies'], $this->unresolved)) {
                        $this->error[$node['dependencies']] = $node['dependencies'];
                        $error                              = true;
                    } elseif (isset($this->registered[$node['dependencies']])) {
                        $this->solveDeps($this->registered[$node['dependencies']]);
                    } else {
                        $this->error[$node['dependencies']] = $node['dependencies'];
                    }
                }
            }
            if (!$error) {
                $this->resolved[$node['key']] = $node['key'];
                unset($this->unresolved[$node['key']]);
            }
        }
    }
}
