# Changelog

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
