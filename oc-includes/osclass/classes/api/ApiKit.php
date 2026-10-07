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

namespace mindstellar\api;

use mindstellar\api\read\ListingReader;
use mindstellar\api\serializer\Links;
use mindstellar\api\serializer\ViewContext;

/**
 * What a plugin handler may use of core's API services, through ApiCall::kit(): the
 * listing reader, the site's links and the caller's view context.
 */
final class ApiKit
{
    public function __construct(private ApiServices $services)
    {
    }

    /**
     * The view context for this call: the caller, version, `locale`, `fields` and `include`.
     *
     * @param string   $object   listing, user or category
     * @param string[] $members  the object's top-level members
     * @param string[] $includes the names `include=` may switch on
     *
     * @throws ProblemException 400 or 422 for an unknown field, include or locale
     *
     * @api
     */
    public function context(ApiCall $call, string $object, array $members, array $includes = []): ViewContext
    {
        return $this->services->context($call->request(), $call->credential(), $object, $members, $includes);
    }

    /**
     * Core's listing reader. It does not check who may see a listing: apply the site's
     * visibility rules before answering with one.
     *
     * @api
     */
    public function listings(): ListingReader
    {
        return $this->services->listingReader();
    }

    /** @api */
    public function links(): Links
    {
        return $this->services->links();
    }
}
