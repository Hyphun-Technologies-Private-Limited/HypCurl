<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Http;

/**
 * Base class for every HypCurl request failure. Catch it to handle all of them, or catch a subclass:
 *
 *   HttpException
 *   ├─ NetworkException    no response: DNS, connection refused, reset, too many redirects, ...
 *   │  ├─ TimeoutException
 *   │  ├─ ProxyException
 *   │  └─ SslException
 *   ├─ ClientException     4xx response
 *   └─ ServerException     5xx response
 *
 * Every exception is structured and JSON-ready:
 *   $e->type        'timeout', 'proxy_error', 'not_found', 'validation_error', ...
 *   $e->reason      short message without the URL (safe to show to end users)
 *   $e->hint        what to check / how to fix it
 *   $e->status()    HTTP status, or null when there was no response
 *   $e->response    the Response (4xx/5xx only)
 *   $e->toArray(), $e->toJson(), json_encode($e)
 */
class HttpException extends \RuntimeException implements \JsonSerializable
{
    private const STATUS_TEXT = [
        400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden',
        404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable', 407 => 'Proxy Authentication Required',
        408 => 'Request Timeout', 409 => 'Conflict', 410 => 'Gone', 411 => 'Length Required', 413 => 'Payload Too Large',
        414 => 'URI Too Long', 415 => 'Unsupported Media Type', 418 => "I'm a teapot", 422 => 'Unprocessable Entity',
        423 => 'Locked', 425 => 'Too Early', 428 => 'Precondition Required', 429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large', 451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable',
        504 => 'Gateway Timeout', 505 => 'HTTP Version Not Supported', 507 => 'Insufficient Storage',
        508 => 'Loop Detected', 511 => 'Network Authentication Required',
    ];

    /** curl error codes that mean a TLS/certificate problem. */
    private const SSL_CODES = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    /** Types worth retrying (temporary network trouble). */
    private const RETRYABLE = ['timeout', 'connection_error', 'network_error', 'empty_response', 'proxy_error', 'dns_error'];

    /** Short message without the URL. */
    public readonly string $reason;

    public function __construct(
        string $message,
        public readonly ?Response $response = null,
        ?\Throwable $previous = null,
        public readonly string $type = 'http_error',
        public readonly ?string $method = null,
        public readonly ?string $url = null,
        public readonly ?string $hint = null,
        public readonly ?int $curlCode = null,
        public readonly ?string $curlMessage = null,
        ?string $reason = null,
    ) {
        parent::__construct($message, $response?->status ?? 0, $previous);
        $this->reason = $reason ?? $message;
    }

    /** Build the right exception for a 4xx/5xx response. */
    public static function fromResponse(Response $response): self
    {
        $status = $response->status;
        $retryAfter = $response->header('retry-after');
        [$type, $hint] = match (true) {
            $status === 400 => ['bad_request', 'The server rejected the request. See error.response.body for details.'],
            $status === 401 => ['unauthorized', 'Missing or invalid credentials: check your token / API key.'],
            $status === 403 => ['forbidden', 'Authenticated, but not allowed to access this resource.'],
            $status === 404 => ['not_found', 'Check the URL, the path and any IDs in it.'],
            $status === 405 => ['method_not_allowed', "This endpoint does not accept $response->method."],
            $status === 407 => ['proxy_auth_required', 'The proxy needs credentials: http://user:pass@host:port.'],
            $status === 408 => ['request_timeout', 'The server gave up waiting for the request.'],
            $status === 409 => ['conflict', 'The request conflicts with the current state of the resource.'],
            $status === 413 => ['payload_too_large', 'The request body is too large for this server.'],
            $status === 415 => ['unsupported_media_type', 'Wrong Content-Type: try ->json(), ->form() or ->multipart().'],
            $status === 422 => ['validation_error', 'The server rejected the request data. See error.response.body.'],
            $status === 429 => ['rate_limited', 'Too many requests' . ($retryAfter ? ", retry after $retryAfter s" : '') . '. Slow down or use ->retries().'],
            $status >= 500 => ['server_error', 'The remote server failed. Usually temporary: retry later or use ->retries().'],
            $status >= 400 => ['client_error', 'The server rejected the request.'],
            default => ['http_error', null],
        };

        $reason = trim($status . ' ' . (self::STATUS_TEXT[$status] ?? ''));
        $serverMessage = self::serverMessage($response);
        if ($serverMessage !== null) {
            $reason .= ": $serverMessage";
        }
        $url = Redact::url($response->finalUrl ?? $response->url);
        $class = $status >= 500 ? ServerException::class : ($status >= 400 ? ClientException::class : self::class);

        return new $class(
            "$reason ($response->method $url)",
            $response, null, $type, $response->method, $url, $hint, null, null, $reason,
        );
    }

