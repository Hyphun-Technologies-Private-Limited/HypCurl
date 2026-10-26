# Changelog

## 1.6.0 - 2026-10-26

- Read one cookie from a client's session by name: `$client->cookie('name', ?domain)`, `$api->sessionCookie('name', ?domain)`, `HypCurl::cookie('name', ?domain)`; plus `HypCurl::cookies()` for the shortcut client
- README: how to read cookie values (per response vs whole session)

## 1.5.0 - 2026-10-26

- `Response` method accessors for every value: `body()`, `status()`, `headers()`, `cookies()`, `time()`, `method()`, `url()`, `finalUrl()`, `redirects()`, `redirectUrl()`, `error()`; plus `successful()` (alias of `ok()`) and `object()` (JSON as stdClass). Properties keep working
- Calling an unknown method on `Response` / `Request` throws a `BadMethodCallException` with suggestions (`getBody()` → `body()`, `getStatusCode()` → `status()`, typos) or a note when a property was called like a method

## 1.4.3 - 2026-10-26

- Fixed: with `json_errors` enabled, an uncaught non-HypCurl error (e.g. calling an undefined function) caused infinite recursion ("Maximum call stack size ... reached") instead of showing the real error. Such errors now go to your previous exception handler, or are reported exactly like PHP's default "Fatal error: Uncaught ..." (honouring display_errors / log_errors, exit code 255)
- Process-level regression tests for the JSON error handler

## 1.4.2 - 2026-10-26

- Setup mistakes throw `Core\ConfigException` (still an `InvalidArgumentException`) with clear fixes: misplaced keys say where they belong (`json_errors` inside `defaults`, `proxy` at the top level of `boot()`, `baseUrl` in `defaults`), typos get "did you mean" suggestions
- `boot()` now validates its own keys
- `json_errors` is registered before anything else in `boot()`, so setup mistakes are reported as JSON (`config_error`, HTTP 500) too
- On the web, the JSON error handler also writes the full error details to PHP's error log

## 1.4.1 - 2026-10-26

- README: table of contents, requirements, complete `boot()` options, `Response` error API (`error`, `failed()`, `throw()`), `trySend()` in the request reference, typed-exception example, accurate retry description

## 1.4.0 - 2026-10-26

- Structured, readable errors: `HttpException` subclasses `NetworkException`, `TimeoutException`, `ProxyException`, `SslException`, `ClientException` (4xx), `ServerException` (5xx), each with `type`, `reason`, `hint`, `status()`, `retryable()`, curl code/message
- Plain-language messages instead of raw curl errors (e.g. "Proxy refused the tunnel to api.example.com (proxy answered HTTP 503)"); upstream error messages are extracted from JSON bodies
- JSON everywhere: `$e->toArray()/toJson()`, `json_encode($e)`, `$res->toArray()/toJson()` with a `debug: false` mode that is safe for end users
- `->trySend()` never throws; `$res->error` holds the failure
- `HypCurl::jsonErrors()` / `boot(['json_errors' => ...])`: uncaught HypCurl errors render as JSON (502/504 on the web, exit 1 in the CLI)
- Retries skip non-temporary failures (TLS errors, redirect loops, invalid URLs)
- Secrets in URLs (passwords, token/api_key-style query params) are masked in errors, logs and printed curl commands
- CLI prints the JSON error on failure
- Changed: a client with `'throw' => false` now also returns (instead of throwing) on network errors; a missing `caBundle` file throws `InvalidArgumentException`

## 1.3.0 - 2026-10-26

- Global settings: `HypCurl::boot(['defaults' => [...]])` / `HypCurl::defaults([...])` apply proxy, TLS, redirects, timeouts, retries, headers, cookies to every client; client options and per-request calls still win; unknown keys are rejected
- TLS: per-request `->insecure()` / `->verifySsl()`, `caBundle` option (`HYPCURL_CA_BUNDLE`), `$api->withSslVerification()`, CLI `-k` / `--cacert`
- Redirects: `maxRedirects` option (`HYPCURL_MAX_REDIRECTS`), `HYPCURL_FOLLOW_REDIRECTS`, per-request `->followRedirects()` / `->withoutRedirects()`, `$api->withRedirects()`, CLI `--no-follow` / `--max-redirs`; redirects restricted to http/https
- Response: `finalUrl`, `redirects`, `redirectUrl`, `isRedirect()`
- Printed curl commands now include `-L`, `--max-redirs`, `-k`, `--cacert`
- New example: `examples/global_settings.php`

## 1.2.0 - 2026-10-26

- Direct use without an API class: `HypCurl::get/post/put/patch/delete/request()` with full URLs (shared client that keeps cookies) and `HypCurl::client([...])` for a configured client inside existing classes
- Relative paths on a client without `baseUrl` now fail with a clear error
- New example: `examples/direct_usage.php`

## 1.1.0 - 2026-10-26

- `hypcurl make:api [Name]`: generates a ready-to-edit API class (default `HypClass`) from a template, using the project's PSR-4 namespace, and registers it in `hypcurl.php`
- `hypcurl init` now points to `make:api` as the next step

## 1.0.0 - 2026-10-26

Initial release.

- `ApiClient` base class (singleton per API), `Resource` and `CrudResource`
- Fluent `Request` builder: query, JSON/form/raw/multipart bodies, auth, cookies, proxy, timeout, retries, downloads
- `HttpClient` on ext-curl: connection reuse, retries with exponential backoff, cookie sessions and cookie jars, proxies, layered custom headers
- `Response`: dot-path JSON access, headers, cookies, `throw()`
- Config from `.env` files, environment variables and code (`HYPCURL_*` keys), with an optional PSR-3 logger
- `hypcurl` CLI: `init`, method listing via reflection, flags for headers, cookies, proxy, dry-run and debug
