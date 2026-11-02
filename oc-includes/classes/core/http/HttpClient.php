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
 * Sends one outgoing HTTP request. Tests swap in a fake, so callers never need the network.
 */
interface HttpClient
{
    /**
     * @param string               $method  GET, POST, PUT, PATCH, DELETE or HEAD
     * @param array<string,string> $headers name => value
     * @param array<string,mixed>  $options connect_timeout and timeout in seconds, max_bytes for the answer
     *
     * @return array{status:int,headers:array<string,string>,body:string} header names lower-cased
     * @throws HttpException when no answer came back, or it was larger than max_bytes
     * @throws \InvalidArgumentException for a URL that is not HTTPS, or a bad header or option
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null, array $options = []): array;
}
