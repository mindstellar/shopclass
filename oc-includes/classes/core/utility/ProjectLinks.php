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

namespace mindstellar\utility;

/**
 * Every Shopclass project link core shows to people, in one place. Do not type these URLs
 * anywhere else; tests/project-links.php fails if one is.
 *
 * @package mindstellar\utility
 */
final class ProjectLinks
{
    public const DOCS = 'https://mindstellar.com/docs/';

    public const INSTALL = 'https://mindstellar.com/docs/install/';

    public const FORUM = 'https://github.com/mindstellar/shopclass/discussions';

    public const ISSUES = 'https://github.com/mindstellar/shopclass/issues';

    public const REPOSITORY = 'https://github.com/mindstellar/shopclass';

    public const RELEASES = 'https://github.com/mindstellar/shopclass/releases';

    public const API_CHANGELOG = 'https://mindstellar.com/docs/developers/api/changelog/';

    /** Add the error code after it. */
    public const API_ERRORS = 'https://mindstellar.com/docs/developers/api/errors/#';

    public const CRON = 'https://mindstellar.com/docs/configure/cron/';

    public const SECURITY_PROXY = 'https://mindstellar.com/docs/deploy/security/#login-throttling-and-the-real-client-ip';

    public const SECURITY_BACKUPS = 'https://mindstellar.com/docs/deploy/security/#the-backups-folder';

    public const SECURITY_BACKUPS_BUCKET = 'https://mindstellar.com/docs/deploy/security/#backups-in-a-bucket';

    public const DOCKER_LIVE = 'https://mindstellar.com/docs/deploy/docker/#live-messages';

    public const DOCKER_PUSH = 'https://mindstellar.com/docs/deploy/docker/#web-push';

    public const DOCKER_CONFIG = 'https://mindstellar.com/docs/deploy/docker/#configuration';
}
