<?php

declare(strict_types=1);

/*
 * Chained requests that share cookies.
 * Run: php examples/cookie_flow.php
 *
 * A real "login then call a protected page" flow looks exactly like step 1 + 2:
 *   $api->post('/login')->form(['user' => $u, 'pass' => $p])->send();   // server sets session cookie
 *   $api->get('/dashboard')->send();                                     // cookie sent automatically
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Hyphun\HypCurl\Core\Logger;
use Hyphun\HypCurl\Http\HttpException;
use Hyphun\HypCurl\Examples\HttpBin\HttpBin;

$api = HttpBin::instance();
$log = Logger::instance();

try {
    // 1. First request: the server responds with Set-Cookie (like a login).
    $login = $api->setCookies(['session' => 'abc123', 'theme' => 'dark']);
    $log->info('Server set cookies: ' . json_encode($login->cookies));

    // 2. Second request: the same client automatically sends those cookies back.
    $check = $api->cookies();
    $log->info('Server received cookies: ' . json_encode($check->json('cookies')));

    // 3. Read one cookie from response 1 and pass it by hand, plus an extra one-off cookie.
    //    Useful when the cookie must go to a different API/client.
    $manual = $api->get('/cookies')
        ->cookie('forwarded_session', $login->cookie('session'))
        ->send();
    $log->info('With manual cookie: ' . json_encode($manual->json('cookies')));

    // 4. Inspect / clear the session.
    $log->info('Session holds ' . count($api->sessionCookies()) . ' cookie(s)');
    $api->clearCookies();
    $log->info('After clear, server sees: ' . json_encode($api->cookies()->json('cookies')));
} catch (HttpException $e) {
    $log->error($e->getMessage());
    exit(1);
}
