<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Http;

use Hyphun\HypCurl\Core\Config;
use Hyphun\HypCurl\Core\Logger;

/**
 * The ONE place that talks to ext-curl. Every API call in the project goes through send().
 *
 * Options (all optional; unset ones fall back to Config, see Config::DEFAULTS):
 *   baseUrl          string   prefix for relative paths
 *   headers          array    default headers for every request; added on top of HYPCURL_HEADERS from config
 *                             (["Name" => "value"], a null value removes a header)
 *   timeout          int      seconds                         (HYPCURL_TIMEOUT)
 *   connectTimeout   int      seconds                         (HYPCURL_CONNECT_TIMEOUT)
 *   retries          int      retries on network error/429/5xx (HYPCURL_RETRIES)
 *   retryDelay       int      base delay in ms, doubles each retry (HYPCURL_RETRY_DELAY_MS)
 *   verifySsl        bool                                     (HYPCURL_VERIFY_SSL)
 *   followRedirects  bool     default true
 *   throw            bool     throw HttpException on 4xx/5xx, default true
 *   cookies          bool     keep a cookie session like a browser (HYPCURL_COOKIES, default true)
 *   cookieJar        string   file to load/save cookies so they survive between runs (HYPCURL_COOKIE_JAR)
 *                             relative paths are resolved from the base path (see HypCurl::boot)
 *   proxy            string   e.g. http://host:8080, http://user:pass@host:8080, socks5h://host:1080 (HYPCURL_PROXY)
 *   noProxy          string   comma-separated hosts that bypass the proxy, e.g. "localhost,.internal" (HYPCURL_NO_PROXY)
 */
final class HttpClient
{
    /** Header values hidden in logs / printed curl commands. */
    private const SECRET_HEADERS = ['cookie', 'x-api-key', 'api-key', 'x-auth-token', 'x-access-token'];

    private string $baseUrl;
    /** @var array<string, string> */
    private array $headers;
    private int $timeout;
    private int $connectTimeout;
    private int $retries;
    private int $retryDelayMs;
    private bool $verifySsl;
    private bool $followRedirects;
    private bool $throw;
    private bool $cookies;
    private ?string $cookieJar;
    private ?string $proxy;
    private ?string $noProxy;
    private ?\CurlHandle $handle = null;
    private Logger $log;

    public function __construct(array $options = [])
    {
        $config = Config::instance();
        $this->log = Logger::instance();

        $this->baseUrl = rtrim($options['baseUrl'] ?? '', '/');
        // Priority: built-in < HYPCURL_HEADERS (config) < client options < per-request headers
        $this->headers = Headers::merge(
            ['User-Agent' => $config->get('HYPCURL_USER_AGENT', 'HypCurl/1.0'), 'Accept' => 'application/json'],
            Headers::parse($config->get('HYPCURL_HEADERS', '')),
            $options['headers'] ?? [],
        );
        $this->timeout = $options['timeout'] ?? $config->int('HYPCURL_TIMEOUT', 30);
        $this->connectTimeout = $options['connectTimeout'] ?? $config->int('HYPCURL_CONNECT_TIMEOUT', 10);
        $this->retries = $options['retries'] ?? $config->int('HYPCURL_RETRIES', 0);
        $this->retryDelayMs = $options['retryDelay'] ?? $config->int('HYPCURL_RETRY_DELAY_MS', 500);
        $this->verifySsl = $options['verifySsl'] ?? $config->bool('HYPCURL_VERIFY_SSL', true);
        $this->followRedirects = $options['followRedirects'] ?? true;
        $this->throw = $options['throw'] ?? true;
        $this->cookies = $options['cookies'] ?? $config->bool('HYPCURL_COOKIES', true);
        $jar = $options['cookieJar'] ?? $config->get('HYPCURL_COOKIE_JAR');
        $this->cookieJar = ($jar === null || $jar === '') ? null : $config->path($jar);
        $this->proxy = $options['proxy'] ?? $config->get('HYPCURL_PROXY');
        if ($this->proxy === 'none') {
            $this->proxy = ''; // '' makes curl ignore http_proxy/https_proxy env vars too
        }
        $this->noProxy = $options['noProxy'] ?? $config->get('HYPCURL_NO_PROXY');
    }

    /** Change the proxy for all later requests from this client (null = no proxy at all). */
    public function setProxy(?string $proxy, ?string $noProxy = null): self
    {
        $this->proxy = $proxy ?? '';
        $this->noProxy = $noProxy;

        return $this;
    }

    // ---- Cookie session ----

    /**
     * Cookies currently held by this client's session.
     *
     * @return list<array{domain: string, path: string, secure: bool, expires: int, name: string, value: string}>
     */
    public function cookies(): array
    {
        $ch = $this->cookieHandle();
        $cookies = [];
        foreach (curl_getinfo($ch, CURLINFO_COOKIELIST) ?: [] as $line) {
            // Netscape format: domain, subdomains, path, secure, expires, name, value
            $f = explode("\t", $line);
            if (count($f) >= 7) {
                $cookies[] = [
                    'domain' => preg_replace('/^#HttpOnly_/', '', $f[0]),
                    'path' => $f[2],
                    'secure' => $f[3] === 'TRUE',
                    'expires' => (int) $f[4],
                    'name' => $f[5],
                    'value' => $f[6],
                ];
            }
        }

        return $cookies;
    }

