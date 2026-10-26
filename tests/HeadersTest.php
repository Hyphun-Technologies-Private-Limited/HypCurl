<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Tests;

use Hyphun\HypCurl\Http\Headers;

final class HeadersTest extends TestCase
{
    public function testSetIsCaseInsensitive(): void
    {
        $headers = Headers::set(['Accept' => 'application/json'], 'accept', 'text/plain');

        $this->assertSame(['accept' => 'text/plain'], $headers);
    }

    public function testMergeLaterWins(): void
    {
        $merged = Headers::merge(['A' => '1', 'B' => '2'], ['b' => '3', 'C' => null]);

        $this->assertSame(['A' => '1', 'b' => '3', 'C' => null], $merged);
    }

    public function testParseSupportsPipeSeparatedAndMultipleArgs(): void
    {
        $this->assertSame(
            ['X-App' => 'demo', 'X-Env' => 'dev', 'X-Trace' => 'a:b'],
            Headers::parse('X-App: demo | X-Env: dev', 'X-Trace: a:b'),
        );
        $this->assertSame([], Headers::parse(''));
    }

    public function testParseRejectsMalformedHeader(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Headers::parse('oops');
    }

    public function testToCurlRendersRemovalAsEmptyHeader(): void
    {
        $this->assertSame(['A: 1', 'User-Agent:'], Headers::toCurl(['A' => '1', 'User-Agent' => null]));
    }
}