    /**
     * Build the right exception for a curl transport error (no HTTP response).
     *
     * @param array{proxy?: ?string, timeout?: int, maxRedirects?: int} $context
     */
    public static function fromCurl(int $code, string $curlMessage, string $method, string $url, array $context = []): NetworkException
    {
        $safeUrl = Redact::url($url);
        $host = parse_url($url, PHP_URL_HOST) ?: $safeUrl;
        $proxy = ($context['proxy'] ?? '') !== '' ? Redact::url((string) $context['proxy']) : null;
        $tunnel = preg_match('/CONNECT tunnel failed, response (\d+)/i', $curlMessage, $m) ? (int) $m[1] : null;
        $checkProxy = 'Check HYPCURL_PROXY / the \'proxy\' option, or bypass it with ->withoutProxy().';

        [$class, $type, $reason, $hint] = match (true) {
            $code === 28 => [
                TimeoutException::class, 'timeout',
                'Request timed out' . (isset($context['timeout']) ? " after {$context['timeout']}s" : ''),
                "No response in time. Raise the timeout ('timeout' option, HYPCURL_TIMEOUT or ->timeout()) or check that $host is up.",
            ],
            $tunnel !== null => [
                ProxyException::class, 'proxy_error',
                "Proxy refused the tunnel to $host (proxy answered HTTP $tunnel)",
                match ($tunnel) {
                    407 => "The proxy $proxy needs valid credentials (http://user:pass@host:port).",
                    403 => "The proxy $proxy does not allow connections to $host.",
                    default => "The proxy $proxy could not reach $host or is overloaded. $checkProxy",
                },
            ],
            $code === 5 => [ProxyException::class, 'proxy_error', 'Could not resolve the proxy host', "Check the proxy address ($proxy). $checkProxy"],
            $code === 97 || ($proxy !== null && $code === 7) => [
                ProxyException::class, 'proxy_error', 'Could not connect to the proxy',
                "Is the proxy $proxy running and reachable from this machine? $checkProxy",
            ],
            $code === 6 => [NetworkException::class, 'dns_error', "Could not resolve host $host", 'Check the hostname and your DNS / internet connection.'],
            $code === 7 => [NetworkException::class, 'connection_error', "Could not connect to $host", 'Is the server running and the port open? Check firewall / VPN.'],
            in_array($code, self::SSL_CODES, true) => [
                SslException::class, 'ssl_error', "TLS/SSL error with $host: $curlMessage",
                "Fix the server's certificate, trust its CA with the 'caBundle' option, or (dev servers only) use ->insecure().",
            ],
            $code === 47 => [
                NetworkException::class, 'too_many_redirects',
                'Too many redirects' . (isset($context['maxRedirects']) ? " (limit {$context['maxRedirects']})" : ''),
                "Raise 'maxRedirects' or check for a redirect loop.",
            ],
            $code === 52 => [NetworkException::class, 'empty_response', "$host closed the connection without a response", 'The server may have crashed or rejected the connection. Retry later.'],
            in_array($code, [1, 3], true) => [NetworkException::class, 'invalid_url', 'Invalid or unsupported URL', 'Use a full http:// or https:// URL.'],
            in_array($code, [55, 56], true) => [NetworkException::class, 'connection_error', "Connection to $host was interrupted", 'The connection dropped mid-request. Retry, or check the network.'],
            default => [NetworkException::class, 'network_error', "Network error with $host", "curl said: $curlMessage"],
        };

        return new $class("$reason ($method $safeUrl)", null, null, $type, $method, $safeUrl, $hint, $code, $curlMessage, $reason);
    }

    /** HTTP status of the failed response, or null when no response arrived. */
    public function status(): ?int
    {
        return $this->response?->status;
    }

    /** Worth retrying? (temporary network problems, 429, 5xx) */
    public function retryable(): bool
    {
        $status = $this->status();

        return in_array($this->type, self::RETRYABLE, true) || $status === 429 || ($status !== null && $status >= 500);
    }

    /**
     * Structured error for APIs and logs.
     *
     * @param bool $debug false = only type, short message and status: safe to show end users
     *                    (no URLs, proxy addresses, config hints or upstream bodies);
     *                    true  = full message, hint, request, curl details and the response body
     */
    public function toArray(bool $debug = true): array
    {
        $error = ['type' => $this->type, 'message' => $debug ? $this->getMessage() : $this->reason];
        if ($this->response !== null) {
            $error['status'] = $this->response->status;
        }
        if ($debug) {
            if ($this->hint !== null) {
                $error['hint'] = $this->hint;
            }
            $error['request'] = array_filter(['method' => $this->method, 'url' => $this->url]);
            if ($this->curlCode !== null) {
                $error['curl'] = ['code' => $this->curlCode, 'message' => $this->curlMessage];
            }
            if ($this->response !== null) {
                $body = $this->response->isJson() ? $this->response->json() : self::truncate($this->response->body);
                $error['response'] = ['status' => $this->response->status, 'body' => $body];
            }
        }

        return ['success' => false, 'error' => $error];
    }

    public function toJson(bool $debug = true, int $flags = JSON_PRETTY_PRINT): string
    {
        return (string) json_encode(
            $this->toArray($debug),
            $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** Pull a human message out of common API error bodies. */
    private static function serverMessage(Response $response): ?string
    {
        $data = $response->json();
        if (!is_array($data)) {
            return null;
        }
        foreach (['message', 'error_description', 'detail', 'title', 'error', 'msg'] as $key) {
            $value = $data[$key] ?? null;
            if (is_array($value)) {
                $value = $value['message'] ?? null;
            }
            if (is_string($value) && trim($value) !== '') {
                return self::truncate(trim($value), 200);
            }
        }

        return null;
    }

    private static function truncate(string $text, int $max = 2000): string
    {
        return strlen($text) > $max ? substr($text, 0, $max) . '…' : $text;
    }
}
