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
 * The pixel work one image library does. Processor holds the rules shared by every engine.
 * An operation may change the Image it is given, so keep using the one it returns.
 */
interface Engine
{
    /**
     * Whether opening the file would decode more than $max pixels, or more than this engine can hold in memory.
     *
     * @param array<int|string,mixed> $info getimagesize() of the file
     */
    public function tooLarge(string $path, array $info, int $max): bool;

    /**
     * Decode the first frame of the file.
     *
     * @param array<int|string,mixed> $info getimagesize() of the file
     *
     * @throws \RuntimeException when it cannot be decoded
     */
    public function open(string $path, array $info): Image;

    /**
     * Turn the image upright from its EXIF orientation. Use the returned Image.
     */
    public function orient(Image $image): Image;

    /**
     * Scale the image to $width x $height and place it at $x,$y on a see-through canvas. Use the returned Image.
     */
    public function fit(Image $image, int $width, int $height, int $canvasWidth, int $canvasHeight, int $x, int $y): Image;

    /**
     * Draw $overlay over the image at each top-left spot, its alpha multiplied by $opacity percent. Use the returned Image.
     *
     * @param list<array{0:int,1:int}> $spots
     */
    public function composite(Image $image, Image $overlay, array $spots, int $opacity): Image;

    /**
     * Write the image without metadata. A JPEG gets white where the image is see-through.
     *
     * @param string $format 'jpeg', 'png', 'gif' or 'webp'
     *
     * @return bool false, with a warning, when the file could not be written
     */
    public function encode(Image $image, string $path, string $format, int $quality, int $compression): bool;

    /**
     * Whether this engine can write the format.
     */
    public function canWrite(string $format): bool;

    /**
     * A copy with its own pixels.
     */
    public function copy(Image $image): Image;

    /**
     * Release the image's memory now.
     */
    public function free(Image $image): void;
}
