<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Tests;

use Hyphun\HypCurl\Cli\Cli;
use Hyphun\HypCurl\Tests\Fixtures\TestApi;

final class CliTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->tempDir();
        file_put_contents("$this->dir/hypcurl.php", '<?php return ' . var_export([
            'services' => ['test' => TestApi::class],
            'values' => ['HYPCURL_LOG_LEVEL' => 'OFF'],
            'examples' => false,
        ], true) . ';');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @return array{0: int, 1: mixed} exit code and decoded stdout */
    private function cli(string ...$argv): array
    {
        ob_start();
        $code = (new Cli($this->dir))->run($argv);
        $out = (string) ob_get_clean();

        return [$code, json_decode($out, true) ?? $out];
    }

    public function testPositionalAndCollectedNamedArgs(): void
    {
        [$code, $out] = $this->cli('test', 'search', 'php', 'page=2', 'tags=["a"]');

        $this->assertSame(0, $code);
        $this->assertSame(['q' => 'php', 'page' => '2', 'tags' => ['a']], $out['query']);
    }

    public function testResourceMethodWithJsonBodyAndHeaders(): void
    {
        [$code, $out] = $this->cli('test', 'items.create', 'name=Pen', 'price=9.5', '-H', 'X-Trace: 1', '--header=X-Other: 2');

        $this->assertSame(0, $code);
        $this->assertSame('{"name":"Pen","price":9.5}', $out['body']);
        $this->assertSame('1', $out['headers']['x-trace']);
        $this->assertSame('2', $out['headers']['x-other']);
    }

    public function testErrorsReturnNonZero(): void
    {
        $this->assertSame(1, $this->cli('test', 'items.find')[0], 'missing argument');
        $this->assertSame(1, $this->cli('test', 'nope')[0], 'unknown method');
        $this->assertSame(1, $this->cli('missing')[0], 'unknown service');
        $this->assertSame(1, $this->cli('test', 'items.find', '1', '--config=/nope.php')[0], 'missing config file');
    }

    public function testListsMethods(): void
    {
        [$code, $out] = $this->cli('test');

        $this->assertSame(0, $code);
        $this->assertStringContainsString('items.find(string|int $id)', $out);
        $this->assertStringContainsString('search(string $term, array $filters = [])', $out);
    }

    public function testMakeApiGeneratesWorkingClassAndRegistersIt(): void
    {
        file_put_contents("$this->dir/composer.json", json_encode(['autoload' => ['psr-4' => ['Acme\\' => 'src/']]]));
        file_put_contents("$this->dir/hypcurl.php", <<<PHP
            <?php
            return [
                'services' => [
                ],
                'values' => ['HYPCURL_LOG_LEVEL' => 'OFF', 'HYP_CLASS_BASE_URL' => '{$this->server()}'],
                'examples' => false,
            ];
            PHP);

        [$code, $out] = $this->cli('make:api');
        $file = "$this->dir/src/Api/HypClass.php";

        $this->assertSame(0, $code);
        $this->assertStringContainsString('created  src/Api/HypClass.php  (Acme\Api\HypClass)', $out);
        $this->assertStringNotContainsString('composer dump-autoload', $out, 'namespace is already covered by PSR-4');
        $this->assertStringContainsString("namespace Acme\\Api;", (string) file_get_contents($file));
        $this->assertStringContainsString("'hypclass' => \\Acme\\Api\\HypClass::class,", (string) file_get_contents("$this->dir/hypcurl.php"));

        // The generated class works as-is.
        require $file;
        [$code, $out] = $this->cli('hypclass', 'search', 'php', 'page=2');
        $this->assertSame(0, $code);
        $this->assertSame('/anything/search', $out['path']);
        $this->assertSame(['q' => 'php', 'page' => '2'], $out['query']);

        $this->assertSame(1, $this->cli('make:api')[0], 'refuses to overwrite');
        $this->assertSame(0, $this->cli('make:api', '--force')[0]);
        $this->assertSame(1, $this->cli('make:api', 'bad-name')[0]);
    }

    public function testMakeApiWithCustomNamespaceOutsideAutoload(): void
    {
        [$code, $out] = $this->cli('make:api', 'Payments', '--namespace=Billing\Clients', '--path=lib/Clients');

        $this->assertSame(0, $code);
        $this->assertFileExists("$this->dir/lib/Clients/Payments.php");
        $this->assertStringContainsString('composer dump-autoload', $out);
        $this->assertStringContainsString('PAYMENTS_BASE_URL', $out);
    }

    private function server(): string
    {
        return HYPCURL_TEST_SERVER;
    }

    public function testInitScaffoldsWithoutOverwriting(): void
    {
        unlink("$this->dir/hypcurl.php");
        [$code] = $this->cli('init');

        $this->assertSame(0, $code);
        $this->assertFileExists("$this->dir/hypcurl.php");
        $this->assertFileExists("$this->dir/config/hypcurl/default.env");

        [, $out] = $this->cli('init');
        $this->assertStringContainsString('skip     hypcurl.php', $out);
    }
}
