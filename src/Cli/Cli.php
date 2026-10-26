<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Cli;

use Hyphun\HypCurl\Api\ApiClient;
use Hyphun\HypCurl\Api\Resource;
use Hyphun\HypCurl\Core\Config;
use Hyphun\HypCurl\Core\Logger;
use Hyphun\HypCurl\Examples\HttpBin\HttpBin;
use Hyphun\HypCurl\Examples\JsonPlaceholder\JsonPlaceholder;
use Hyphun\HypCurl\HypCurl;
use Hyphun\HypCurl\Http\Headers;
use Hyphun\HypCurl\Http\HttpException;
use Hyphun\HypCurl\Http\Response;

/**
 * Calls any service method from the terminal, mapping CLI args to method params via reflection.
 *
 *   vendor/bin/hypcurl <service> <[resource.]method> [positional...] [name=value...] [--flags]
 *
 * Services and settings come from hypcurl.php in the current directory (or --config=<file>).
 * name=value pairs that don't match a parameter name are collected into the
 * method's first array parameter (e.g. $filters, $data). Values are JSON-decoded
 * when possible, so userId=1 is an int and tags='["a","b"]' is an array.
 */
final class Cli
{
    public const PROJECT_FILE = 'hypcurl.php';

    /** Demo services, available unless the project file sets 'examples' => false. */
    private const EXAMPLES = [
        'httpbin' => HttpBin::class,
        'jsonplaceholder' => JsonPlaceholder::class,
    ];

    private bool $raw = false;
    private bool $showHeaders = false;
    private string $cwd;
    private ?string $projectFile = null;
    /** @var array<string, class-string<ApiClient>> */
    private array $services = [];

    public function __construct(?string $cwd = null)
    {
        $this->cwd = $cwd ?? (getcwd() ?: '.');
    }

    public function run(array $argv): int
    {
        try {
            [$positional, $named, $flags] = $this->parseArgv($argv);
            if (($positional[0] ?? null) === 'init') {
                return $this->init();
            }
            if (($positional[0] ?? null) === 'make:api') {
                return $this->makeApi($positional[1] ?? 'HypClass', $flags);
            }
            $this->loadProject($flags);
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");

            return 1;
        }

        $config = Config::instance();
        if (isset($flags['dry-run'])) {
            $config->set('HYPCURL_DRY_RUN', '1');
        }
        if (isset($flags['jar'])) {
            $config->set('HYPCURL_COOKIE_JAR', $flags['jar'] === true ? '.hypcurl/cookies.txt' : $flags['jar']);
        }
        if (isset($flags['no-cookies'])) {
            $config->set('HYPCURL_COOKIES', 'false');
        }
        if (isset($flags['proxy'])) {
            $config->set('HYPCURL_PROXY', (string) $flags['proxy']);
        }
        if (isset($flags['no-proxy'])) {
            $config->set('HYPCURL_PROXY', 'none');
        }
        if (isset($flags['v']) || isset($flags['verbose'])) {
            Logger::instance()->setLevel('DEBUG');
        }
        if (isset($flags['q']) || isset($flags['quiet'])) {
            Logger::instance()->setLevel('ERROR');
        }
        $this->raw = isset($flags['raw']);
        $this->showHeaders = isset($flags['headers']);

        $serviceName = $positional[0] ?? null;
        if ($serviceName === null || $serviceName === 'help' || isset($flags['help']) || isset($flags['h'])) {
            $this->printUsage();

            return 0;
        }
        $serviceClass = $this->services[$serviceName] ?? null;
        if ($serviceClass === null) {
            fwrite(STDERR, "Unknown service '$serviceName'. Available: " . implode(', ', array_keys($this->services)) . "\n");

            return 1;
        }
        if (!is_subclass_of($serviceClass, ApiClient::class)) {
            fwrite(STDERR, "Service '$serviceName' ($serviceClass) must be an autoloadable subclass of " . ApiClient::class . "\n");

            return 1;
        }

        $target = $positional[1] ?? null;
        if ($target === null) {
            $this->printServiceMethods($serviceName, $serviceClass);

            return 0;
        }

        try {
            $service = $serviceClass::instance();
            foreach (Headers::parse(...$flags['header']) as $name => $value) {
                $service->withHeader($name, $value);
            }
            foreach ($flags['no-header'] as $name) {
                $service->withoutHeader($name);
            }
            [$object, $method] = $this->resolve($service, $target);
            $args = $this->buildArgs($method, array_slice($positional, 2), $named);
            $this->output($method->invokeArgs($object, $args));

            return 0;
        } catch (HttpException $e) {
            // HttpClient already logged the failure; just show the error body if there is one.
            if ($e->response !== null) {
                $this->output($e->response);
            }

            return 1;
        } catch (\ArgumentCountError $e) {
            if (!isset($method)) {
                throw $e;
            }
            Logger::instance()->error("Missing arguments for '$target'. Signature:");
            $this->printMethod('', $method);

            return 1;
        } catch (\Throwable $e) {
            Logger::instance()->error($e->getMessage());

            return 1;
        }
    }

