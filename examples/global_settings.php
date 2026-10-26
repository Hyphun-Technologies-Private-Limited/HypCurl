<?php

declare(strict_types=1);

/*
 * Set proxy, SSL, redirects, headers, timeouts... ONCE at startup, so no request needs ->proxy() etc.
 * Run: php examples/global_settings.php
 *
 * Put this in your app's bootstrap (index.php, a service provider, a base controller...).
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use Hyphun\HypCurl\HypCurl;

HypCurl::boot([
    'defaults' => [
        // Proxy for every request ('none' = never use one, even if http_proxy is set)
        // 'proxy'       => 'http://user:pass@10.0.0.1:3128',
        // 'noProxy'     => 'localhost,127.0.0.1,.internal',

        // TLS: keep verification on in production
        'verifySsl'      => true,
        // 'caBundle'    => __DIR__ . '/certs/company-ca.pem',   // trust an internal CA

        // Redirects (like curl -L)
        'followRedirects' => true,
        'maxRedirects'    => 5,

        // Timeouts and retries
        'timeout'        => 20,
        'connectTimeout' => 5,
        'retries'        => 2,
        'retryDelay'     => 300,   // ms, doubles each retry

        // Headers sent with every request
        'headers' => [
            'X-App' => 'my-app',
            'Accept-Language' => 'en',
        ],
    ],
]);

// Every request below uses those settings: no ->proxy(), ->timeout(), ->header('X-App') needed.
$res = HypCurl::get('https://httpbin.org/redirect/2')->send();
echo "Followed {$res->redirects} redirects to {$res->finalUrl}\n";
echo 'Server saw X-App: ' . HypCurl::get('https://httpbin.org/headers')->send()->json('headers.X-App') . "\n";

// Clients and API classes get them too...
$shop = HypCurl::client(['baseUrl' => 'https://httpbin.org']);
echo 'Client request saw X-App: ' . $shop->get('/headers')->send()->json('headers.X-App') . "\n";

// ...and can still override any of them.
$local = HypCurl::client(['baseUrl' => 'https://httpbin.org', 'proxy' => 'none', 'timeout' => 5, 'followRedirects' => false]);
echo 'Not followed: HTTP ' . $local->get('/redirect/1')->send()->status . "\n";

// One request can override too.
echo 'Per-request override: HTTP ' . HypCurl::get('https://httpbin.org/redirect/1')->withoutRedirects()->send()->status . "\n";

/*
 * The same settings can live in config instead of code (config/hypcurl/default.env or env vars):
 *   HYPCURL_PROXY=http://10.0.0.1:3128
 *   HYPCURL_VERIFY_SSL=true
 *   HYPCURL_CA_BUNDLE=certs/company-ca.pem
 *   HYPCURL_FOLLOW_REDIRECTS=true
 *   HYPCURL_MAX_REDIRECTS=5
 *   HYPCURL_TIMEOUT=20
 *   HYPCURL_HEADERS=X-App: my-app | Accept-Language: en
 * 'defaults' in code win over these.
 */
