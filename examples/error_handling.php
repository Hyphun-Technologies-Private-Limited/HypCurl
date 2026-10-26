<?php

declare(strict_types=1);

/*
 * Handling errors: typed exceptions, trySend(), and JSON output.
 * Run: php examples/error_handling.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Hyphun\HypCurl\Http\ClientException;
use Hyphun\HypCurl\Http\HttpException;
use Hyphun\HypCurl\Http\NetworkException;
use Hyphun\HypCurl\Http\ProxyException;
use Hyphun\HypCurl\Http\ServerException;
use Hyphun\HypCurl\Http\SslException;
use Hyphun\HypCurl\Http\TimeoutException;
use Hyphun\HypCurl\HypCurl;

HypCurl::boot(['values' => ['HYPCURL_LOG_LEVEL' => 'OFF']]);

// 1. Catch by type (all extend HttpException, so one catch can handle everything).
function fetchUser(int $id): array
{
    try {
        return HypCurl::get("https://jsonplaceholder.typicode.com/users/$id")->send()->json();
    } catch (ClientException $e) {            // 4xx
        if ($e->type === 'not_found') {
            return [];
        }
        throw $e;
    } catch (TimeoutException $e) {           // took too long
        echo "Timed out, try later\n";
        return [];
    } catch (ProxyException | SslException $e) {
        echo "Config problem: {$e->reason}\n   hint: {$e->hint}\n";
        return [];
    } catch (ServerException | NetworkException $e) {   // 5xx, DNS, refused, reset, ...
        echo "Upstream down: {$e->reason}\n";
        return [];
    }
}
echo 'User 1: ' . (fetchUser(1)['name'] ?? '-') . "\n";
echo 'User 999: ' . json_encode(fetchUser(999)) . "\n\n";

// 2. trySend(): never throws. Check ok(), or return toArray() as your own API's JSON.
$res = HypCurl::get('https://jsonplaceholder.typicode.com/posts/99999')->trySend();
if (!$res->ok()) {
    echo "trySend failed with type '{$res->error->type}' and status {$res->status}\n";
}
echo "Public JSON (safe for end users):\n" . $res->toJson(debug: false) . "\n\n";

// 3. Any exception to JSON, with full details for logs / developers.
try {
    HypCurl::get('http://no-such-host.invalid/api')->send();
} catch (HttpException $e) {
    echo "Debug JSON:\n" . $e->toJson() . "\n\n";
    // error_log($e->toJson(flags: 0));  // one-line JSON for log files
}

// 4. Successful responses use the same envelope.
echo "Success envelope:\n" . HypCurl::get('https://jsonplaceholder.typicode.com/todos/1')->send()->toJson() . "\n";

// 5. In a web app, add this once at startup: any UNCAUGHT HypCurl error then becomes a
//    502/504 JSON response instead of a "Fatal error: Uncaught ..." page.
//    HypCurl::boot(['json_errors' => true]);                    // production
//    HypCurl::boot(['json_errors' => ['debug' => true]]);       // development: adds hint, URL, curl + upstream body
