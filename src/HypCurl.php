<?php

declare(strict_types=1);

namespace Hyphun\HypCurl;

use Hyphun\HypCurl\Api\ApiClient;
use Hyphun\HypCurl\Core\Config;
use Hyphun\HypCurl\Core\ConfigException;
use Hyphun\HypCurl\Core\Logger;
use Hyphun\HypCurl\Http\HttpClient;
use Hyphun\HypCurl\Http\JsonErrorHandler;
use Hyphun\HypCurl\Http\Request;

/**
 * Entry point: configuration, plus shortcuts for making requests without writing an ApiClient class.
 *
 * Configure (optional; without boot(), HypCurl uses environment variables and Config::DEFAULTS):
 *
 *   HypCurl::boot([
 *       'config_dir' => __DIR__ . '/config/hypcurl',  // default.env, {env}.env, local.env
 *       'env'        => 'prod',                       // else HYPCURL_ENV, APP_ENV, "dev"
 *       'values'     => ['HYPCURL_TIMEOUT' => 10],    // highest priority
 *       'base_path'  => __DIR__,                      // for relative paths (cookie jar); default: cwd
 *       'logger'     => $psrLogger,                   // any PSR-3 logger
 *       'defaults'   => [                             // applied to EVERY client and request
 *           'proxy'     => 'http://10.0.0.1:3128',
 *           'verifySsl' => true,
 *           'headers'   => ['X-App' => 'my-app'],
 *           'timeout'   => 20,
 *       ],
 *       'json_errors' => true,                        // uncaught HypCurl errors -> JSON (or ['debug' => true])
 *   ]);
 *
 * Use directly from any class:
 *
 *   HypCurl::get('https://api.example.com/users')->query(['page' => 2])->send()->json();
 *   HypCurl::post('https://api.example.com/orders')->json($order)->bearer($token)->send();
 *
 *   $shop = HypCurl::client(['baseUrl' => 'https://shop.example.com/api', 'retries' => 2]);
 *   $shop->get('/orders/7')->send();
 */
final class HypCurl
{
    public const VERSION = '1.5.0';

    private static ?HttpClient $shared = null;

    /** Call once at startup. Re-booting resets config, logger, defaults, the shared client and all API client singletons. */
    private const BOOT_OPTIONS = ['config_dir', 'env', 'values', 'base_path', 'logger', 'defaults', 'json_errors'];

    public static function boot(array $options = []): void
    {
        // JSON errors first, so even a setup mistake below is reported as JSON.
        // (Also honoured when misplaced inside 'defaults'; that still raises a config error.)
        $json = $options['json_errors'] ?? $options['defaults']['json_errors'] ?? null;
        if (!empty($json)) {
            self::jsonErrors((bool) (is_array($json) ? ($json['debug'] ?? false) : false), (bool) (is_array($json) ? ($json['all'] ?? false) : false));
        }

        $inDefaults = 'is a request setting: put it inside \'defaults\' => [...].';
        ConfigException::assertKnownKeys($options, self::BOOT_OPTIONS, 'boot()', array_fill_keys(
            ['proxy', 'noProxy', 'verifySsl', 'caBundle', 'followRedirects', 'maxRedirects', 'timeout', 'connectTimeout',
             'retries', 'retryDelay', 'headers', 'cookies', 'cookieJar', 'throw'],
            $inDefaults,
        ));

        Config::configure(array_diff_key($options, ['logger' => true, 'defaults' => true, 'json_errors' => true]));
        Logger::reset();
        if (isset($options['logger'])) {
            Logger::instance()->useLogger($options['logger']);
        }
        self::defaults($options['defaults'] ?? []);
    }

    /**
     * Render uncaught HypCurl exceptions as JSON instead of a fatal error + stack trace.
     *   Web: 502 (504 for timeouts), Content-Type: application/json.   CLI: JSON on stdout, exit code 1.
     *
     * @param bool $debug         include request URL, curl details and the upstream response body (never in production)
     * @param bool $allExceptions also render non-HypCurl exceptions (as "internal_error")
     */
    public static function jsonErrors(bool $debug = false, bool $allExceptions = false): void
    {
        JsonErrorHandler::register($debug, $allExceptions);
    }

    /**
     * Settings for every client created from now on: proxy, verifySsl, caBundle, headers, timeout,
     * connectTimeout, retries, retryDelay, followRedirects, throw, cookies, cookieJar, noProxy.
     * Same names as client options. A client's own options, and per-request calls, still win.
     *
     * Resets the shared client and API singletons so they pick the defaults up.
     * Clients you created yourself with HypCurl::client() before this call keep their settings.
     */
    public static function defaults(array $defaults): void
    {
        HttpClient::setDefaults($defaults);
        ApiClient::resetAll();
        self::$shared = null;
    }

    public static function config(): Config
    {
        return Config::instance();
    }

    // ---- Direct use, no class needed ----

    /**
     * A new client with its own settings, cookies and connection. Keep it in a property of your class:
     *   $this->shop = HypCurl::client(['baseUrl' => '...', 'headers' => [...], 'retries' => 2]);
     * Options are the same as for an ApiClient's options() (see HttpClient).
     */
    public static function client(array $options = []): HttpClient
    {
        return new HttpClient($options);
    }

    /** The shared client behind HypCurl::get()/post()/...; keeps cookies between calls like a browser. */
    public static function http(): HttpClient
    {
        return self::$shared ??= new HttpClient();
    }

    /** Any method with a full URL: HypCurl::request('OPTIONS', 'https://...')->send() */
    public static function request(string $method, string $url): Request
    {
        return self::http()->request($method, $url);
    }

    public static function get(string $url): Request    { return self::http()->get($url); }
    public static function post(string $url): Request   { return self::http()->post($url); }
    public static function put(string $url): Request    { return self::http()->put($url); }
    public static function patch(string $url): Request  { return self::http()->patch($url); }
    public static function delete(string $url): Request { return self::http()->delete($url); }
}
