<?php /** @noinspection PhpIncludeInspection */
    /*
     * Shopclass - Free and open source theme for Osclass with premium features
     * Maintained and supported by Mindstellar Community
     * https://github.com/mindstellar/shopclass
     * Copyright (c) 2021.  Mindstellar
     *
     * This program is free software: you can redistribute it and/or modify
     * it under the terms of the GNU General Public License as published by
     * the Free Software Foundation, either version 3 of the License, or
     * (at your option) any later version.
     *
     * This program is distributed in the hope that it will be useful,
     * but WITHOUT ANY WARRANTY; without even the implied warranty of
     * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
     *
     *                     GNU GENERAL PUBLIC LICENSE
     *                        Version 3, 29 June 2007
     *
     *  Copyright (C) 2007 Free Software Foundation, Inc. <http://fsf.org/>
     *  Everyone is permitted to copy and distribute verbatim copies
     *  of this license document, but changing it is not allowed.
     *
     *  You should have received a copy of the GNU Affero General Public
     *  License along with this program. If not, see <http://www.gnu.org/licenses/>.
     *
     */

	/**
	 * Register the autoloader for Hybridauth classes.
	 *
	 * Based off the official PSR-4 autoloader example found at
	 * http://www.php-fig.org/psr/psr-4/examples/
	 *
	 * @param string $class The fully-qualified class name.
	 *
	 * @return void
	 */
	spl_autoload_register(
		function ($class) {
			// project-specific namespace prefix. Will only kicks in for shopclass's namespace.
			$prefix = 'shopclass\\';

			// base directory for the namespace prefix.
			$base_dir = tfc_path();   // By default, it points to this same folder.
			// You may change this path if having trouble detecting the path to
			// the source files.

			// does the class use the namespace prefix?
			$len = strlen($prefix);
			if (strncmp($prefix, $class, $len) !== 0) {
				// no, move to the next registered autoloader.
				return;
			}

			// get the relative class name.
			$relative_class = substr($class, $len);

			// replace the namespace prefix with the base directory, replace namespace
			// separators with directory separators in the relative class name, append
			// with .php
			$file = $base_dir.DIRECTORY_SEPARATOR.str_replace('\\', DIRECTORY_SEPARATOR, $relative_class).'.php';

			// if the file exists, require it
			if (file_exists($file)) {
				require $file;
			}
		}
	);