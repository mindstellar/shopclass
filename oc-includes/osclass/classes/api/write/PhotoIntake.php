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

namespace mindstellar\api\write;

use mindstellar\api\ProblemException;
use mindstellar\api\ratelimit\RateLimiter;
use mindstellar\api\ratelimit\RatePolicy;
use mindstellar\api\Request;

/**
 * Where a listing's photos come from: one uploaded file, staged photos named by token, or
 * public URLs the site downloads. Each is checked as the listing form checks its photos.
 */
final class PhotoIntake
{
    /**
     * @param bool $urls     whether the site downloads photos named by URL
     * @param int  $maxBytes the largest photo the site takes
     */
    public function __construct(
        private PhotoStage $stage,
        private ImageFetcher $fetcher,
        private RateLimiter $limiter,
        private RatePolicy $limits,
        private bool $urls,
        private int $maxBytes
    ) {
    }

    /**
     * The photo a request carries, checked, in the temp folder.
     *
     * @throws ProblemException 413, 415 or 422
     */
    public function upload(Request $request): PhotoFile
    {
        return PhotoFile::fromRequest($request, $this->stage, $this->maxBytes);
    }

    /**
     * Keep an uploaded photo for a listing not made yet.
     *
     * @throws ProblemException 422 when the user holds as many as they may, 500 when it cannot be stored
     */
    public function stage(int $userId, PhotoFile $photo): PhotoFile
    {
        try {
            return $this->stage->stage($userId, $photo);
        } catch (\OverflowException $e) {
            throw ProblemException::field('/photo', 'limit', 'cannot be kept: ' . PhotoStage::MAX_PENDING . ' photos are already waiting for a listing');
        } catch (\RuntimeException $e) {
            throw PhotoFile::notStored();
        } finally {
            $photo->discard();
        }
    }

    /**
     * The photos a listing body names in `photo_tokens` and `photo_urls`. URLs are fetched only
     * while the listing has room and within the user's hourly fetch limit, a few at a time.
     *
     * @param array<mixed> $input the listing body
     * @param int|null     $room  photos the listing can still take; null for no limit
     *
     * @throws ProblemException 422 for an unknown token, a URL when the site does not fetch them or
     *                          a bad photo; 429 past the fetch limit; 500 when a staged photo cannot be copied
     */
    public function batch(array $input, int $userId, ?int $room): PhotoBatch
    {
        $tokens = array_values(array_map('strval', (array) ($input['photo_tokens'] ?? [])));
        $staged = $tokens === [] ? [] : $this->stage->staged($userId, $tokens);
        foreach ($tokens as $i => $token) {
            if (!isset($staged[$token])) {
                throw ProblemException::field('/photo_tokens/' . $i, 'invalid', 'is unknown or has expired');
            }
        }
        $urls = array_values(array_map('strval', (array) ($input['photo_urls'] ?? [])));
        if ($urls !== [] && !$this->urls) {
            throw ProblemException::field('/photo_urls', 'invalid', 'is switched off on this site; upload to /photos and send photo_tokens');
        }
        $staged = array_values($staged);
        $left   = $room === null ? count($urls) : max(0, min(count($urls), $room - count($staged)));
        $wanted = array_slice($urls, 0, $left);
        $copies = [];
        try {
            foreach ($staged as $photo) {
                $copies[] = $this->copy($photo);
            }
            foreach ($wanted as $url) {
                $this->limiter->enforce($this->limits->photoFetch($userId), 'Too many photos fetched by URL in an hour. Try again later.');
            }
            $fetched = $wanted === [] ? [] : $this->fetch($wanted);
        } catch (ProblemException $e) {
            (new PhotoBatch([], [], 0, $copies))->discard(false);

            throw $e;
        }

        return new PhotoBatch($staged, $fetched, count($urls) - $left, $copies);
    }

    /**
     * After the listing was saved, or not: forget the tokens it used and remove what is left.
     */
    public function finish(PhotoBatch $batch, int $userId, bool $saved): void
    {
        if ($saved && $batch->tokens() !== []) {
            $this->stage->forget($userId, $batch->tokens());
        }
        $batch->discard($saved);
    }

    /**
     * A copy of a staged photo, for a listing save to take.
     *
     * @throws ProblemException 500 when it cannot be copied
     */
    private function copy(PhotoFile $photo): PhotoFile
    {
        $extension = strtolower(pathinfo($photo->path(), PATHINFO_EXTENSION));
        $file      = $this->stage->tempPath($extension !== '' ? $extension : 'jpg');
        if (!@copy($photo->path(), $file)) {
            @unlink($file);

            throw ProblemException::of('server_error', 'A staged photo could not be read.');
        }

        return new PhotoFile($file, $extension);
    }

    /**
     * Download the URLs side by side and check each as a photo.
     *
     * @param string[] $urls
     *
     * @return PhotoFile[]
     * @throws ProblemException 422 at the first URL that is refused or failed, else at the first that is not a usable photo
     */
    private function fetch(array $urls): array
    {
        $files  = array_map(fn (): string => $this->stage->tempPath('fetch'), $urls);
        $errors = $this->fetcher->fetchAll($urls, $files, $this->maxBytes);
        $photos = [];
        try {
            $failed = array_key_first(array_filter($errors));
            if ($failed !== null) {
                throw ProblemException::field('/photo_urls/' . $failed, 'invalid', rtrim(lcfirst((string) $errors[$failed]), '.'));
            }
            foreach ($files as $i => $file) {
                $photos[] = PhotoFile::checked($file, $this->maxBytes, '/photo_urls/' . $i);
            }
        } catch (ProblemException $e) {
            foreach ($photos as $photo) {
                $photo->discard();
            }
            foreach ($files as $file) {
                @unlink($file);
            }

            throw $e;
        }

        return $photos;
    }
}
