# HypCurl

Organised, reusable cURL toolkit for PHP 8.1+. Define each external API once as a small class, then call it from code or the terminal. Retries, timeouts, cookies, proxies, custom headers and logging are handled in one place.

- **Direct use, no class needed:** `HypCurl::get($url)->send()->json()` from any class you already have
- **Ready-made template:** `hypcurl make:api` generates `HypClass`, a working API class you fill in
- **Singleton API clients:** `HypClass::instance()->items()->find(7)`
- **Fluent requests:** `$api->post('/orders')->json($data)->bearer($token)->retries(2)->send()`
- **REST resources with no code:** `list`, `find`, `create`, `update`, `patch`, `delete`
- **Cookie sessions:** kept in memory, or in a cookie-jar file that persists between runs
- **Global settings:** set the proxy, SSL, redirects, timeouts and headers once with `HypCurl::boot(['defaults' => [...]])`
- **Proxy support:** HTTP, HTTPS and SOCKS, with credentials masked in logs
- **Readable, JSON-ready errors:** typed exceptions (`TimeoutException`, `ProxyException`, …) with hints, `toJson()`, `trySend()`, and uncaught errors rendered as JSON
- **SSL and redirect control:** verify or skip TLS checks, trust a custom CA, follow or limit redirects
- **Custom headers:** layered (global → per-API → per-request), and you can remove any header
- **CLI:** call any API method with `vendor/bin/hypcurl`, and see the equivalent `curl` command with `-v`
- **No dependencies** beyond `ext-curl`. Optionally logs through any PSR-3 logger.

## Contents

