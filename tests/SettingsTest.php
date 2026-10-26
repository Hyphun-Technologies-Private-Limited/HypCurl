<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Tests;

use Hyphun\HypCurl\Core\ConfigException;
use Hyphun\HypCurl\Http\HttpClient;
use Hyphun\HypCurl\Http\HttpException;
use Hyphun\HypCurl\Http\JsonErrorHandler;
use Hyphun\HypCurl\HypCurl;
use Hyphun\HypCurl\Tests\Fixtures\TestApi;

/** Global defaults, redirects and TLS settings. */
final class SettingsTest extends TestCase
{
    protected function tearDown(): void
    {
        HypCurl::defaults([]);
    }

    public function testGlobalDefaultsApplyEverywhereAndClientOptionsWin(): void
    {
        HypCurl::boot([
            'values' => ['HYPCURL_LOG_LEVEL' => 'OFF'],
            'defaults' => [
                'proxy' => HYPCURL_TEST_SERVER,              // the test server doubles as a proxy
                'headers' => ['X-App' => 'global', 'X-Shared' => 'global'],
                'cookies' => false,
            ],
        ]);

        // Shortcut client, ad-hoc client and API class all get the defaults...
        $viaShortcut = HypCurl::get('http://example.test/echo')->send();
        $this->assertTrue($viaShortcut->json('proxied'));
        $this->assertSame('global', $viaShortcut->json('headers.x-app'));

        $client = HypCurl::client(['headers' => ['X-Shared' => 'client'], 'proxy' => 'none']);
        $res = $client->get(HYPCURL_TEST_SERVER . '/echo')->send();
        $this->assertFalse($res->json('proxied'), 'client option overrides the global proxy');
        $this->assertSame('global', $res->json('headers.x-app'), 'headers are merged');
        $this->assertSame('client', $res->json('headers.x-shared'));

        $api = TestApi::instance()->get('/echo')->header('X-Shared', 'request')->send();
        $this->assertSame('request', $api->json('headers.x-shared'), 'per-request wins');

        // ...and cookies => false from the defaults applies too.
        HypCurl::get(HYPCURL_TEST_SERVER . '/set-cookie')->withoutProxy()->query(['a' => 1])->send();
        $this->assertSame([], HypCurl::get(HYPCURL_TEST_SERVER . '/echo')->withoutProxy()->send()->json('cookies'));
    }

    public function testDefaultsRejectUnknownOptionsWithSuggestions(): void
    {
        $e = $this->configError(fn () => HypCurl::defaults(['proxyy' => 'x']));
        $this->assertInstanceOf(\InvalidArgumentException::class, $e);
        $this->assertSame("Unknown 'defaults' option(s): 'proxyy'. 'proxyy': did you mean 'proxy'?", $e->getMessage());
        $this->assertStringStartsWith('Allowed: headers, timeout', (string) $e->hint);
    }

    public function testMisplacedKeysExplainWhereTheyGo(): void
    {
        // The real-world mistake: json_errors inside 'defaults'
        $inside = $this->configError(fn () => HypCurl::defaults(['proxy' => 'http://p:1', 'json_errors' => true]));
        $this->assertStringContainsString("'json_errors' is a boot() option: put it next to 'defaults', not inside it.", $inside->getMessage());

        // ...and the reverse: request settings at the top level of boot()
        $outside = $this->configError(fn () => HypCurl::boot(['proxy' => 'http://p:1', 'timeout' => 5]));
        $this->assertStringContainsString("'proxy' is a request setting: put it inside 'defaults' => [...].", $outside->getMessage());
        $this->assertStringContainsString("'timeout' is a request setting", $outside->getMessage());

        $base = $this->configError(fn () => HypCurl::defaults(['baseUrl' => 'https://x']));
        $this->assertStringContainsString('differs per API', $base->getMessage());

        $typo = $this->configError(fn () => HypCurl::boot(['defualts' => []]));
        $this->assertStringContainsString("did you mean 'defaults'?", $typo->getMessage());
    }