    /**
     * Load hypcurl.php (if any) and boot HypCurl with it. The file returns:
     *   ['services' => ['name' => Class::class], 'config_dir' => ..., 'env' => ..., 'values' => [...], 'examples' => bool]
     */
    private function loadProject(array $flags): void
    {
        $explicit = isset($flags['config']) && $flags['config'] !== true;
        $file = $explicit ? (string) $flags['config'] : $this->cwd . '/' . self::PROJECT_FILE;
        $project = [];
        if (is_file($file)) {
            $project = require $file;
            if (!is_array($project)) {
                throw new \RuntimeException("$file must return an array");
            }
            $this->projectFile = realpath($file) ?: $file;
        } elseif ($explicit) {
            throw new \RuntimeException("Config file not found: $file");
        }

        $options = array_diff_key($project, ['services' => true, 'examples' => true]);
        $options['base_path'] ??= $this->projectFile !== null ? dirname($this->projectFile) : $this->cwd;
        if (isset($flags['env']) && $flags['env'] !== true) {
            $options['env'] = (string) $flags['env'];
        }
        HypCurl::boot($options);

        $this->services = array_merge(
            ($project['examples'] ?? true) ? self::EXAMPLES : [],
            $project['services'] ?? [],
        );
    }

    /** Scaffold hypcurl.php + config/hypcurl/ in the current directory. Never overwrites. */
    private function init(): int
    {
        $stubs = dirname(__DIR__, 2) . '/stubs';
        $files = [
            'hypcurl.php' => "$stubs/hypcurl.php",
            'config/hypcurl/default.env' => "$stubs/config/default.env",
            'config/hypcurl/local.env.example' => "$stubs/config/local.env.example",
        ];
        foreach ($files as $target => $source) {
            $path = "{$this->cwd}/$target";
            if (file_exists($path)) {
                echo "  skip     $target (already exists)\n";
                continue;
            }
            if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true)) {
                fwrite(STDERR, "Cannot create " . dirname($path) . "\n");

                return 1;
            }
            copy($source, $path);
            echo "  created  $target\n";
        }
        echo <<<TXT

        Next steps:
          1. cp config/hypcurl/local.env.example config/hypcurl/local.env   (secrets)
          2. Add to .gitignore:  /config/hypcurl/local.env
          3. Generate your first API class:  vendor/bin/hypcurl make:api   (or make:api Payments)

        TXT;

        return 0;
    }

    /**
     * Generate an API class from stubs/HypClass.php.stub and register it in hypcurl.php.
     * Namespace/path come from the project's composer.json PSR-4 autoload (first entry + "\Api"),
     * or from --namespace=... / --path=...
     */
    private function makeApi(string $class, array $flags): int
    {
        if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $class)) {
            fwrite(STDERR, "Class name must be StudlyCase, e.g. Payments or HypClass\n");

            return 1;
        }
        [$namespace, $dir, $autoloaded] = $this->apiLocation($flags);
        $relative = "$dir/$class.php";
        $file = "{$this->cwd}/$relative";
        if (file_exists($file) && !isset($flags['force'])) {
            fwrite(STDERR, "$relative already exists (use --force to overwrite)\n");

            return 1;
        }

        $service = strtolower($class);
        $prefix = strtoupper(preg_replace('/(?<!^)[A-Z]/', '_$0', $class));
        $code = strtr((string) file_get_contents(dirname(__DIR__, 2) . '/stubs/HypClass.php.stub'), [
            '{{namespace}}' => $namespace,
            '{{class}}' => $class,
            '{{service}}' => $service,
            '{{prefix}}' => $prefix,
        ]);
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0755, true)) {
            fwrite(STDERR, 'Cannot create ' . dirname($file) . "\n");

            return 1;
        }
        file_put_contents($file, $code);
        echo "  created  $relative  ($namespace\\$class)\n";
        $registered = $this->registerService($service, "$namespace\\$class");

        echo "\nNext steps:\n";
        $step = 1;
        if (!$autoloaded) {
            echo '  ' . $step++ . ". Add to composer.json autoload.psr-4:  \"" . str_replace('\\', '\\\\', $namespace) . "\\\\\": \"$dir/\"  then: composer dump-autoload\n";
        }
        if (!$registered) {
            echo '  ' . $step++ . ". Add to 'services' in hypcurl.php:  '$service' => \\$namespace\\$class::class,  (or run: vendor/bin/hypcurl init)\n";
        }
        echo '  ' . $step++ . ". Set {$prefix}_BASE_URL (and {$prefix}_TOKEN) in config/hypcurl/default.env / local.env\n";
        echo '  ' . $step++ . ". Edit $relative: replace the example endpoints with your API's\n";
        echo '  ' . $step . ". Try it:  vendor/bin/hypcurl $service ping\n\n";

        return 0;
    }

    /** @return array{0: string, 1: string, 2: bool} namespace, relative dir, covered by composer autoload */
    private function apiLocation(array $flags): array
    {
        $namespace = isset($flags['namespace']) && $flags['namespace'] !== true ? trim((string) $flags['namespace'], '\\') : null;
        $dir = isset($flags['path']) && $flags['path'] !== true ? trim((string) $flags['path'], '/') : null;

        $composer = json_decode((string) @file_get_contents("{$this->cwd}/composer.json"), true);
        $psr4 = $composer['autoload']['psr-4'] ?? [];
        $baseNs = array_key_first($psr4);
        if ($baseNs !== null) {
            $baseDir = trim((string) (is_array($psr4[$baseNs]) ? $psr4[$baseNs][0] : $psr4[$baseNs]), '/');
            $namespace ??= trim($baseNs, '\\') . '\\Api';
            $dir ??= ($baseDir === '' ? '' : "$baseDir/") . 'Api';
        }
        $namespace ??= 'App\\Api';
        $dir ??= 'src/Api';

        // Is $namespace inside a PSR-4 mapping whose folder matches $dir?
        $autoloaded = false;
        foreach ($psr4 as $prefix => $paths) {
            $prefix = trim($prefix, '\\');
            foreach ((array) $paths as $path) {
                $path = trim($path, '/');
                $rest = trim(str_replace('\\', '/', substr($namespace, strlen($prefix))), '/');
                $expected = trim("$path/$rest", '/');
                if (($namespace === $prefix || str_starts_with($namespace, "$prefix\\")) && $expected === $dir) {
                    $autoloaded = true;
                }
            }
        }

        return [$namespace, $dir, $autoloaded];
    }

    /** Add 'name' => \Class::class to the services array in hypcurl.php. */
    private function registerService(string $service, string $fqcn): bool
    {
        $file = "{$this->cwd}/" . self::PROJECT_FILE;
        if (!is_file($file)) {
            return false;
        }
        $source = (string) file_get_contents($file);
        if (str_contains($source, "$fqcn::class")) {
            echo '  exists   ' . self::PROJECT_FILE . " already registers $fqcn\n";

            return true;
        }
        $updated = preg_replace(
            "/('services'\s*=>\s*\[)/",
            "$1\n        '$service' => \\\\" . str_replace('\\', '\\\\', $fqcn) . '::class,',
            $source,
            1,
            $count,
        );
        if ($count !== 1) {
            return false;
        }
        file_put_contents($file, $updated);
        echo '  updated  ' . self::PROJECT_FILE . "  ('$service' => \\$fqcn::class)\n";

        return true;
    }

    /** @return array{0: list<string>, 1: array<string, string>, 2: array<string, string|true>} */
    private function parseArgv(array $argv): array
    {
        $positional = $named = $flags = [];
        $flags['header'] = $flags['no-header'] = [];
        for ($i = 0; $i < count($argv); $i++) {
            $arg = $argv[$i];
            // Repeatable, curl-style: -H "X-A: 1"  --header "X-A: 1"  --header="X-A: 1"  --no-header=Accept
            if (preg_match('/^(-H|--header|--no-header)(?:=(.*))?$/s', $arg, $m)) {
                $key = $m[1] === '--no-header' ? 'no-header' : 'header';
                $value = $m[2] ?? $argv[++$i] ?? throw new \InvalidArgumentException("$arg needs a value");
                $flags[$key][] = $value;
            } elseif (preg_match('/^--?([\w-]+)(?:=(.*))?$/s', $arg, $m)) {
                $flags[$m[1]] = $m[2] ?? true;
            } elseif (preg_match('/^([A-Za-z_]\w*)=(.*)$/s', $arg, $m)) {
                $named[$m[1]] = $m[2];
            } else {
                $positional[] = $arg;
            }
        }

        return [$positional, $named, $flags];
    }

    /**
     * "posts.list" -> $service->posts()->list ; "headers" -> $service->headers
     *
     * @return array{0: object, 1: \ReflectionMethod}
     */
    private function resolve(ApiClient $service, string $target): array
    {
        $object = $service;
        $parts = explode('.', $target);
        $methodName = array_pop($parts);
        foreach ($parts as $accessor) {
            if (!$this->isCallable($object, $accessor)) {
                throw new \InvalidArgumentException("Unknown resource '$accessor'");
            }
            $object = $object->$accessor();
        }
        if (!$this->isCallable($object, $methodName)) {
            throw new \InvalidArgumentException("Unknown method '$target'. Run without a method to list them.");
        }

        return [$object, new \ReflectionMethod($object, $methodName)];
    }

    private function isCallable(object $object, string $method): bool
    {
        return method_exists($object, $method) && (new \ReflectionMethod($object, $method))->isPublic();
    }

    private function buildArgs(\ReflectionMethod $method, array $positional, array $named): array
    {
        $args = [];
        $collector = null; // first array param not given explicitly: receives leftover name=value pairs
        foreach ($method->getParameters() as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $named)) {
                $args[$name] = $this->cast($named[$name], $param);
                unset($named[$name]);
            } elseif ($positional !== []) {
                $args[$name] = $this->cast(array_shift($positional), $param);
            } elseif ($collector === null && $this->typeName($param) === 'array') {
                $collector = $name;
            }
        }
        if ($named !== []) {
            if ($collector === null) {
                throw new \InvalidArgumentException('Unknown argument(s): ' . implode(', ', array_keys($named)));
            }
            $args[$collector] = array_map(fn ($v) => $this->smartValue($v), $named);
        }
        if ($positional !== []) {
            throw new \InvalidArgumentException('Too many arguments: ' . implode(' ', $positional));
        }

        return $args;
    }

    private function cast(string $value, \ReflectionParameter $param): mixed
    {
        return match ($this->typeName($param)) {
            'int' => is_numeric($value) ? (int) $value : throw new \InvalidArgumentException("\${$param->getName()} must be an integer"),
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'array' => is_array($decoded = json_decode($value, true))
                ? $decoded
                : throw new \InvalidArgumentException("\${$param->getName()} must be a JSON object/array"),
            default => $value,
        };
    }

    /** "1" -> 1, "true" -> true, '["a"]' -> array, anything else stays a string. */
    private function smartValue(string $value): mixed
    {
        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    /** First non-null type name of a parameter (int|string -> "int"; untyped -> "mixed"). */
    private function typeName(\ReflectionParameter $param): string
    {
        $type = $param->getType();
        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }
        if ($type instanceof \ReflectionUnionType) {
            return $type->getTypes()[0]->getName();
        }

        return 'mixed';
    }

    private function output(mixed $result): void
    {
        if ($result instanceof Response) {
            if ($this->showHeaders) {
                foreach ($result->headers as $name => $value) {
                    fwrite(STDERR, "$name: $value\n");
                }
            }
            if ($result->body !== '') {
                echo ($this->raw ? $result->body : $result->pretty()), "\n";
            }

            return;
        }
        if ($result !== null) {
            echo is_scalar($result) ? $result : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
        }
    }

    // ---- Help ----

    private function printUsage(): void
    {
        $services = implode("\n", array_map(
            fn ($name) => sprintf('  %-18s %s', $name, isset(self::EXAMPLES[$name]) && self::EXAMPLES[$name] === $this->services[$name] ? '(example)' : $this->services[$name]),
            array_keys($this->services),
        ));
        $version = HypCurl::VERSION;
        $project = $this->projectFile ?? 'none (run `hypcurl init` to create ' . self::PROJECT_FILE . ')';
        echo <<<TXT
        HypCurl $version

        Usage:
          hypcurl init                                      Create hypcurl.php + config/hypcurl/ here
          hypcurl make:api [Name]                           Generate an API class (default: HypClass)
                                                            --namespace=App\\Api --path=src/Api --force
          hypcurl <service>                                 List a service's methods
          hypcurl <service> <[resource.]method> [args...]   Call a method

        Arguments:
          value          positional parameter, in order
          name=value     named parameter; unknown names go into the method's array param
                         values are JSON-decoded: 1 -> int, true -> bool, '{"a":1}' -> array

        Flags:
          --config=<f>   project file (default: ./hypcurl.php)
          --env=<name>   load <config_dir>/<name>.env (default: HYPCURL_ENV, APP_ENV or dev)
          -v, --verbose  debug logs, including the equivalent curl command
          -q, --quiet    errors only
          --dry-run      print the curl command, don't send
          --raw          print the body as-is (no pretty JSON)
          --headers      print response headers (to stderr)
          -H "Name: v"   add/override a request header (repeatable; also --header="Name: v")
          --no-header=N  don't send header N (e.g. Accept, User-Agent)
          --jar[=file]   save/load cookies in a file so separate runs share a session
                         (default file: .hypcurl/cookies.txt)
          --no-cookies   don't keep a cookie session
          --proxy=<url>  send through a proxy, e.g. http://127.0.0.1:8080 or socks5h://host:1080
          --no-proxy     never use a proxy (ignores http_proxy env vars too)

        Project file: $project

        Services:
        $services

        Examples:
          hypcurl jsonplaceholder posts.list userId=1
          hypcurl jsonplaceholder posts.find 1
          hypcurl jsonplaceholder posts.create title="Hello" body="World" userId=1
          hypcurl httpbin status 503 retries=2 -v
          hypcurl httpbin headers -H "X-Trace-Id: 42" -H "Accept-Language: en"
          hypcurl httpbin setCookies session=abc123 --jar   # 1st run stores the cookie
          hypcurl httpbin cookies --jar                     # 2nd run sends it back

        TXT;
    }

    private function printServiceMethods(string $name, string $class): void
    {
        echo "Methods for '$name' ($class):\n\n";
        foreach ($this->publicMethods($class, ApiClient::class) as $method) {
            $returnType = $method->getReturnType();
            $returnClass = $returnType instanceof \ReflectionNamedType ? $returnType->getName() : '';
            if (is_subclass_of($returnClass, Resource::class)) {
                foreach ($this->publicMethods($returnClass, Resource::class) as $resourceMethod) {
                    $this->printMethod($method->getName() . '.', $resourceMethod);
                }
            } else {
                $this->printMethod('', $method);
            }
        }
    }

    /** @return list<\ReflectionMethod> public methods declared below $stopAt in the hierarchy */
    private function publicMethods(string $class, string $stopAt): array
    {
        $methods = array_filter(
            (new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn (\ReflectionMethod $m) => !$m->isStatic()
                && !$m->isConstructor()
                && is_subclass_of($m->getDeclaringClass()->getName(), $stopAt),
        );

        return array_values($methods);
    }

    private function printMethod(string $prefix, \ReflectionMethod $method): void
    {
        $params = array_map(function (\ReflectionParameter $p) {
            $type = $p->getType() ? $p->getType() . ' ' : '';
            $default = $p->isDefaultValueAvailable()
                ? ' = ' . str_replace(["\n", 'array (', ')', 'NULL'], ['', '[', ']', 'null'], var_export($p->getDefaultValue(), true))
                : '';

            return "$type\${$p->getName()}$default";
        }, $method->getParameters());

        $doc = '';
        if (preg_match('#/\*\*\s*\*?\s*(.+?)\s*(\*/|\n)#', (string) $method->getDocComment(), $m)) {
            $doc = '  — ' . $m[1];
        }
        printf("  %-55s%s\n", $prefix . $method->getName() . '(' . implode(', ', $params) . ')', $doc);
    }
}
