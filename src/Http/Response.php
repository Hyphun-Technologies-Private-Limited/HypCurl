<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Http;

final class Response
{
    private mixed $decoded = null;
    private bool $decodedReady = false;

    /**
     * @param array<string, string> $headers lower-cased header names
     * @param array<string, string> $cookies cookies set by this response (Set-Cookie), name => value
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly float $time,
        public readonly array $cookies = [],
    ) {
    }

    /** Value of a cookie this response set, e.g. $login->cookie('session'). */
    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function failed(): bool
    {
        return $this->status === 0 || $this->status >= 400;
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

    /** Throw if the request failed; otherwise return $this for chaining. */
    public function throw(): self
    {
        if ($this->failed()) {
            throw new HttpException("$this->method $this->url returned HTTP $this->status", $this);
        }

        return $this;
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
