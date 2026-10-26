<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Http;

use Hyphun\HypCurl\Core\HelpfulCalls;

/**
 * Result of a request. Turn it into a JSON-ready envelope with toArray() / toJson():
 *   success: {"success": true,  "status": 200, "data": {...}}
 *   failure: {"success": false, "error": {"type": "not_found", "message": "...", "status": 404, "hint": "..."}}
 */
final class Response implements \JsonSerializable
{
    use HelpfulCalls;

    private mixed $decoded = null;
    private bool $decodedReady = false;

    /**
     * @param array<string, string> $headers lower-cased header names
     * @param array<string, string> $cookies cookies set by this response (Set-Cookie), name => value
     * @param ?string $finalUrl    URL that produced this response, after following redirects
     * @param int $redirects       number of redirects followed
     * @param ?string $redirectUrl where a redirect would have gone, when redirects were NOT followed
     * @param ?HttpException $error the failure, when the request was sent with trySend() or 'throw' => false
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly float $time,
        public readonly array $cookies = [],
        public readonly ?string $finalUrl = null,
        public readonly int $redirects = 0,
        public readonly ?string $redirectUrl = null,
        public readonly ?HttpException $error = null,
    ) {
    }

    /** A Response standing in for a request that got no HTTP response at all (network error). */
    public static function failure(string $method, string $url, HttpException $error): self
    {
        return new self($method, $url, 0, [], '', 0.0, error: $error);
    }

    /** Same response with the failure attached. */
    public function withError(HttpException $error): self
    {
        return new self(
            $this->method, $this->url, $this->status, $this->headers, $this->body, $this->time,
            $this->cookies, $this->finalUrl, $this->redirects, $this->redirectUrl, $error,
        );
    }

    // ---- Method-style accessors (same values as the properties: $res->body === $res->body()) ----

    /** Raw response body. */
    public function body(): string
    {
        return $this->body;
    }

    /** HTTP status code (0 when no response arrived). */
    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> response headers, lower-cased names */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @return array<string, string> cookies set by this response */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /** Total time in seconds. */
    public function time(): float
    {
        return $this->time;
    }

    /** URL that was requested. */
    public function url(): string
    {
        return $this->url;
    }

    /** URL after following redirects. */
    public function finalUrl(): string
    {
        return $this->finalUrl ?? $this->url;
    }

    /** HTTP method that was used. */
    public function method(): string
    {
        return $this->method;
    }

    /** Number of redirects followed. */
    public function redirects(): int
    {
        return $this->redirects;
    }

    /** Where a 3xx would have gone, when redirects were not followed. */
    public function redirectUrl(): ?string
    {
        return $this->redirectUrl;
    }

    /** The failure, when sent with trySend() or 'throw' => false; null on success. */
    public function error(): ?HttpException
    {
        return $this->error;
    }

    /** Alias of ok(): 2xx and no error. */
    public function successful(): bool
    {
        return $this->ok();
    }

    /** JSON body decoded as an object (stdClass) instead of an array. */
    public function object(): mixed
    {
        return $this->isJson() ? json_decode($this->body, false) : null;
    }

    /** 3xx response (only seen when redirects are not followed). */
    public function isRedirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    /** Value of a cookie this response set, e.g. $login->cookie('session'). */
    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function ok(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300;
    }

    public function failed(): bool
    {
        return $this->error !== null || $this->status === 0 || $this->status >= 400;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isJson(): bool
    {
        $this->decode();

        return $this->decoded !== null;
    }

    /**
     * Decoded JSON body, or a value at a dot path:
     *   $res->json()            whole body as array
     *   $res->json('id')        top-level key
     *   $res->json('data.0.id') nested key / list index
     */
    public function json(?string $path = null, mixed $default = null): mixed
    {
        $this->decode();
        if ($path === null) {
            return $this->decoded ?? $default;
        }
        $value = $this->decoded;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** Throw the matching exception if the request failed; otherwise return $this for chaining. */
    public function throw(): self
    {
        if ($this->error !== null) {
            throw $this->error;
        }
        if ($this->failed()) {
            throw HttpException::fromResponse($this);
        }

        return $this;
    }

    /**
     * JSON-ready envelope, e.g. to return from your own API endpoint.
     *
     * @param bool $debug failures only: include request/curl/response details (see HttpException::toArray)
     */
    public function toArray(bool $debug = true): array
    {
        if ($this->failed()) {
            return ($this->error ?? HttpException::fromResponse($this))->toArray($debug);
        }

        return ['success' => true, 'status' => $this->status, 'data' => $this->isJson() ? $this->json() : $this->body];
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

    /** Pretty JSON if possible, raw body otherwise. */
    public function pretty(): string
    {
        return $this->isJson()
            ? json_encode($this->decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $this->body;
    }

    private function decode(): void
    {
        if ($this->decodedReady) {
            return;
        }
        $this->decodedReady = true;
        if ($this->body === '') {
            return;
        }
        try {
            $this->decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->decoded = null;
        }
    }
}
