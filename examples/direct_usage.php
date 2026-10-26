<?php

declare(strict_types=1);

/*
 * Using HypCurl directly inside your existing classes, without writing an ApiClient subclass.
 * Run: php examples/direct_usage.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Hyphun\HypCurl\Http\HttpClient;
use Hyphun\HypCurl\Http\HttpException;
use Hyphun\HypCurl\HypCurl;

/**
 * An ordinary class from your app (a service, controller, job, ...).
 * It keeps one configured client in a property and calls it from its methods.
 */
final class BlogService
{
    private HttpClient $api;

    public function __construct()
    {
        $this->api = HypCurl::client([
            'baseUrl' => 'https://jsonplaceholder.typicode.com',
            'headers' => ['X-App' => 'blog-service'],   // e.g. 'Authorization' => 'Bearer ' . $token
            'retries' => 2,
            'timeout' => 15,
        ]);
    }

    /** @return list<string> */
    public function titlesForUser(int $userId): array
    {
        $posts = $this->api->get('/posts')->query(['userId' => $userId])->send()->json();

        return array_column($posts, 'title');
    }

    public function publish(string $title, string $body): int
    {
        return $this->api->post('/posts')
            ->json(['title' => $title, 'body' => $body, 'userId' => 1])
            ->send()
            ->json('id');
    }

    public function exists(int $postId): bool
    {
        try {
            $this->api->get("/posts/$postId")->send();

            return true;
        } catch (HttpException $e) {
            if ($e->response?->status === 404) {
                return false;
            }
            throw $e;   // network error, 5xx after retries, ...
        }
    }
}

$blog = new BlogService();
echo 'User 1 has ' . count($blog->titlesForUser(1)) . " posts\n";
echo 'Published post id ' . $blog->publish('Hello', 'From HypCurl') . "\n";
echo 'Post 99999 exists? ' . var_export($blog->exists(99999), true) . "\n";

// One-off calls with a full URL: no client to set up at all.
$ip = HypCurl::get('https://httpbin.org/ip')->send()->json('origin');
echo "My IP: $ip\n";

$echo = HypCurl::post('https://httpbin.org/post')
    ->json(['hello' => 'world'])
    ->header('X-Request-Id', 'r-1')
    ->send();
echo 'httpbin received: ' . json_encode($echo->json('json')) . "\n";

// The shared client keeps cookies between HypCurl::* calls, like a browser.
HypCurl::get('https://httpbin.org/cookies/set/session/abc123')->send();
echo 'Cookies sent back: ' . json_encode(HypCurl::get('https://httpbin.org/cookies')->send()->json('cookies')) . "\n";
