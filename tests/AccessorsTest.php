<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Tests;

use Hyphun\HypCurl\Http\HttpClient;
use Hyphun\HypCurl\Http\Response;

final class AccessorsTest extends TestCase
{
    public function testMethodAccessorsMatchProperties(): void
    {
        $res = new Response('POST', 'http://x/a', 201, ['content-type' => 'application/json'], '{"id":7,"tags":["a"]}', 0.25,
            ['s' => '1'], 'http://x/b', 1, null);

        $this->assertSame($res->body, $res->body());
        $this->assertSame(201, $res->status());
        $this->assertSame('POST', $res->method());
        $this->assertSame(['content-type' => 'application/json'], $res->headers());
        $this->assertSame(['s' => '1'], $res->cookies());
        $this->assertSame(0.25, $res->time());
        $this->assertSame('http://x/a', $res->url());
        $this->assertSame('http://x/b', $res->finalUrl());
        $this->assertSame(1, $res->redirects());
        $this->assertNull($res->redirectUrl());
        $this->assertNull($res->error());
        $this->assertTrue($res->successful());
        $this->assertSame(7, $res->object()->id);
        $this->assertSame(['a'], $res->object()->tags);
    }

    public function testFinalUrlFallsBackToUrl(): void
    {
        $this->assertSame('http://x', (new Response('GET', 'http://x', 200, [], '', 0.0))->finalUrl());
        $this->assertNull((new Response('GET', 'http://x', 200, [], 'not json', 0.0))->object());
    }

    public function testUnknownMethodsGiveHelpfulMessages(): void
    {
        $res = new Response('GET', 'http://x', 200, [], '{}', 0.0);

        $this->assertMessage('Did you mean body()?', fn () => $res->getBody());
        $this->assertMessage('Did you mean status()?', fn () => $res->getStatusCode());
        $this->assertMessage('Did you mean json()?', fn () => $res->jsn());

        $request = (new HttpClient())->get('https://x');
        $this->assertMessage('Did you mean proxy()?', fn () => $request->proxyy('a'));
        $this->assertMessage("'path' is a property: use ->path without parentheses.", fn () => $request->path());
    }

    private function assertMessage(string $expected, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected BadMethodCallException');
        } catch (\BadMethodCallException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }
}
