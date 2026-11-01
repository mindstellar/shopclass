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

namespace mindstellar\image;

/**
 * An open image: the engine's own object (a GdImage or an Imagick) with its size.
 * Only the engine that opened it reads $native.
 */
final class Image
{
    /** What turns each EXIF orientation upright: degrees to rotate clockwise, then whether to flip left to right. */
    private const UPRIGHT = [
        2 => [0, true],
        3 => [180, false],
        4 => [180, true],
        5 => [90, true],
        6 => [90, false],
        7 => [270, true],
        8 => [270, false],
    ];

    /**
     * @param string $mime        the MIME type of the file it was read from
     * @param int    $orientation the EXIF orientation still to apply, 1 when upright
     */
    public function __construct(
        public readonly object $native,
        public readonly int $width,
        public readonly int $height,
        public readonly string $mime,
        public readonly int $orientation = 1
    ) {
    }

    /**
     * The steps that turn this image upright, null when it already is.
     *
     * @return array{0:int,1:bool}|null degrees to rotate clockwise, then whether to flip left to right
     */
    public function upright(): ?array
    {
        return self::UPRIGHT[$this->orientation] ?? null;
    }

    /**
     * The same image after an engine changed its pixels.
     */
    public function with(object $native, int $width, int $height, int $orientation = 1): self
    {
        return new self($native, $width, $height, $this->mime, $orientation);
    }
}
