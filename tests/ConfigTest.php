<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Tests;

use Hyphun\HypCurl\Core\Config;
use Hyphun\HypCurl\HypCurl;

final class ConfigTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = $this->tempDir();
        file_put_contents("$this->dir/default.env", "A=default\nB=default\nC=default\nD=default\n# comment\nQ=\"quoted\"\n");
        file_put_contents("$this->dir/prod.env", "B=prod\nC=prod\nD=prod\n");
        file_put_contents("$this->dir/local.env", "C=local\nD=local\n");
    }

    protected function tearDown(): void
    {
        putenv('D');
        array_map('unlink', glob("$this->dir/*"));
        rmdir($this->dir);
    }

    public function testPriorityDefaultsFilesEnvironmentValues(): void
    {
        putenv('D=environment');
        HypCurl::boot(['config_dir' => $this->dir, 'env' => 'prod', 'values' => ['E' => 'code']]);
        $config = Config::instance();

        $this->assertSame('default', $config->get('A'));
        $this->assertSame('prod', $config->get('B'));
        $this->assertSame('local', $config->get('C'));
        $this->assertSame('environment', $config->get('D'));
        $this->assertSame('code', $config->get('E'));
        $this->assertSame('quoted', $config->get('Q'));
        $this->assertSame('30', $config->get('HYPCURL_TIMEOUT'), 'built-in default');
    }

    public function testCodeValuesBeatEnvironment(): void
    {
        putenv('D=environment');
        HypCurl::boot(['config_dir' => $this->dir, 'values' => ['D' => 'code']]);

        $this->assertSame('code', Config::instance()->get('D'));
    }

    public function testTypedGettersAndRequire(): void
    {
        HypCurl::boot(['values' => ['N' => '42', 'FLAG' => true]]);
        $config = Config::instance();

        $this->assertSame(42, $config->int('N'));
        $this->assertTrue($config->bool('FLAG'));
        $this->assertSame('fallback', $config->get('MISSING', 'fallback'));

        $this->expectException(\RuntimeException::class);
        $config->require('MISSING');
    }

    public function testRelativePathsResolveFromBasePath(): void
    {
        HypCurl::boot(['base_path' => '/srv/app']);

        $this->assertSame('/srv/app/.hypcurl/cookies.txt', Config::instance()->path('.hypcurl/cookies.txt'));
        $this->assertSame('/tmp/jar.txt', Config::instance()->path('/tmp/jar.txt'));
    }
}