[Installation](#installation) · [Quick start](#quick-start) · [Use it directly](#use-it-directly-no-extra-class) · [Configuration](#configuration) · [Global settings](#global-settings-set-once-used-everywhere) · [Requests](#requests) · [Responses](#responses) · [Errors](#errors) · [Cookies](#cookies-chained-requests) · [Headers](#headers) · [Proxy](#proxy) · [SSL / TLS](#ssl--tls) · [Redirects](#redirects) · [CLI](#cli-reference) · [Long-running processes](#long-running-processes) · [Development](#development)

## Installation

```bash
composer require hyphun/hypcurl
```

Requires PHP 8.1+ with the `curl` and `json` extensions. `fileinfo` is optional and is used to detect MIME types for uploads. See [CHANGELOG.md](CHANGELOG.md) for what changed in each version.

## Quick start

### 1. Generate your API class

```bash
vendor/bin/hypcurl init        # once: creates hypcurl.php + config/hypcurl/
vendor/bin/hypcurl make:api    # creates src/Api/HypClass.php and registers it
```

Pass a name to generate more classes, one per external API: `make:api Payments`, `make:api Sms`. The namespace and folder are read from your `composer.json` PSR-4 autoload; with `"App\\": "src/"` you get `App\Api\HypClass` in `src/Api/`. You can override them with `--namespace=... --path=...`.

### 2. Fill it in

The generated class works immediately: it points at httpbin.org, which echoes requests back. It has three marked sections for you to edit:

```php
namespace App\Api;

final class HypClass extends ApiClient
{
    // 1. CONNECTION: base URL, auth, default headers, timeouts
    protected function options(): array
    {
        $config = Config::instance();

        return [
            'baseUrl' => $config->get('HYP_CLASS_BASE_URL', 'https://httpbin.org'),
            'headers' => array_filter([
                'Accept' => 'application/json',
                'Authorization' => ($token = $config->get('HYP_CLASS_TOKEN')) ? "Bearer $token" : null,
            ]),
            'retries' => 2,
        ];
    }

    // 2. RESOURCES: REST collections get list/find/create/update/patch/delete for free
    public function items(): CrudResource
    {
        return $this->resource(CrudResource::class, '/items');
    }

    // 3. YOUR ENDPOINTS: one method per call; copy a pattern and edit it
    public function search(string $query, array $filters = []): Response
    {
        return $this->get('/search')->query(['q' => $query] + $filters)->send();
    }

    public function createOrder(array $order): Response
    {
        return $this->post('/orders')->json($order)->send();
    }

    // ... also included: ping(), login() (cookie session), upload(), download(), originIp()
}
```

Set its URL and token in `config/hypcurl/default.env` / `local.env`. The keys use the class name as a prefix:

```
HYP_CLASS_BASE_URL=https://api.yourservice.com
HYP_CLASS_TOKEN=secret
```

### 3. Use it in code

```php
use App\Api\HypClass;

$api = HypClass::instance();               // same instance everywhere

$results = $api->search('php', ['page' => 2])->json('data');
$order   = $api->createOrder(['item' => 'pen', 'qty' => 2])->json('id');
$item    = $api->items()->find(7)->json();
```

### 4. Or from the terminal

```bash
vendor/bin/hypcurl hypclass                       # list methods
vendor/bin/hypcurl hypclass search php page=2
vendor/bin/hypcurl hypclass createOrder item=pen qty=2 -v
vendor/bin/hypcurl hypclass items.find 7
```

Without the generator, a class only needs to extend `Hyphun\HypCurl\Api\ApiClient` and implement `options()`.

## Use it directly (no extra class)

You don't have to create an API class. Call HypCurl from any class you already have.

**One-off calls** with a full URL:

```php
use Hyphun\HypCurl\HypCurl;

$users = HypCurl::get('https://api.example.com/users')->query(['page' => 2])->send()->json();

$order = HypCurl::post('https://api.example.com/orders')
    ->json(['item' => 'pen', 'qty' => 2])
    ->bearer($token)
    ->send();

$id = $order->json('id');
```

`HypCurl::get/post/put/patch/delete/request()` share one client, so cookies are kept between calls, like a browser.

**A configured client in your existing class.** Set the base URL, auth, headers and retries once, then use the client in any method:

```php
use Hyphun\HypCurl\Http\ClientException;
use Hyphun\HypCurl\Http\HttpClient;
use Hyphun\HypCurl\HypCurl;

class OrderService
{
    private HttpClient $shop;

    public function __construct()
    {
        $this->shop = HypCurl::client([
            'baseUrl' => 'https://shop.example.com/api',
            'headers' => ['X-Api-Key' => getenv('SHOP_API_KEY')],
            'retries' => 2,
            'timeout' => 15,
        ]);
    }

    public function find(int $id): ?array
    {
        try {
            return $this->shop->get("/orders/$id")->send()->json();
        } catch (ClientException $e) {      // 4xx
            if ($e->type === 'not_found') {
                return null;
            }
            throw $e;
        }
    }

    public function create(array $order): int
    {
        return $this->shop->post('/orders')->json($order)->send()->json('id');
    }
}
```

Each `HypCurl::client()` has its own settings, cookies and connection. Every request option shown below (headers, cookies, proxy, uploads, …) works the same way. See [examples/direct_usage.php](examples/direct_usage.php).

**Which approach to use:** direct use suits a few calls inside an existing class. An API class (`make:api`) is better when an API is used in many places, or when you want to call it from the terminal.

## Configuration

HypCurl works without any setup. It reads `HYPCURL_*` environment variables and falls back to built-in defaults. To use config files, set global defaults, or plug in your app's logger, call `boot()` once at startup. Every key is optional:

```php
use Hyphun\HypCurl\HypCurl;

HypCurl::boot([
    'config_dir'  => __DIR__ . '/config/hypcurl',   // default.env, {env}.env, local.env
    'env'         => 'prod',                        // default: HYPCURL_ENV, APP_ENV, or "dev"
    'values'      => ['HYPCURL_TIMEOUT' => 15],     // HYPCURL_* keys, highest priority
    'base_path'   => __DIR__,                       // for relative paths (cookie jar, CA bundle); default: cwd
    'logger'      => $monolog,                      // any PSR-3 logger
    'defaults'    => ['proxy' => '...', 'verifySsl' => true],   // see Global settings
    'json_errors' => true,                          // see Errors
]);
```

Priority, highest first: `values` → environment variables → `local.env` → `{env}.env` → `default.env` → built-in defaults.

| Key | Default | Meaning |
|---|---|---|
| `HYPCURL_TIMEOUT` | `30` | Total request timeout (s) |
| `HYPCURL_CONNECT_TIMEOUT` | `10` | Connect timeout (s) |
| `HYPCURL_RETRIES` | `0` | Retries on temporary failures: timeouts, connection/DNS/proxy errors, 429, 5xx (not on TLS errors or redirect loops) |
| `HYPCURL_RETRY_DELAY_MS` | `500` | First retry delay, doubles each retry |
| `HYPCURL_VERIFY_SSL` | `true` | Verify TLS certificate + hostname (`false` = `curl -k`) |
| `HYPCURL_CA_BUNDLE` | | Extra CA certificate file (PEM) to trust |
| `HYPCURL_FOLLOW_REDIRECTS` | `true` | Follow 3xx redirects (`curl -L`) |
| `HYPCURL_MAX_REDIRECTS` | `10` | Max redirects to follow |
| `HYPCURL_USER_AGENT` | `HypCurl/1.0` | |
| `HYPCURL_HEADERS` | | Extra headers for every request: `X-App: a \| X-Env: dev` |
| `HYPCURL_COOKIES` | `true` | Keep a cookie session per API client |
| `HYPCURL_COOKIE_JAR` | | Cookie file shared between runs |
| `HYPCURL_PROXY` | | `http://user:pass@host:3128`, `socks5h://host:1080`, or `none` |
| `HYPCURL_NO_PROXY` | | `localhost,.internal` |
| `HYPCURL_LOG_LEVEL` | `INFO` | `DEBUG`, `INFO`, `WARN`, `ERROR`, `OFF` |
| `HYPCURL_DRY_RUN` | `false` | Log the curl command instead of sending |

> All keys use the `HYPCURL_` prefix on purpose. Under CGI/FPM, incoming request headers become `HTTP_*` variables ("httpoxy"), so `HTTP_*` names must never be used for settings.

Logging: in the CLI, logs go to stderr. In web requests (FPM, FrankenPHP, …), HypCurl stays silent unless you pass a `logger`.

## Global settings: set once, used everywhere

Set the proxy, TLS, redirect, timeout and header settings once at startup, so individual requests never repeat them:

```php
use Hyphun\HypCurl\HypCurl;

// e.g. in index.php, a service provider, or your app's bootstrap
HypCurl::boot([
    'defaults' => [
        'proxy'           => 'http://user:pass@10.0.0.1:3128',
        'noProxy'         => 'localhost,.internal',
        'verifySsl'       => true,
        'caBundle'        => __DIR__ . '/certs/company-ca.pem',
        'followRedirects' => true,
        'maxRedirects'    => 5,
        'timeout'         => 20,
        'connectTimeout'  => 5,
        'retries'         => 2,
        'headers'         => ['X-App' => 'my-app'],
    ],
]);

HypCurl::get('https://api.example.com/users')->send();   // uses all of the above
```

These defaults apply to the `HypCurl::get()/post()…` shortcuts, to `HypCurl::client()`, and to every API class. Anything more specific still wins, in this order:

**per request** (`->proxy()`, `->insecure()`, `->withoutRedirects()`…) > **client / API `options()`** > **`defaults`** > **`HYPCURL_*` config** > **built-in**

`defaults` takes request settings only. `json_errors`, `logger`, `config_dir` and the other `boot()` options go next to it, not inside it:

```php
HypCurl::boot([
    'defaults'    => ['proxy' => $proxy, 'timeout' => 20],   // request settings
    'json_errors' => true,                                   // boot() option
]);
```

Headers are merged rather than replaced. Unknown or misplaced keys raise a `ConfigException` that says where the key belongs, or suggests the right name for a typo like `proxyy`. You can also change the defaults later with `HypCurl::defaults([...])`. Call `boot()` before you create clients: a `HypCurl::client()` made earlier keeps its settings. Full example: [examples/global_settings.php](examples/global_settings.php).

## Requests

```php
$res = $api->post('/orders')
    ->query(['draft' => true])                 // ?draft=true
    ->json(['item' => 'pen', 'qty' => 2])      // or ->form([...]), ->body($raw, 'text/xml')
    ->multipart(['file' => '@/path/photo.png'])
    ->header('X-Request-Id', 'r-1')
    ->headers(['X-A' => '1', 'X-B: 2'])
    ->withoutHeader('User-Agent')
    ->bearer($token)                           // or ->basicAuth($user, $pass)
    ->cookie('session', $sid)
    ->proxy('http://127.0.0.1:8080')           // or ->withoutProxy()
    ->insecure()                               // skip TLS check; or ->verifySsl(true)
    ->followRedirects(true, 3)                 // or ->withoutRedirects()
    ->timeout(10)
    ->retries(3)
    ->saveTo('/tmp/file.pdf')                  // stream large downloads to disk
    ->send();                                  // throws on failure; ->trySend() never throws
```

All of these work the same on `HypCurl::get(...)`, on a `HypCurl::client()`, and inside an API class.

## Responses

Every value is available as a method or as a property, so `$res->body()` and `$res->body` are the same.

```php
$res->body();              // raw body (string)
$res->json();              // decoded JSON as array
$res->json('data.0.id');   // dot path into the JSON
$res->object();            // decoded JSON as stdClass
$res->status();            // 200 (0 if no response arrived)
$res->ok();                // 2xx and no error (alias: successful())
$res->failed();            // the opposite
$res->header('content-type');
$res->headers();           // all headers, lower-cased names
$res->cookie('session');   // a cookie set by this response
$res->cookies();
$res->time();              // seconds
$res->method();  $res->url();
$res->finalUrl();          // URL after redirects
$res->redirects();         // how many redirects were followed
$res->redirectUrl();       // Location of a 3xx that was NOT followed
$res->isRedirect();        // 3xx (only when not following)
$res->error();             // the HttpException, when sent with trySend() / 'throw' => false
$res->throw();             // throw that error now (returns $res if all is well)
$res->toArray();           // JSON-ready envelope (see Errors)
$res->toJson();
```

Calling a method that doesn't exist gives a hint instead of a bare fatal error, e.g. `getBody()` → *"Response has no method getBody(). Did you mean body()?"*.

## Errors

Every failure throws a structured exception with a readable message, a machine-readable `type`, and a hint on how to fix it. All of them extend `HttpException`:

```
HttpException
├─ NetworkException     no response: dns_error, connection_error, empty_response, too_many_redirects, invalid_url
│  ├─ TimeoutException  timeout
│  ├─ ProxyException    proxy_error
│  └─ SslException      ssl_error
├─ ClientException      4xx: bad_request, unauthorized, forbidden, not_found, validation_error, rate_limited, ...
└─ ServerException      5xx: server_error
```

```php
use Hyphun\HypCurl\Http\{HttpException, ClientException, TimeoutException, ProxyException};

try {
    $user = $api->get('/users/7')->send()->json();
} catch (ClientException $e) {
    if ($e->type === 'not_found') { /* ... */ }
} catch (TimeoutException | ProxyException $e) {
    // ...
} catch (HttpException $e) {         // everything else
    $e->type;        // 'server_error', 'dns_error', ...
    $e->reason;      // "503 Service Unavailable: Maintenance" (short, no URL)
    $e->getMessage();// reason + (METHOD url)
    $e->hint;        // what to check
    $e->status();    // HTTP status, or null if no response
    $e->response;    // Response for 4xx/5xx
    $e->retryable(); // temporary problem?
}
```

**JSON.** Exceptions and responses share one envelope:

```php
$e->toArray();                // full details: hint, request, curl code, upstream body (logs, development)
$e->toArray(debug: false);    // only type, message, status: safe to return to end users
$e->toJson();  json_encode($e);
```

```json
{
    "success": false,
    "error": {
        "type": "proxy_error",
        "message": "Proxy refused the tunnel to api.example.com (proxy answered HTTP 503)",
        "hint": "The proxy http://10.0.0.1:3128 could not reach api.example.com or is overloaded. ...",
        "request": { "method": "GET", "url": "https://api.example.com/users" },
        "curl": { "code": 56, "message": "CONNECT tunnel failed, response 503" }
    }
}
```

Success looks like `{"success": true, "status": 200, "data": {...}}`.

**Without exceptions.** `trySend()` never throws; check the result instead:

```php
$res = $api->get('/users')->trySend();

if ($res->ok()) {
    return $res->json();
}
$res->error->type;                     // the exception, if you need it
return $res->toArray(debug: false);    // or pass the error straight through as JSON
```

A client created with `'throw' => false` behaves the same way for every request.

**Uncaught errors become JSON.** Add this once at startup, and an uncaught HypCurl error becomes a clean JSON response instead of `Fatal error: Uncaught ...` with a stack trace:

```php
HypCurl::boot(['json_errors' => true]);                // production: type + message only
HypCurl::boot(['json_errors' => ['debug' => true]]);   // development: + hint, URL, curl, upstream body
// or HypCurl::jsonErrors(debug: false, allExceptions: false);
```

On the web it responds `502 Bad Gateway`, or `504` for timeouts, with `Content-Type: application/json`, and writes the full details to PHP's error log, so production responses can stay short without losing anything. In the CLI it prints the JSON and exits with code 1.

Setup mistakes (an unknown option, a misplaced key, a missing CA file) are `ConfigException`s. They are reported as `{"type": "config_error"}` with status 500, and the message explains the fix. Other exceptions go to your existing handler unless you pass `'all' => true`.

Secrets are hidden everywhere errors and logs appear: passwords in URLs, and `token`/`api_key`/`password`-style query parameters, are shown as `****`.

## Cookies: chained requests

```php
$api->post('/login')->form(['user' => $u, 'pass' => $p])->send();  // server sets a session cookie
$api->get('/dashboard')->send();                                    // the cookie is sent automatically

$api->sessionCookies();   // inspect
$api->clearCookies();     // reset
```

Across separate CLI runs, use a cookie jar file:

```bash
vendor/bin/hypcurl myapi login user=me pass=secret --jar
vendor/bin/hypcurl myapi dashboard --jar
```

## Headers

| Scope | How |
|---|---|
| Every API | `HYPCURL_HEADERS=X-App: my-app \| X-Env: dev` |
| One API | `'headers' => [...]` in `options()` |
| From now on | `$api->withHeader('X-Tenant', 'acme')`, `$api->withoutHeader('Accept')` |
| One request | `->header()`, `->headers()`, `->withoutHeader()` |
| CLI | `-H "X-Trace: 1"` (repeatable), `--no-header=User-Agent` |

Header names are matched case-insensitively. Values of `Authorization`, `Cookie`, `X-Api-Key` and similar headers are masked in logs.

## Proxy

Set it globally (`'defaults' => ['proxy' => ...]` or `HYPCURL_PROXY`), per API (`'proxy' => ...` in `options()`), at runtime (`$api->withProxy($url)`), or per request (`->proxy($url)`, `->withoutProxy()`).

## SSL / TLS

Certificates and hostnames are verified by default. Options:

| Scope | Verify on/off | Trust a custom CA |
|---|---|---|
| Everything | `'defaults' => ['verifySsl' => false]` or `HYPCURL_VERIFY_SSL=false` | `'defaults' => ['caBundle' => 'ca.pem']` or `HYPCURL_CA_BUNDLE` |
| One API / client | `'verifySsl' => false` in options, `$api->withSslVerification(false)` | `'caBundle' => 'ca.pem'` |
| One request | `->insecure()` / `->verifySsl(true)` | n/a |
| CLI | `-k` / `--insecure` | `--cacert=ca.pem` |

Only turn verification off for local or dev servers with self-signed certificates. For internal services, prefer `caBundle`, which keeps verification on and trusts your company's CA.

## Redirects

3xx redirects are followed by default, up to 10 hops, and only to `http`/`https` URLs: a redirect to `file://` and similar is blocked.

| Scope | How |
|---|---|
| Everything | `'defaults' => ['followRedirects' => false, 'maxRedirects' => 5]`, `HYPCURL_FOLLOW_REDIRECTS`, `HYPCURL_MAX_REDIRECTS` |
| One API / client | `'followRedirects' => false`, `'maxRedirects' => 3` in options, `$api->withRedirects(false)` |
| One request | `->withoutRedirects()`, `->followRedirects(true, 3)` |
| CLI | `--no-follow`, `--max-redirs=3` |

```php
$res = HypCurl::get('https://example.com/short-link')->withoutRedirects()->send();
$res->status;        // 301
$res->redirectUrl;   // where it points
```

## CLI reference

```
vendor/bin/hypcurl init
vendor/bin/hypcurl make:api [Name] [--namespace=App\Api] [--path=src/Api] [--force]
vendor/bin/hypcurl <service>                                   list methods
vendor/bin/hypcurl <service> <[resource.]method> [args...]     call a method

  value               positional parameter
  name=value          named parameter; unknown names go into the method's array parameter
                      (values are JSON-decoded: 1 -> int, true -> bool, '{"a":1}' -> array)
  --config=<file>     project file (default ./hypcurl.php)
  --env=<name>        environment
  -v / -q             debug (prints the equivalent curl command) / errors only
  --dry-run           print the curl command, don't send
  --raw, --headers    raw body / print response headers
  -H "N: v", --no-header=N
  --jar[=file], --no-cookies
  --proxy=<url>, --no-proxy
  --no-follow, --max-redirs=N
  -k / --insecure, --cacert=<file>
```

The demo services `httpbin` and `jsonplaceholder` are available until you set `'examples' => false` in `hypcurl.php`.

## Long-running processes

Each API client keeps one curl handle open, so connections are reused, and frees it automatically when the process ends. You never need to call `curl_close()`. In workers and in FrankenPHP or RoadRunner worker mode, call `HypCurl::boot()` again, or `MyApi::reset()`, between jobs or requests. Otherwise cookies and runtime headers carry over from one user to the next.

## Development

```bash
composer install
composer test        # PHPUnit; spins up a local server, no internet needed
php examples/direct_usage.php
php examples/global_settings.php
php examples/error_handling.php
php examples/demo_flow.php
php examples/cookie_flow.php
```

## License

MIT. See [LICENSE](LICENSE).
