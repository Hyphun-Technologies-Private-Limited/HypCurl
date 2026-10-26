<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Examples\JsonPlaceholder;

use Hyphun\HypCurl\Api\CrudResource;
use Hyphun\HypCurl\Http\Response;

/** /posts — inherits list/find/create/update/patch/delete, adds post-specific endpoints. */
final class Posts extends CrudResource
{
    /** GET /posts/{id}/comments */
    public function comments(int $postId): Response
    {
        return $this->api->get($this->url($postId, 'comments'))->send();
    }

    /** GET /posts?userId={id} */
    public function byUser(int $userId): Response
    {
        return $this->list(['userId' => $userId]);
    }
}
