<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Api;

use Hyphun\HypCurl\Http\Response;

/**
 * Standard REST endpoints for free. Any typical resource needs zero code:
 *   $this->resource(CrudResource::class, '/users')
 * Extend it when a resource has extra endpoints (see Examples\JsonPlaceholder\Posts).
 */
class CrudResource extends Resource
{
    /** GET /path?filters */
    public function list(array $filters = []): Response
    {
        return $this->api->get($this->url())->query($filters)->send();
    }

    /** GET /path/{id} */
    public function find(int|string $id): Response
    {
        return $this->api->get($this->url($id))->send();
    }

    /** POST /path */
    public function create(array $data): Response
    {
        return $this->api->post($this->url())->json($data)->send();
    }

    /** PUT /path/{id} */
    public function update(int|string $id, array $data): Response
    {
        return $this->api->put($this->url($id))->json($data)->send();
    }

    /** PATCH /path/{id} */
    public function patch(int|string $id, array $data): Response
    {
        return $this->api->patch($this->url($id))->json($data)->send();
    }

    /** DELETE /path/{id} */
    public function delete(int|string $id): Response
    {
        return $this->api->delete($this->url($id))->send();
    }
}