    public function testConfigErrorsAreJsonReady(): void
    {
        $e = $this->configError(fn () => HypCurl::defaults(['json_errors' => true]));

        $this->assertSame('config_error', $e->toArray()['error']['type']);
        $this->assertSame(['type' => 'config_error', 'message' => $e->getMessage()], $e->toArray(debug: false)['error']);
        $this->assertSame('config_error', JsonErrorHandler::toArray($e, debug: true)['error']['type']);
        $this->assertSame(500, JsonErrorHandler::httpStatus($e));
    }

    private function configError(callable $call): ConfigException
    {
        try {
            $call();
        } catch (ConfigException $e) {
            return $e;
        }
        $this->fail('Expected ConfigException');
    }

    public function testRedirectsAreFollowedByDefault(): void
    {
        $res = HypCurl::get(HYPCURL_TEST_SERVER . '/redirect/3')->send();

        $this->assertSame(200, $res->status);
        $this->assertSame(3, $res->redirects);
        $this->assertSame(HYPCURL_TEST_SERVER . '/echo?redirected=1', $res->finalUrl);
        $this->assertSame(HYPCURL_TEST_SERVER . '/redirect/3', $res->url);
    }

    public function testRedirectsCanBeDisabledPerRequestAndPerClient(): void
    {
        $res = HypCurl::get(HYPCURL_TEST_SERVER . '/redirect/2')->withoutRedirects()->send();
        $this->assertSame(302, $res->status);
        $this->assertTrue($res->isRedirect());
        $this->assertSame(HYPCURL_TEST_SERVER . '/redirect/1', $res->redirectUrl);
        $this->assertSame('/redirect/1', $res->header('location'));

        $client = HypCurl::client(['followRedirects' => false]);
        $this->assertSame(302, $client->get(HYPCURL_TEST_SERVER . '/redirect/1')->send()->status);
        $this->assertSame(200, $client->get(HYPCURL_TEST_SERVER . '/redirect/1')->followRedirects()->send()->status);
    }

    public function testMaxRedirects(): void
    {
        $this->assertSame(2, HypCurl::get(HYPCURL_TEST_SERVER . '/redirect/2')->followRedirects(true, 2)->send()->redirects);

        try {
            HypCurl::get(HYPCURL_TEST_SERVER . '/redirect/5')->followRedirects(true, 2)->send();
            $this->fail('Expected too many redirects');
        } catch (HttpException $e) {
            $this->assertSame('too_many_redirects', $e->type);
            $this->assertSame(47, $e->curlCode);
        }
    }

    public function testRedirectToNonHttpProtocolIsBlocked(): void
    {
        $this->expectException(HttpException::class);
        HypCurl::get(HYPCURL_TEST_SERVER . '/redirect-to-file')->send();
    }

    public function testCurlCommandShowsRedirectAndTlsFlags(): void
    {
        $client = new HttpClient(['baseUrl' => 'https://h', 'maxRedirects' => 4]);
        $cmd = fn ($request) => $client->toCurlCommand($request, 'https://h/x', []);

        $this->assertStringContainsString("-L --max-redirs 4", $cmd($client->get('/x')));
        $this->assertStringNotContainsString('-L', $cmd($client->get('/x')->withoutRedirects()));
        $this->assertStringContainsString(' -k ', $cmd($client->get('/x')->insecure()));
        $this->assertStringNotContainsString(' -k ', $cmd($client->get('/x')));

        $pinned = new HttpClient(['caBundle' => '/etc/ssl/company-ca.pem']);
        $this->assertStringContainsString("--cacert '/etc/ssl/company-ca.pem'", $pinned->toCurlCommand($pinned->get('https://h/x'), 'https://h/x', []));
        $this->assertStringNotContainsString('--cacert', $pinned->toCurlCommand($pinned->get('https://h/x')->insecure(), 'https://h/x', []));
    }

    public function testMissingCaBundleFailsClearly(): void
    {
        $client = HypCurl::client(['caBundle' => '/nope/ca.pem', 'retries' => 0]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('CA bundle not found: /nope/ca.pem');
        $client->get(HYPCURL_TEST_SERVER . '/echo')->send();
    }
}
