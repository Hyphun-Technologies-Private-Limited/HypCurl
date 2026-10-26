<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Http;

use Hyphun\HypCurl\Core\ConfigException;

/**
 * Turns uncaught HypCurl exceptions into a JSON error instead of a PHP fatal error + stack trace.
 * Register it with HypCurl::jsonErrors() or HypCurl::boot(['json_errors' => true]).
 *
 * Web:  responds 502 (upstream failed) or 504 (upstream timed out) with Content-Type: application/json.
 * CLI:  prints the JSON and exits with code 1.
 * HypCurl setup mistakes (ConfigException) are rendered too, as "config_error" with status 500.
 * On the web, the full (debug) error is also written to the PHP error log.
 * Other exceptions go to the previously registered handler (or PHP's default),
 * unless $allExceptions is true.
 */
final class JsonErrorHandler
{
    private static bool $registered = false;
    private static bool $debug = false;
    private static bool $allExceptions = false;
    /** @var callable|null */
    private static $previous = null;

    public static function register(bool $debug = false, bool $allExceptions = false): void
    {
        self::$debug = $debug;
        self::$allExceptions = $allExceptions;
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        self::$previous = set_exception_handler([self::class, 'handle']);
    }

    /** @internal called by PHP for uncaught exceptions */
    public static function handle(\Throwable $e): void
    {
        if (!$e instanceof HttpException && !$e instanceof ConfigException && !self::$allExceptions) {
            if (self::$previous !== null) {
                (self::$previous)($e);

                return;
            }
            restore_exception_handler();
            throw $e; // let PHP report it as usual
        }
        if (PHP_SAPI !== 'cli') {
            // Keep the full details in the server's error log, whatever the client is shown.
            error_log('[HypCurl] ' . json_encode(self::toArray($e, debug: true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        }
        self::render($e, self::$debug);
        exit(1);
    }

    /** Write the JSON error (and, on the web, the status + Content-Type headers). */
    public static function render(\Throwable $e, bool $debug = false): void
    {
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            http_response_code(self::httpStatus($e));
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(
            self::toArray($e, $debug),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        ), "\n";
    }

    /** JSON-ready array for any throwable (non-HypCurl ones become "internal_error"). */
    public static function toArray(\Throwable $e, bool $debug = false): array
    {
        if ($e instanceof HttpException || $e instanceof ConfigException) {
            return $e->toArray($debug);
        }
        $error = ['type' => 'internal_error', 'message' => $debug ? $e->getMessage() : 'Internal error'];
        if ($debug) {
            $error['exception'] = $e::class;
            $error['file'] = $e->getFile() . ':' . $e->getLine();
        }

        return ['success' => false, 'error' => $error];
    }

    /** Status for OUR response: the upstream API failing is a 502, an upstream timeout a 504. */
    public static function httpStatus(\Throwable $e): int
    {
        return match (true) {
            $e instanceof TimeoutException => 504,
            $e instanceof HttpException => 502,
            default => 500,
        };
    }
}
