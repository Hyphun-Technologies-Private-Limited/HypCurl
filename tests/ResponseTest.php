<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Tests;

use Hyphun\HypCurl\Http\HttpException;
use Hyphun\HypCurl\Http\Response;

final class ResponseTest extends TestCase
{
    private function response(int $status, string $body, array $headers = [], array $cookies = []): Response
    {
        return new Response('GET', 'http://x/y', $status, $headers, $body, 0.1, $cookies);
    }

    public function testJsonDotPath(): void
    {
        $res = $this->response(200, '{"data":[{"id":7,"tags":["a"]}]}');

        $this->assertTrue($res->isJson());
        $this->assertSame(7, $res->json('data.0.id'));
        $this->assertSame('a', $res->json('data.0.tags.0'));
        $this->assertSame('none', $res->json('data.5.id', 'none'));
    }

    public function testNonJsonBody(): void
    {
        $res = $this->response(200, '<html>');

        $this->assertFalse($res->isJson());
        $this->assertNull($res->json());
        $this->assertSame('<html>', $res->pretty());
    }

    public function testHeadersAndCookies(): void
    {
        $res = $this->response(200, '', ['content-type' => 'text/plain'], ['session' => 'abc']);

        $this->assertSame('text/plain', $res->header('Content-Type'));
        $this->assertSame('abc', $res->cookie('session'));
        $this->assertNull($res->cookie('nope'));
    }

    public function testThrowOnFailure(): void
    {
        $this->assertTrue($this->response(204, '')->throw()->ok());

        try {
            $this->response(422, '{"error":"bad"}')->throw();
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame('bad', $e->response?->json('error'));
        }
    }
}
