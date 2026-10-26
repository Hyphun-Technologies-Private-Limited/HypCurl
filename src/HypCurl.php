<?php

declare(strict_types=1);

namespace Hyphun\HypCurl;

use Hyphun\HypCurl\Api\ApiClient;
use Hyphun\HypCurl\Core\Config;
use Hyphun\HypCurl\Core\Logger;

/**
 * Entry point for configuring HypCurl. Optional: without boot(), HypCurl uses
 * environment variables and Config::DEFAULTS.
 *
 *   HypCurl::boot([
 *       'config_dir' => __DIR__ . '/config/hypcurl',  // default.env, {env}.env, local.env
 *       'env'        => 'prod',                       // else HYPCURL_ENV, APP_ENV, "dev"
 *       'values'     => ['HYPCURL_TIMEOUT' => 10],    // highest priority
 *       'base_path'  => __DIR__,                      // for relative paths (cookie jar); default: cwd
 *       'logger'     => $psrLogger,                   // any PSR-3 logger
 *   ]);
 */
final class HypCurl
{
    public const VERSION = '1.0.0';

    /** Call once at startup. Re-booting resets config, logger and all API client singletons. */
    public static function boot(array $options = []): void
    {
        Config::configure(array_diff_key($options, ['logger' => true]));
        Logger::reset();
        ApiClient::resetAll();
        if (isset($options['logger'])) {
            Logger::instance()->useLogger($options['logger']);
        }
    }

    public static function config(): Config
    {
        return Config::instance();
    }
}
