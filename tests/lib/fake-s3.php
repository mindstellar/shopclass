<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * An in-memory S3 client with the async-aws method names S3Storage calls. It records every
 * call with its parameters, and $before may throw to fail one.
 *
 * Usage:  require_once __DIR__ . '/lib/fake-s3.php';  new S3Storage(['client' => new FakeS3Client(), ...])
 */

/** A result: resolve() and getX() read from its data. */
final class FakeS3Result
{
    /** @var array<string,mixed> */
    private $data;

    public function __construct(array $data = array())
    {
        $this->data = $data;
    }

    public function resolve(): bool
    {
        return true;
    }

    public function isSuccess(): bool
    {
        return (bool) ($this->data['success'] ?? true);
    }

    public function __call(string $name, array $args)
    {
        return $this->data[lcfirst(substr($name, 3))] ?? null;
    }
}

final class FakeS3Client
{
    /** @var array<int,array{op:string,params:array<string,mixed>,body:?string}> */
    public $calls = array();

    /** @var array<string,string> key => bytes */
    public $objects = array();

    /** @var array<string,array<int,string>> upload id => part number => bytes */
    public $uploads = array();

    /** @var callable|null fn(string $op, array $params): void, may throw */
    public $before;

    /** @var int|null a size headObject reports instead of the real one */
    public $lieAboutSize;

    /**
     * The calls of one operation.
     *
     * @param string $op
     *
     * @return array<int,array{op:string,params:array<string,mixed>,body:?string}>
     */
    public function calls(string $op): array
    {
        return array_values(array_filter($this->calls, static function (array $c) use ($op) {
            return $c['op'] === $op;
        }));
    }

    private function record(string $op, array $params): ?string
    {
        if ($this->before !== null) {
            ($this->before)($op, $params);
        }
        $body = null;
        if (array_key_exists('Body', $params)) {
            $body = is_resource($params['Body']) ? (string) stream_get_contents($params['Body'], -1, 0) : (string) $params['Body'];
            $params['Body'] = is_resource($params['Body']) ? 'resource' : 'string';
        }
        $this->calls[] = array('op' => $op, 'params' => $params, 'body' => $body);

        return $body;
    }

    public function putObject(array $p): FakeS3Result
    {
        $this->objects[$p['Key']] = (string) $this->record('putObject', $p);

        return new FakeS3Result(array('etag' => '"' . md5($this->objects[$p['Key']]) . '"'));
    }

    public function createMultipartUpload(array $p): FakeS3Result
    {
        $this->record('createMultipartUpload', $p);
        $id = 'up' . count($this->uploads);
        $this->uploads[$id] = array();

        return new FakeS3Result(array('uploadId' => $id));
    }

    public function uploadPart(array $p): FakeS3Result
    {
        $body = (string) $this->record('uploadPart', $p);
        $this->uploads[$p['UploadId']][(int) $p['PartNumber']] = $body;

        return new FakeS3Result(array('etag' => '"' . md5($body) . '"'));
    }

    public function completeMultipartUpload(array $p): FakeS3Result
    {
        $this->record('completeMultipartUpload', $p);
        $bytes = '';
        foreach ($p['MultipartUpload']['Parts'] as $part) {
            $bytes .= $this->uploads[$p['UploadId']][$part['PartNumber']];
        }
        $this->objects[$p['Key']] = $bytes;
        unset($this->uploads[$p['UploadId']]);

        return new FakeS3Result();
    }

    public function abortMultipartUpload(array $p): FakeS3Result
    {
        $this->record('abortMultipartUpload', $p);
        unset($this->uploads[$p['UploadId']]);

        return new FakeS3Result();
    }

    public function headObject(array $p): FakeS3Result
    {
        $this->record('headObject', $p);
        if (!isset($this->objects[$p['Key']])) {
            throw new RuntimeException('Not found');
        }

        return new FakeS3Result(array(
            'contentLength' => $this->lieAboutSize ?? strlen($this->objects[$p['Key']]),
            'etag'          => '"' . md5($this->objects[$p['Key']]) . '"',
        ));
    }

    public function objectExists(array $p): FakeS3Result
    {
        $this->record('objectExists', $p);

        return new FakeS3Result(array('success' => isset($this->objects[$p['Key']])));
    }

    public function getObject(array $p): FakeS3Result
    {
        $this->record('getObject', $p);
        if (!isset($this->objects[$p['Key']])) {
            throw new RuntimeException('Not found');
        }
        $bytes = $this->objects[$p['Key']];
        if (($p['IfMatch'] ?? null) !== null && $p['IfMatch'] !== '"' . md5($bytes) . '"') {
            throw new RuntimeException('Precondition failed');
        }
        if (isset($p['Range']) && preg_match('/^bytes=(\d+)-(\d+)$/', $p['Range'], $m)) {
            $bytes = substr($bytes, (int) $m[1], (int) $m[2] - (int) $m[1] + 1);
        }

        return new FakeS3Result(array('body' => new class ($bytes) {
            /** @var string */
            private $bytes;

            public function __construct(string $bytes)
            {
                $this->bytes = $bytes;
            }

            public function getChunks(): iterable
            {
                foreach (str_split($this->bytes, 7) as $chunk) {
                    yield $chunk;
                }
            }

            public function getContentAsString(): string
            {
                return $this->bytes;
            }
        }));
    }

    public function listObjectsV2(array $p): FakeS3Result
    {
        $this->record('listObjectsV2', $p);
        $rows = array();
        foreach ($this->objects as $key => $bytes) {
            if (strpos($key, (string) ($p['Prefix'] ?? '')) === 0) {
                $rows[] = new FakeS3Result(array('key' => $key, 'size' => strlen($bytes), 'lastModified' => new DateTimeImmutable()));
            }
        }

        return new FakeS3Result(array('contents' => $rows));
    }

    public function deleteObjects(array $p): FakeS3Result
    {
        $this->record('deleteObjects', $p);
        foreach ($p['Delete']['Objects'] as $object) {
            unset($this->objects[$object['Key']]);
        }

        return new FakeS3Result(array('errors' => array()));
    }

    public function deleteObject(array $p): FakeS3Result
    {
        $this->record('deleteObject', $p);
        unset($this->objects[$p['Key']]);

        return new FakeS3Result();
    }

    public function presign($input, DateTimeImmutable $expires): string
    {
        $this->calls[] = array('op' => 'presign', 'params' => array('Key' => $input->getKey(), 'expires' => $expires->getTimestamp()), 'body' => null);

        return 'https://bucket.example/' . $input->getKey() . '?X-Amz-Signature=secret';
    }
}

/* file end: ./tests/lib/fake-s3.php */
