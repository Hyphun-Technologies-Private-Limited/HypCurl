<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Tests;

use Hyphun\HypCurl\HypCurl;
use Hyphun\HypCurl\Tests\Fixtures\TestApi;

final class CookieLookupTest extends TestCase
{
    public function testResponseCookieAndSessionCookie(): void
    {
        $client = HypCurl::client(['baseUrl' => HYPCURL_TEST_SERVER]);
        $login = $client->get('/set-cookie')->query(['session' => 'abc 123', 'theme' => 'dark'])->send();

        // From that one response
        $this->assertSame('abc 123', $login->cookie('session'));
        $this->assertSame(['session' => 'abc 123', 'theme' => 'dark'], $login->cookies());

        // From the client's session, any time later
        $later = $client->get('/echo')->send();
        $this->assertNull($later->cookie('session'), 'this response did not set it');
        $this->assertSame('abc 123', $client->cookie('session'));
        $this->assertSame('dark', $client->cookie('theme', '127.0.0.1'));
        $this->assertNull($client->cookie('theme', 'other.example.com'), 'domain filter');
        $this->assertNull($client->cookie('missing'));
    }

    public function testShortcutAndApiClientLookups(): void
    {
        HypCurl::get(HYPCURL_TEST_SERVER . '/set-cookie')->query(['token' => 't1'])->send();
        $this->assertSame('t1', HypCurl::cookie('token'));
        $this->assertSame('token', HypCurl::cookies()[0]['name']);

        TestApi::instance()->get('/set-cookie')->query(['sid' => 's9'])->send();
        $this->assertSame('s9', TestApi::instance()->sessionCookie('sid'));
        $this->assertNull(HypCurl::cookie('sid'), 'each client has its own session');
    }
}
