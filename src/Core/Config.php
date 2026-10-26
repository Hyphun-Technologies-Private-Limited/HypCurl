<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Core;

/**
 * HypCurl configuration, loaded once.
 *
 * Priority (highest wins):
 *   1. Values set in code         HypCurl::boot(['values' => [...]]) or Config::instance()->set()
 *   2. Environment variables      e.g. HYPCURL_TIMEOUT=10 php app.php
 *   3. {config_dir}/local.env     secrets, git-ignored
 *   4. {config_dir}/{env}.env     env = boot 'env' option, HYPCURL_ENV, APP_ENV, or "dev"
 *   5. {config_dir}/default.env
 *   6. Config::DEFAULTS
 *
 * All built-in keys use the HYPCURL_ prefix. Never use HTTP_* names for settings:
 * under CGI/FPM, request headers appear as HTTP_* variables ("httpoxy").
 */
final class Config
{
    use Singleton;

    public const DEFAULTS = [
        'HYPCURL_LOG_LEVEL' => 'INFO',
        'HYPCURL_TIMEOUT' => '30',
        'HYPCURL_CONNECT_TIMEOUT' => '10',
        'HYPCURL_RETRIES' => '0',
        'HYPCURL_RETRY_DELAY_MS' => '500',
        'HYPCURL_VERIFY_SSL' => 'true',
        'HYPCURL_USER_AGENT' => 'HypCurl/1.0',
        'HYPCURL_DRY_RUN' => 'false',
        'HYPCURL_HEADERS' => '',
        'HYPCURL_COOKIES' => 'true',
        'HYPCURL_COOKIE_JAR' => '',
        'HYPCURL_PROXY' => '',
        'HYPCURL_NO_PROXY' => '',
    ];

    /** @var array{config_dir?: ?string, env?: ?string, values?: array<string, scalar>, base_path?: ?string} */
    private static array $options = [];

    /** @var array<string, string> from DEFAULTS and .env files */
    private array $values;
    /** @var array<string, string> set in code; beat everything else */
    private array $overrides = [];
    private string $env;
    private string $basePath;

    private function __construct()
    {
        $options = self::$options;
        $this->env = $options['env'] ?? self::fromEnvironment('HYPCURL_ENV') ?? self::fromEnvironment('APP_ENV') ?? 'dev';
        $this->basePath = rtrim($options['base_path'] ?? (getcwd() ?: '.'), '/');

        $this->values = self::DEFAULTS;
        $dir = $options['config_dir'] ?? null;
        if ($dir !== null) {
            foreach (["$dir/default.env", "$dir/{$this->env}.env", "$dir/local.env"] as $file) {
                $this->values = array_merge($this->values, self::parseEnvFile($file));
            }
        }
        foreach ($options['values'] ?? [] as $key => $value) {
            $this->set((string) $key, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }
    }

    /**
     * Set the options used when the config is (re)built, and drop the current instance.
     * Prefer HypCurl::boot(), which also resets the logger and API clients.
     */
    public static function configure(array $options): void
    {
        self::$options = $options;
        self::reset();
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (isset($this->overrides[$key])) {
            return $this->overrides[$key];
        }
        $value = self::fromEnvironment($key) ?? ($this->values[$key] ?? '');

        return $value !== '' ? $value : $default;
    }

    public function require(string $key): string
    {
        return $this->get($key)
            ?? throw new \RuntimeException("Missing required config: $key (set it in your config dir's local.env, the environment, or HypCurl::boot values)");
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) ($this->get($key) ?? $default);
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** Override a value at runtime. */
    public function set(string $key, string $value): void
    {
        $this->overrides[$key] = $value;
    }

    public function env(): string
    {
        return $this->env;
    }

    /** Resolve a path relative to the project base path (absolute paths are returned as-is). */
    public function path(string $path): string
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path)
            ? $path
            : $this->basePath . '/' . $path;
    }

    private static function fromEnvironment(string $key): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);

        return ($value === false || $value === '' || !is_scalar($value)) ? null : (string) $value;
    }

    /** @return array<string, string> */
    private static function parseEnvFile(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $values = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (preg_match('/^([\'"])(.*)\1$/', $value, $m)) {
                $value = $m[2];
            }
            $values[$key] = $value;
        }

        return $values;
    }
}
