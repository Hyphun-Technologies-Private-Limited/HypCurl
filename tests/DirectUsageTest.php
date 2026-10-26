<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Tests;

use Hyphun\HypCurl\Http\HttpException;
use Hyphun\HypCurl\HypCurl;

final class DirectUsageTest extends TestCase
{
    public function testStaticShortcutsWithFullUrls(): void
    {
        $get = HypCurl::get(HYPCURL_TEST_SERVER . '/echo')->query(['a' => 1])->send();
        $this->assertSame(['a' => '1'], $get->json('query'));

        $post = HypCurl::post(HYPCURL_TEST_SERVER . '/echo')->json(['x' => 1])->send();
        $this->assertSame('POST', $post->json('method'));
        $this->assertSame('{"x":1}', $post->json('body'));

        $this->assertSame('PUT', HypCurl::put(HYPCURL_TEST_SERVER . '/echo')->send()->json('method'));
        $this->assertSame('PATCH', HypCurl::patch(HYPCURL_TEST_SERVER . '/echo')->send()->json('method'));
        $this->assertSame('DELETE', HypCurl::delete(HYPCURL_TEST_SERVER . '/echo')->send()->json('method'));
        $this->assertSame('OPTIONS', HypCurl::request('OPTIONS', HYPCURL_TEST_SERVER . '/echo')->send()->json('method'));
    }

    public function testSharedClientKeepsCookiesUntilBoot(): void
    {
        HypCurl::get(HYPCURL_TEST_SERVER . '/set-cookie')->query(['s' => '1'])->send();
        $this->assertSame(['s' => '1'], HypCurl::get(HYPCURL_TEST_SERVER . '/echo')->send()->json('cookies'));
        $this->assertSame(HypCurl::http(), HypCurl::http());

        HypCurl::boot(['values' => ['HYPCURL_LOG_LEVEL' => 'OFF']]);
        $this->assertSame([], HypCurl::get(HYPCURL_TEST_SERVER . '/echo')->send()->json('cookies'));
    }

    public function testConfiguredClientsAreIndependent(): void
    {
        $a = HypCurl::client(['baseUrl' => HYPCURL_TEST_SERVER, 'headers' => ['X-Who' => 'a']]);
        $b = HypCurl::client(['baseUrl' => HYPCURL_TEST_SERVER, 'headers' => ['X-Who' => 'b'], 'throw' => false]);

        $this->assertNotSame($a, $b);
        $this->assertSame('a', $a->get('/echo')->send()->json('headers.x-who'));
        $this->assertSame('b', $b->get('/echo')->send()->json('headers.x-who'));
        $this->assertSame(500, $b->get('/status/500')->send()->status);

        $this->expectException(HttpException::class);
        $a->get('/status/500')->send();
    }

    public function testRelativePathWithoutBaseUrlIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a full URL');
        HypCurl::get('/users')->send();
    }
}
