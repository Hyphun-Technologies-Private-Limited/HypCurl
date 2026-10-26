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
