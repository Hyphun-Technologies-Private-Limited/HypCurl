<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Http;

/**
 * Fluent request builder. Describes WHAT to send; HttpClient decides HOW.
 *
 *   $client->post('/posts')
 *       ->json(['title' => 'Hi'])
 *       ->header('X-Trace', 'abc')
 *       ->retries(2)
 *       ->send();
 */
final class Request
{
    /** @var array<string, ?string> null value = remove that header */
    public array $headers = [];
    /** @var array<string, mixed> */
    public array $query = [];
    public ?string $body = null;
    /** @var array<string, string|\CURLFile> */
    public array $multipart = [];
    public ?string $basicAuth = null;
    public ?int $timeout = null;
    public ?int $retries = null;
    public ?string $saveTo = null;
    /** @var array<string, string> cookies sent with this request only */
    public array $cookies = [];
    /** null = use the client's proxy, '' = no proxy for this request */
    public ?string $proxy = null;

    public function __construct(
        public string $method,
        public string $path,
        private ?HttpClient $client = null,
    ) {
        $this->method = strtoupper($method);
    }

    /** Add/replace a header for this request (case-insensitive, overrides client defaults). */
    public function header(string $name, string $value): self
    {
        $this->headers = Headers::set($this->headers, $name, $value);

        return $this;
    }

    /**
     * Several headers at once, as a map or as "Name: value" strings:
     *   ->headers(['X-Trace' => 'abc', 'X-Tenant' => '42'])
     *   ->headers(['X-Trace: abc', 'X-Tenant: 42'])
     */
    public function headers(array $headers): self
    {
        foreach ($headers as $name => $value) {
            if (is_int($name)) {
                $this->headers = Headers::merge($this->headers, Headers::parse((string) $value));
            } else {
                $this->header($name, (string) $value);
            }
        }

        return $this;
    }

    /** Don't send this header — even a client default or one curl adds itself (e.g. Accept, User-Agent). */
    public function withoutHeader(string $name): self
    {
        $this->headers = Headers::set($this->headers, $name, null);

        return $this;
    }

    /** @param array<string, mixed> $params Null values are skipped. */
    public function query(array $params): self
    {
        $this->query = array_merge($this->query, array_filter($params, fn ($v) => $v !== null));

        return $this;
    }

    public function json(mixed $data): self
    {
        return $this->body(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'application/json');
    }

    /** application/x-www-form-urlencoded body. */
    public function form(array $fields): self
    {
        return $this->body(http_build_query($fields), 'application/x-www-form-urlencoded');
    }

    /**
     * multipart/form-data. A value starting with "@" is treated as a file path:
     *   ->multipart(['note' => 'hi', 'file' => '@/path/to/photo.png'])
     */
    public function multipart(array $fields): self
    {
        foreach ($fields as $name => $value) {
            if (is_string($value) && str_starts_with($value, '@')) {
                $path = substr($value, 1);
                if (!is_file($path)) {
                    throw new \InvalidArgumentException("Upload file not found: $path");
                }
                $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: null) : null;
                $value = new \CURLFile($path, $mime, basename($path));
            }
            $this->multipart[$name] = $value;
        }

        return $this;
    }

    public function body(string $body, ?string $contentType = null): self
    {
        $this->body = $body;
        if ($contentType !== null) {
            $this->header('Content-Type', $contentType);
        }

        return $this;
    }

    public function bearer(string $token): self
    {
        return $this->header('Authorization', "Bearer $token");
    }

    public function basicAuth(string $user, string $password): self
    {
        $this->basicAuth = "$user:$password";

        return $this;
    }

    public function timeout(int $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    public function retries(int $times): self
    {
        $this->retries = $times;

        return $this;
    }

    /**
     * Send an extra cookie with this request only (on top of the client's cookie session).
     * Handy for passing a cookie from one API/response to another:
     *   ->cookie('session', $loginResponse->cookie('session'))
     */
    public function cookie(string $name, string $value): self
    {
        $this->cookies[$name] = $value;

        return $this;
    }

    /** @param array<string, string> $cookies */
    public function cookies(array $cookies): self
    {
        foreach ($cookies as $name => $value) {
            $this->cookie($name, (string) $value);
        }

        return $this;
    }

    /** Route this request through a proxy ('' = bypass any proxy). */
    public function proxy(string $proxy): self
    {
        $this->proxy = $proxy;

        return $this;
    }

    public function withoutProxy(): self
    {
        return $this->proxy('');
    }

    /** Stream the response body into a file (downloads). */
    public function saveTo(string $file): self
    {
        $this->saveTo = $file;

        return $this;
    }

    /** Send through the client that created this request. */
    public function send(): Response
    {
        if ($this->client === null) {
            throw new \LogicException('Request is not bound to a client; use $client->send($request).');
        }

        return $this->client->send($this);
    }
}
