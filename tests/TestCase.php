<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Tests;

use Hyphun\HypCurl\HypCurl;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        HypCurl::boot(['values' => ['HYPCURL_LOG_LEVEL' => 'OFF']]);
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/hypcurl-test-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);

        return $dir;
    }
}
