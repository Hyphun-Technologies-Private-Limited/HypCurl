<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Examples\JsonPlaceholder;

use Hyphun\HypCurl\Api\ApiClient;
use Hyphun\HypCurl\Api\CrudResource;
use Hyphun\HypCurl\Core\Config;

/**
 * https://jsonplaceholder.typicode.com — free fake REST API for practice.
 *
 *   $api = JsonPlaceholder::instance();
 *   $api->posts()->list(['userId' => 1]);
 *   $api->posts()->comments(1);
 *   $api->users()->find(3);
 */
final class JsonPlaceholder extends ApiClient
{
    protected function options(): array
    {
        return [
            'baseUrl' => Config::instance()->get('JSONPH_BASE_URL', 'https://jsonplaceholder.typicode.com'),
            'retries' => 2,
        ];
    }

    public function posts(): Posts
    {
        return $this->resource(Posts::class, '/posts');
    }

    /** Plain CRUD resources need no class of their own. */
    public function users(): CrudResource
    {
        return $this->resource(CrudResource::class, '/users');
    }

    public function todos(): CrudResource
    {
        return $this->resource(CrudResource::class, '/todos');
    }

    public function comments(): CrudResource
    {
        return $this->resource(CrudResource::class, '/comments');
    }
}
