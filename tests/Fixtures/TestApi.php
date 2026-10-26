<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Tests\Fixtures;

use Hyphun\HypCurl\Api\ApiClient;
use Hyphun\HypCurl\Api\CrudResource;
use Hyphun\HypCurl\Http\Response;

final class TestApi extends ApiClient
{
    protected function options(): array
    {
        return ['baseUrl' => HYPCURL_TEST_SERVER, 'retryDelay' => 10];
    }

    public function items(): CrudResource
    {
        return $this->resource(CrudResource::class, '/items');
    }

    /** Echo with query filters. */
    public function search(string $term, array $filters = []): Response
    {
        return $this->get('/search')->query(['q' => $term] + $filters)->send();
    }
}
