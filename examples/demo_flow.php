<?php

declare(strict_types=1);

/*
 * Example of a multi-step flow: compose service calls in a script instead of the CLI.
 * Run: php examples/demo_flow.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Hyphun\HypCurl\Core\Logger;
use Hyphun\HypCurl\Http\HttpException;
use Hyphun\HypCurl\Examples\JsonPlaceholder\JsonPlaceholder;

$api = JsonPlaceholder::instance();
$log = Logger::instance();

try {
    // 1. Find a user
    $user = $api->users()->find(1)->json();
    $log->info("User: {$user['name']} <{$user['email']}>");

    // 2. Their posts
    $posts = $api->posts()->byUser($user['id'])->json();
    $log->info(sprintf('%s has %d posts', $user['name'], count($posts)));

    // 3. Comments on their first post
    $firstPost = $posts[0];
    $comments = $api->posts()->comments($firstPost['id'])->json();
    $log->info(sprintf('Post #%d "%s" has %d comments', $firstPost['id'], $firstPost['title'], count($comments)));

    // 4. Create a new post
    $created = $api->posts()->create([
        'userId' => $user['id'],
        'title' => 'Hello from HypCurl',
        'body' => 'Created by examples/demo_flow.php',
    ]);
    $log->info("Created post id={$created->json('id')} (HTTP $created->status)");

    // 5. Same singleton everywhere — no re-configuration needed
    assert(JsonPlaceholder::instance() === $api);
} catch (HttpException $e) {
    $log->error($e->getMessage());
    if ($e->response !== null) {
        fwrite(STDERR, $e->response->pretty() . "\n");
    }
    exit(1);
}
