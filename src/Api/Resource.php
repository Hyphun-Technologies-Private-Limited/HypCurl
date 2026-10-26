<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Api;

/**
 * A group of endpoints under one path, e.g. "/posts".
 */
abstract class Resource
{
    public function __construct(
        protected ApiClient $api,
        protected string $path,
    ) {
    }

    /** Build "/base/seg1/seg2" with each segment URL-encoded. */
    protected function url(string|int ...$segments): string
    {
        $encoded = array_map(fn ($s) => rawurlencode((string) $s), $segments);

        return rtrim($this->path . '/' . implode('/', $encoded), '/');
    }
}
