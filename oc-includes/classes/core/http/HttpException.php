<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace mindstellar\http;

/**
 * No answer came back, or it was too large. The message never holds the URL's path, query
 * string or the request headers, since API keys travel there.
 */
class HttpException extends \RuntimeException
{
}
