<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Api;

use Hyphun\HypCurl\Core\Singleton;
use Hyphun\HypCurl\Http\HttpClient;
use Hyphun\HypCurl\Http\Request;

/**
 * Base class for one external API. Each subclass is a singleton:
 *   JsonPlaceholder::instance()->posts()->list();
 *
 * A subclass only has to say how to configure its HttpClient (options()),
 * and expose resources/methods. All curl plumbing is inherited.
 */
abstract class ApiClient
{
    use Singleton;

    protected HttpClient $http;

    /** @var array<string, Resource> */
    private array $resources = [];

    final protected function __construct()
    {
        $this->http = new HttpClient($this->options());
        $this->boot();
    }

    /** HttpClient options for this API (baseUrl, headers, timeout, retries, ...). */
    abstract protected function options(): array;

    /** Optional hook: runs once after construction (e.g. attach a token). */
    protected function boot(): void
    {
    }

    public function http(): HttpClient
    {
        return $this->http;
    }

    public function withToken(string $token): static
    {
        $this->http->setDefaultHeader('Authorization', "Bearer $token");

        return $this;
    }

    /** Send this header with every later request to this API. */
    public function withHeader(string $name, string $value): static
    {
        $this->http->setDefaultHeader($name, $value);

        return $this;
    }

    /** Stop sending this header (including built-ins like Accept / User-Agent). */
    public function withoutHeader(string $name): static
    {
        $this->http->removeDefaultHeader($name);

        return $this;
    }

    /** Follow redirects (and how many at most) for all later requests to this API. */
    public function withRedirects(bool $follow, ?int $max = null): static
    {
        $this->http->setFollowRedirects($follow, $max);

        return $this;
    }

    /** Turn TLS certificate verification on/off for all later requests to this API. */
    public function withSslVerification(bool $verify): static
    {
        $this->http->setVerifySsl($verify);

        return $this;
    }

    /** Route all later requests through a proxy (null = none). */
    public function withProxy(?string $proxy, ?string $noProxy = null): static
    {
        $this->http->setProxy($proxy, $noProxy);

        return $this;
    }

    /** Value of one cookie in this API's session (from any earlier response), or null. */
    public function sessionCookie(string $name, ?string $domain = null): ?string
    {
        return $this->http->cookie($name, $domain);
    }

    /** Cookies held by this API's session (name, value, domain, path, ...). */
    public function sessionCookies(): array
    {
        return $this->http->cookies();
    }

    /** Log out locally: forget every cookie (and delete the jar file, if used). */
    public function clearCookies(): string
    {
        $this->http->clearCookies();

        return 'Cookies cleared';
    }

    public function get(string $path): Request    { return $this->http->get($path); }
    public function post(string $path): Request   { return $this->http->post($path); }
    public function put(string $path): Request    { return $this->http->put($path); }
    public function patch(string $path): Request  { return $this->http->patch($path); }
    public function delete(string $path): Request { return $this->http->delete($path); }

    /**
     * Cached resource instances, so each one is created once per API.
     *
     * @template T of Resource
     * @param class-string<T> $class
     * @return T
     */
    protected function resource(string $class, string $path): Resource
    {
        return $this->resources["$class|$path"] ??= new $class($this, $path);
    }
}