    /** Forget all cookies (and empty the jar file if one is used). */
    public function clearCookies(): void
    {
        curl_setopt($this->cookieHandle(), CURLOPT_COOKIELIST, 'ALL');
        if ($this->cookieJar !== null && is_file($this->cookieJar)) {
            unlink($this->cookieJar);
        }
    }

    /** Handle with the cookie engine on and the jar (if any) loaded. */
    private function cookieHandle(): \CurlHandle
    {
        $ch = $this->handle ??= curl_init();
        if ($this->cookies) {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieJar ?? '');
            if ($this->cookieJar !== null && is_file($this->cookieJar)) {
                curl_setopt($ch, CURLOPT_COOKIELIST, 'RELOAD');
            }
        }

        return $ch;
    }

    // ---- Request factories (bound to this client, so ->send() works) ----

    public function request(string $method, string $path): Request
    {
        return new Request($method, $path, $this);
    }

    public function get(string $path): Request    { return $this->request('GET', $path); }
    public function post(string $path): Request   { return $this->request('POST', $path); }
    public function put(string $path): Request    { return $this->request('PUT', $path); }
    public function patch(string $path): Request  { return $this->request('PATCH', $path); }
    public function delete(string $path): Request { return $this->request('DELETE', $path); }

    /** Add/replace a header sent with every request from this client (e.g. after login). */
    public function setDefaultHeader(string $name, string $value): self
    {
        $this->headers = Headers::set($this->headers, $name, $value);

        return $this;
    }

    /** Stop sending a header on every request from this client. */
    public function removeDefaultHeader(string $name): self
    {
        $this->headers = Headers::set($this->headers, $name, null);

        return $this;
    }

    /** @return array<string, ?string> */
    public function defaultHeaders(): array
    {
        return $this->headers;
    }

    // ---- Sending ----

    public function send(Request $request): Response
    {
        $url = $this->buildUrl($request);
        $headers = Headers::merge($this->headers, $request->headers);
        $retries = $request->retries ?? $this->retries;

        $this->log->debug($this->toCurlCommand($request, $url, $headers));
        if (Config::instance()->bool('HYPCURL_DRY_RUN')) {
            $this->log->info('[dry-run] ' . $this->toCurlCommand($request, $url, $headers));

            return new Response($request->method, $url, 0, [], '', 0.0);
        }

        $proxy = $request->proxy ?? $this->proxy;
        $this->log->info("→ $request->method $url" . ($proxy ? ' (via proxy ' . self::maskUrl($proxy) . ')' : ''));
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->execute($request, $url, $headers);
                $shouldRetry = $response->status === 429 || $response->status >= 500;
                $error = null;
            } catch (HttpException $e) {
                $response = null;
                $shouldRetry = true;
                $error = $e;
            }

            if (!$shouldRetry || $attempt > $retries) {
                break;
            }
            $delayMs = $this->retryDelayMs * (2 ** ($attempt - 1));
            $reason = $error?->getMessage() ?? "HTTP $response->status";
            $this->log->warn("Attempt $attempt failed ($reason) — retrying in {$delayMs}ms");
            usleep($delayMs * 1000);
        }

        if ($error !== null) {
            $this->log->error("← {$error->getMessage()}");
            throw $error;
        }

        $summary = sprintf('← %d in %.3fs%s', $response->status, $response->time, $request->saveTo ? " (saved to $request->saveTo)" : '');
        $response->failed() ? $this->log->error($summary) : $this->log->info($summary);

        return $this->throw ? $response->throw() : $response;
    }

    private function execute(Request $request, string $url, array $headers): Response
    {
        $ch = $this->handle ??= curl_init();
        curl_reset($ch); // reuse the handle => keep-alive connections, fresh options

        $responseHeaders = [];
        $responseCookies = [];
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $request->timeout ?? $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => $this->followRedirects,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_ENCODING => '', // accept any compression curl supports
            CURLOPT_HTTPHEADER => Headers::toCurl($headers),
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders, &$responseCookies): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = []; // new response (e.g. after redirect) — start over
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $name = strtolower(trim($name));
                    $value = trim($value);
                    $responseHeaders[$name] = isset($responseHeaders[$name]) ? "{$responseHeaders[$name]}, $value" : $value;
                    if ($name === 'set-cookie' && str_contains($value, '=')) {
                        // Kept across redirects: a login that 302s still exposes its cookies.
                        [$cookieName, $cookieValue] = explode('=', explode(';', $value, 2)[0], 2);
                        $responseCookies[trim($cookieName)] = urldecode(trim($cookieValue));
                    }
                }

                return strlen($line);
            },
        ];
        if ($request->method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }
        if ($request->basicAuth !== null) {
            $options[CURLOPT_USERPWD] = $request->basicAuth;
        }

        // Cookies: the engine keeps the session; a jar file persists it between runs.
        if ($this->cookies) {
            $options[CURLOPT_COOKIEFILE] = $this->cookieJar ?? ''; // '' = in-memory only
            if ($this->cookieJar !== null) {
                $this->ensureDir($this->cookieJar);
                $options[CURLOPT_COOKIEJAR] = $this->cookieJar;
            }
        }
        if ($request->cookies !== []) {
            $options[CURLOPT_COOKIE] = $this->cookieString($request->cookies);
        }

        // Proxy: request setting wins over client setting; '' means "no proxy at all".
        $proxy = $request->proxy ?? $this->proxy;
        if ($proxy !== null) {
            $options[CURLOPT_PROXY] = $proxy;
            if ($proxy !== '' && $this->noProxy !== null) {
                $options[CURLOPT_NOPROXY] = $this->noProxy;
            }
        }

        if ($request->multipart !== []) {
            $options[CURLOPT_POSTFIELDS] = $request->multipart; // curl sets multipart Content-Type itself
        } elseif ($request->body !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }

        $sink = null;
        if ($request->saveTo !== null) {
            $sink = fopen($request->saveTo, 'wb') ?: throw new \RuntimeException("Cannot write to $request->saveTo");
            $options[CURLOPT_RETURNTRANSFER] = false;
            $options[CURLOPT_FILE] = $sink;
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        if ($sink !== null) {
            fclose($sink);
        }
        if ($this->cookies && $this->cookieJar !== null) {
            curl_setopt($ch, CURLOPT_COOKIELIST, 'FLUSH'); // write the jar now, not at exit
        }

        if ($errno !== 0) {
            throw new HttpException("curl error $errno: $error ($request->method " . self::maskUrl($url) . ')');
        }

        return new Response(
            $request->method,
            $url,
            (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            $responseHeaders,
            is_string($body) ? $body : '',
            (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME),
            $responseCookies,
        );
    }

    /** @param array<string, string> $cookies */
    private function cookieString(array $cookies): string
    {
        return implode('; ', array_map(fn ($k, $v) => "$k=" . rawurlencode((string) $v), array_keys($cookies), $cookies));
    }

    private function ensureDir(string $file): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory $dir");
        }
    }

    /** Hide passwords in URLs like http://user:secret@proxy:8080 */
    private static function maskUrl(string $url): string
    {
        return preg_replace('#(//[^:/@]+):[^@/]*@#', '$1:****@', $url);
    }

    private function buildUrl(Request $request): string
    {
        if (preg_match('#^https?://#i', $request->path)) {
            $url = $request->path;
        } elseif ($this->baseUrl !== '') {
            $url = $this->baseUrl . '/' . ltrim($request->path, '/');
        } else {
            throw new \InvalidArgumentException("'$request->path' is not a full URL and this client has no baseUrl");
        }

        if ($request->query !== []) {
            // http_build_query turns true/false into 1/0; APIs usually expect "true"/"false".
            $query = array_map(fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : $v, $request->query);
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /** Equivalent shell curl command (secrets masked) — great for debugging and learning. */
    public function toCurlCommand(Request $request, string $url, array $headers): string
    {
        $parts = ['curl', '-sS', '-X', $request->method];
        foreach ($headers as $name => $value) {
            $lower = strtolower($name);
            if ($value !== null && in_array($lower, ['authorization', 'proxy-authorization'], true)) {
                $value = preg_replace('/^(\S+\s).*/', '$1****', $value);  // keep scheme: "Bearer ****"
            } elseif ($value !== null && in_array($lower, self::SECRET_HEADERS, true)) {
                $value = '****';
            }
            $parts[] = '-H';
            $parts[] = escapeshellarg($value === null ? "$name:" : "$name: $value");
        }
        if ($request->basicAuth !== null) {
            $parts[] = '-u';
            $parts[] = escapeshellarg(strtok($request->basicAuth, ':') . ':****');
        }
        foreach ($request->multipart as $name => $value) {
            $parts[] = '-F';
            $parts[] = escapeshellarg($value instanceof \CURLFile ? "$name=@{$value->getFilename()}" : "$name=$value");
        }
        if ($request->multipart === [] && $request->body !== null) {
            $parts[] = '--data-raw';
            $parts[] = escapeshellarg($request->body);
        }
        if ($request->cookies !== []) {
            $parts[] = '-b';
            $parts[] = escapeshellarg($this->cookieString($request->cookies));
        }
        if ($this->cookies && $this->cookieJar !== null) {
            array_push($parts, '-b', escapeshellarg($this->cookieJar), '-c', escapeshellarg($this->cookieJar));
        }
        $proxy = $request->proxy ?? $this->proxy;
        if ($proxy !== null) {
            array_push($parts, '-x', escapeshellarg(self::maskUrl($proxy)));
            if ($proxy !== '' && $this->noProxy !== null) {
                array_push($parts, '--noproxy', escapeshellarg($this->noProxy));
            }
        }
        if ($request->saveTo !== null) {
            $parts[] = '-o';
            $parts[] = escapeshellarg($request->saveTo);
        }
        $parts[] = escapeshellarg($url);

        return implode(' ', $parts);
    }
}
