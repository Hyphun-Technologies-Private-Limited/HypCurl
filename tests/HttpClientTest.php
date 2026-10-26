<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Tests;

use Hyphun\HypCurl\Http\HttpClient;
use Hyphun\HypCurl\Http\HttpException;
use Hyphun\HypCurl\HypCurl;
use Hyphun\HypCurl\Tests\Fixtures\TestApi;

final class HttpClientTest extends TestCase
{
    private function client(array $options = []): HttpClient
    {
        return new HttpClient($options + ['baseUrl' => HYPCURL_TEST_SERVER, 'retryDelay' => 10]);
    }

    public function testGetWithQuery(): void
    {
        $res = $this->client()->get('/echo')->query(['a' => 1, 'flag' => true, 'skip' => null])->send();

        $this->assertSame(200, $res->status);
        $this->assertSame('GET', $res->json('method'));
        $this->assertSame(['a' => '1', 'flag' => 'true'], $res->json('query'));
    }

    public function testPostJson(): void
    {
        $res = $this->client()->post('/echo')->json(['title' => 'Hi', 'n' => 1])->send();

        $this->assertSame('{"title":"Hi","n":1}', $res->json('body'));
        $this->assertSame('application/json', $res->json('headers.content-type'));
    }

    public function testFormAndMultipart(): void
    {
        $form = $this->client()->post('/echo')->form(['a' => 'b'])->send();
        $this->assertSame(['a' => 'b'], $form->json('post'));

        $file = tempnam(sys_get_temp_dir(), 'hc');
        file_put_contents($file, 'hello');
        $upload = $this->client()->post('/echo')->multipart(['note' => 'n', 'file' => "@$file"])->send();
        unlink($file);

        $this->assertSame(['note' => 'n'], $upload->json('post'));
        $this->assertSame(5, $upload->json('files.file.size'));
    }

    public function testHeaderLayersAndRemoval(): void
    {
        HypCurl::boot(['values' => ['HYPCURL_LOG_LEVEL' => 'OFF', 'HYPCURL_HEADERS' => 'X-Global: g | X-Over: config']]);
        $client = $this->client(['headers' => ['X-Client' => 'c']]);
        $client->setDefaultHeader('x-over', 'runtime');

        $headers = $client->get('/echo')
            ->headers(['X-Req: r', 'X-Map' => 'm'])
            ->header('accept', 'text/plain')
            ->withoutHeader('User-Agent')
            ->send()
            ->json('headers');

        $this->assertSame('g', $headers['x-global']);
        $this->assertSame('runtime', $headers['x-over']);
        $this->assertSame('c', $headers['x-client']);
        $this->assertSame('r', $headers['x-req']);
        $this->assertSame('m', $headers['x-map']);
        $this->assertSame('text/plain', $headers['accept']);
        $this->assertArrayNotHasKey('user-agent', $headers);
    }

    public function testCookieSessionIsReusedAndClearable(): void
    {
        $client = $this->client();
        $set = $client->get('/set-cookie')->query(['session' => 'abc', 'theme' => 'dark'])->send();
        $this->assertSame(['session' => 'abc', 'theme' => 'dark'], $set->cookies);

        $echo = $client->get('/echo')->cookie('extra', 'x y')->send();
        $this->assertSame(['session' => 'abc', 'theme' => 'dark', 'extra' => 'x y'], $echo->json('cookies'));
        $this->assertCount(2, $client->cookies());

        $client->clearCookies();
        $this->assertSame([], $client->get('/echo')->send()->json('cookies'));
    }

    public function testCookieJarPersistsBetweenClients(): void
    {
        $dir = $this->tempDir();
        HypCurl::boot(['base_path' => $dir, 'values' => ['HYPCURL_LOG_LEVEL' => 'OFF', 'HYPCURL_COOKIE_JAR' => 'jar/cookies.txt']]);

        $this->client()->get('/set-cookie')->query(['token' => 't1'])->send();
        $this->assertFileExists("$dir/jar/cookies.txt");

        $fresh = $this->client(); // new handle, like a new process
        $this->assertSame(['token' => 't1'], $fresh->get('/echo')->send()->json('cookies'));

        $fresh->clearCookies();
        $this->assertFileDoesNotExist("$dir/jar/cookies.txt");
        @rmdir("$dir/jar");
        @rmdir($dir);
    }

    public function testCookiesCanBeDisabled(): void
    {
        $client = $this->client(['cookies' => false]);
        $client->get('/set-cookie')->query(['a' => '1'])->send();

        $this->assertSame([], $client->get('/echo')->send()->json('cookies'));
    }

    public function testHttpErrorsThrowWithResponse(): void
    {
        try {
            $this->client()->get('/status/404')->send();
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->response?->status);
        }

        $res = $this->client(['throw' => false])->get('/status/500')->send();
        $this->assertTrue($res->failed());
    }

    public function testRetriesTransientFailures(): void
    {
        $key = bin2hex(random_bytes(4));
        $res = $this->client()->get('/flaky')->query(['key' => $key, 'fail' => 2])->retries(2)->send();

        $this->assertSame(200, $res->status);
        $this->assertSame(3, $res->json('attempt'));
    }

    public function testTimeoutIsANetworkError(): void
    {
        try {
            $this->client()->get('/sleep/2')->timeout(1)->send();
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertNull($e->response);
            $this->assertStringContainsString('curl error 28', $e->getMessage());
        }
    }

    public function testProxyIsUsedAndCanBeBypassed(): void
    {
        // The test server doubles as a forward proxy: it sees an absolute URI.
        $client = $this->client(['proxy' => HYPCURL_TEST_SERVER]);

        $this->assertTrue($client->get('http://example.test/echo')->send()->json('proxied'));
        $this->assertFalse($client->get('/echo')->withoutProxy()->send()->json('proxied'));
    }

    public function testDryRunSendsNothing(): void
    {
        HypCurl::boot(['values' => ['HYPCURL_LOG_LEVEL' => 'OFF', 'HYPCURL_DRY_RUN' => 'true']]);

        $this->assertSame(0, $this->client()->get('/status/500')->send()->status);
    }

    public function testCurlCommandMasksSecrets(): void
    {
        $client = $this->client(['proxy' => 'http://user:pw@proxy:8080']);
        $request = $client->get('/x')->bearer('secret-token')->header('X-Api-Key', 'k')->basicAuth('me', 'pw');
        $cmd = $client->toCurlCommand($request, 'http://h/x', ['Authorization' => 'Bearer secret-token', 'X-Api-Key' => 'k']);

        $this->assertStringContainsString("'Authorization: Bearer ****'", $cmd);
        $this->assertStringContainsString("'X-Api-Key: ****'", $cmd);
        $this->assertStringContainsString("'me:****'", $cmd);
        $this->assertStringContainsString("'http://user:****@proxy:8080'", $cmd);
        $this->assertStringNotContainsString('secret-token', $cmd);
    }

    public function testApiClientSingletonAndCrudResource(): void
    {
        $api = TestApi::instance();
        $this->assertSame($api, TestApi::instance());

        $this->assertSame('/items/7', $api->items()->find(7)->json('path'));
        $this->assertSame('PATCH', $api->items()->patch(7, ['a' => 1])->json('method'));
        $this->assertSame(['q' => 'php', 'page' => '2'], $api->search('php', ['page' => 2])->json('query'));

        HypCurl::boot();
        $this->assertNotSame($api, TestApi::instance(), 'boot() resets API singletons');
    }
}
