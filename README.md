# HypCurl

Organised, reusable cURL toolkit for PHP 8.1+. Define each external API once as a small class, then call it from code or the terminal. Retries, timeouts, cookies, proxies, custom headers and logging are handled in one place.

- **Singleton API clients:** `GitHub::instance()->repos()->find('owner/repo')`
- **Fluent requests:** `$api->post('/orders')->json($data)->bearer($token)->retries(2)->send()`
- **REST resources with no code:** `list`, `find`, `create`, `update`, `patch`, `delete`
- **Cookie sessions:** kept in memory, or in a cookie-jar file that persists between runs
- **Proxy support:** HTTP, HTTPS and SOCKS, with credentials masked in logs
- **Custom headers:** layered (global → per-API → per-request), and you can remove any header
- **CLI:** call any API method with `vendor/bin/hypcurl`, and see the equivalent `curl` command with `-v`
- **No dependencies** beyond `ext-curl`. Optionally logs through any PSR-3 logger.

## Installation

```bash
composer require hyphun/hypcurl
```

## Quick start

### 1. Define an API

```php
namespace App\Api;

use Hyphun\HypCurl\Api\ApiClient;
use Hyphun\HypCurl\Api\CrudResource;
use Hyphun\HypCurl\Core\Config;
use Hyphun\HypCurl\Http\Response;

final class GitHub extends ApiClient
{
    protected function options(): array
    {
        $config = Config::instance();

        return [
            'baseUrl' => $config->get('GITHUB_BASE_URL', 'https://api.github.com'),
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'Authorization' => 'Bearer ' . $config->require('GITHUB_TOKEN'),
            ],
            'retries' => 2,
        ];
    }

    /** Standard REST resource: list/find/create/update/patch/delete. */
    public function gists(): CrudResource
    {
        return $this->resource(CrudResource::class, '/gists');
    }

    /** One-off endpoint. */
    public function user(string $username): Response
    {
        return $this->get('/users/' . rawurlencode($username))->send();
    }
}
```

### 2. Use it

```php
use App\Api\GitHub;

$github = GitHub::instance();               // same instance everywhere

$name  = $github->user('octocat')->json('name');
$gists = $github->gists()->list(['per_page' => 5])->json();
$new   = $github->gists()->create(['public' => false, 'files' => [...]]);
```

### 3. Or call it from the terminal

```bash
vendor/bin/hypcurl init          # creates hypcurl.php + config/hypcurl/
# register 'github' => App\Api\GitHub::class in hypcurl.php, then:
vendor/bin/hypcurl github                        # list methods
vendor/bin/hypcurl github user octocat
vendor/bin/hypcurl github gists.list per_page=5 -v
```

## Configuration

HypCurl works without any setup. It reads `HYPCURL_*` environment variables and falls back to built-in defaults. To use config files, or to plug in your app's logger, call `boot()` once at startup:

```php
use Hyphun\HypCurl\HypCurl;

HypCurl::boot([
    'config_dir' => __DIR__ . '/config/hypcurl',   // default.env, {env}.env, local.env
    'env'        => 'prod',                        // default: HYPCURL_ENV, APP_ENV, or "dev"
    'values'     => ['HYPCURL_TIMEOUT' => 15],     // highest priority
    'base_path'  => __DIR__,                       // for relative paths (cookie jar); default: cwd
    'logger'     => $monolog,                      // any PSR-3 logger
]);
```

Priority, highest first: `values` → environment variables → `local.env` → `{env}.env` → `default.env` → built-in defaults.

| Key | Default | Meaning |
|---|---|---|
| `HYPCURL_TIMEOUT` | `30` | Total request timeout (s) |
| `HYPCURL_CONNECT_TIMEOUT` | `10` | Connect timeout (s) |
| `HYPCURL_RETRIES` | `0` | Retries on network error, 429, 5xx |
| `HYPCURL_RETRY_DELAY_MS` | `500` | First retry delay, doubles each retry |
| `HYPCURL_VERIFY_SSL` | `true` | TLS verification |
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
    ->timeout(10)
    ->retries(3)
    ->saveTo('/tmp/file.pdf')                  // stream large downloads to disk
    ->send();
```

Any `HttpClient` works without defining an `ApiClient`:

```php
$http = new \Hyphun\HypCurl\Http\HttpClient(['baseUrl' => 'https://example.com']);
$http->get('/status')->send();
```

## Responses

```php
$res->status;              // 200
$res->ok();                // 2xx
$res->json();              // decoded body
$res->json('data.0.id');   // dot path
$res->header('content-type');
$res->cookie('session');   // cookie set by this response
$res->body;  $res->time;  $res->url;
```

4xx and 5xx responses throw `Hyphun\HypCurl\Http\HttpException`, and `$e->response` holds the response. Network errors and timeouts throw the same exception, with `$e->response === null`. To get the response back instead of an exception, pass `'throw' => false` in the client options.

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

Set it globally (`HYPCURL_PROXY`), per API (`'proxy' => ...` in `options()`), at runtime (`$api->withProxy($url)`), or per request (`->proxy($url)`, `->withoutProxy()`).

## CLI reference

```
vendor/bin/hypcurl init
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
```

The demo services `httpbin` and `jsonplaceholder` are available until you set `'examples' => false` in `hypcurl.php`.

## Long-running processes

Each API client keeps one curl handle open, so connections are reused, and frees it automatically when the process ends. You never need to call `curl_close()`. In workers and in FrankenPHP or RoadRunner worker mode, call `HypCurl::boot()` again, or `MyApi::reset()`, between jobs or requests. Otherwise cookies and runtime headers carry over from one user to the next.

## Development

```bash
composer install
composer test        # PHPUnit; spins up a local server, no internet needed
php examples/demo_flow.php
php examples/cookie_flow.php
```

## License

MIT. See [LICENSE](LICENSE).
