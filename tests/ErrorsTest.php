<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Tests;

use Hyphun\HypCurl\Http\ClientException;
use Hyphun\HypCurl\Http\HttpException;
use Hyphun\HypCurl\Http\JsonErrorHandler;
use Hyphun\HypCurl\Http\NetworkException;
use Hyphun\HypCurl\Http\ProxyException;
use Hyphun\HypCurl\Http\Redact;
use Hyphun\HypCurl\Http\ServerException;
use Hyphun\HypCurl\Http\TimeoutException;
use Hyphun\HypCurl\HypCurl;

final class ErrorsTest extends TestCase
{
    public function testProxyTunnelFailureIsAProxyException(): void
    {
        // The exact error from a real report: "curl error 56: CONNECT tunnel failed, response 503"
        try {
            HypCurl::get('https://api.example.com/users')->proxy(HYPCURL_TEST_SERVER)->send();
            $this->fail('Expected ProxyException');
        } catch (ProxyException $e) {
            $this->assertInstanceOf(NetworkException::class, $e);
            $this->assertSame('proxy_error', $e->type);
            $this->assertSame('Proxy refused the tunnel to api.example.com (proxy answered HTTP 503)', $e->reason);
            $this->assertStringContainsString('could not reach api.example.com', (string) $e->hint);
            $this->assertSame(56, $e->curlCode);
            $this->assertNull($e->status());
            $this->assertTrue($e->retryable());
        }
    }

    public function testUnreachableProxyAndDns(): void
    {
        $proxy = $this->catch(fn () => HypCurl::get('http://example.test/')->proxy('http://user:pw@127.0.0.1:1')->send());
        $this->assertInstanceOf(ProxyException::class, $proxy);
        $this->assertSame('Could not connect to the proxy', $proxy->reason);
        $this->assertStringContainsString('http://user:****@127.0.0.1:1', (string) $proxy->hint);
        $this->assertStringNotContainsString(':pw@', $proxy->toJson());

        $dns = $this->catch(fn () => HypCurl::get('http://no-such-host.invalid/')->send());
        $this->assertInstanceOf(NetworkException::class, $dns);
        $this->assertSame('dns_error', $dns->type);
        $this->assertSame('Could not resolve host no-such-host.invalid', $dns->reason);
    }

    public function testHttpErrorsHaveTypeStatusAndServerMessage(): void
    {
        $e = $this->catch(fn () => HypCurl::get(HYPCURL_TEST_SERVER . '/error/422')->query(['message' => 'Email is invalid'])->send());
        $this->assertInstanceOf(ClientException::class, $e);
        $this->assertSame('validation_error', $e->type);
        $this->assertSame(422, $e->status());
        $this->assertSame(422, $e->getCode());
        $this->assertSame('422 Unprocessable Entity: Email is invalid', $e->reason);
        $this->assertFalse($e->retryable());

        $this->assertSame('not_found', $this->catch(fn () => HypCurl::get(HYPCURL_TEST_SERVER . '/error/404')->send())->type);
        $this->assertSame('unauthorized', $this->catch(fn () => HypCurl::get(HYPCURL_TEST_SERVER . '/error/401')->send())->type);

        $limited = $this->catch(fn () => HypCurl::get(HYPCURL_TEST_SERVER . '/error/429')->query(['retry_after' => 30])->send());
        $this->assertSame('rate_limited', $limited->type);
        $this->assertStringContainsString('retry after 30 s', (string) $limited->hint);

        $server = $this->catch(fn () => HypCurl::get(HYPCURL_TEST_SERVER . '/error/503')->send());
        $this->assertInstanceOf(ServerException::class, $server);
        $this->assertSame('server_error', $server->type);
        $this->assertTrue($server->retryable());
    }

    public function testToArrayDebugAndPublicShapes(): void
    {
        $e = $this->catch(fn () => HypCurl::get(HYPCURL_TEST_SERVER . '/error/422?apiToken=secret123')->query(['message' => 'Bad email'])->send());

        $debug = $e->toArray();
        $this->assertFalse($debug['success']);
        $this->assertSame('validation_error', $debug['error']['type']);
        $this->assertSame(422, $debug['error']['status']);
        $this->assertSame('GET', $debug['error']['request']['method']);
        $this->assertStringContainsString('apiToken=****', $debug['error']['request']['url']);
        $this->assertSame(['email' => ['invalid']], $debug['error']['response']['body']['errors']);
        $this->assertStringNotContainsString('secret123', $e->toJson());

        $public = $e->toArray(debug: false);
        $this->assertSame(['type', 'message', 'status'], array_keys($public['error']));
        $this->assertSame('422 Unprocessable Entity: Bad email', $public['error']['message']);

        $this->assertSame($debug, json_decode(json_encode($e), true), 'json_encode($e) works');
    }

    public function testTimeoutJson(): void
    {
        $e = $this->catch(fn () => HypCurl::get(HYPCURL_TEST_SERVER . '/sleep/2')->timeout(1)->send());

        $this->assertInstanceOf(TimeoutException::class, $e);
        $this->assertSame(['code' => 28, 'message' => $e->curlMessage], $e->toArray()['error']['curl']);
    }

    public function testTrySendNeverThrows(): void
    {
        $ok = HypCurl::get(HYPCURL_TEST_SERVER . '/echo')->query(['a' => 1])->trySend();
        $this->assertTrue($ok->ok());
        $this->assertNull($ok->error);
        $this->assertSame(['success' => true, 'status' => 200], array_slice($ok->toArray(), 0, 2));
        $this->assertSame(['a' => '1'], $ok->toArray()['data']['query']);

        $notFound = HypCurl::get(HYPCURL_TEST_SERVER . '/error/404')->trySend();
        $this->assertFalse($notFound->ok());
        $this->assertInstanceOf(ClientException::class, $notFound->error);
        $this->assertSame('not_found', $notFound->toArray()['error']['type']);

        $down = HypCurl::get('http://127.0.0.1:1/')->trySend();
        $this->assertSame(0, $down->status);
        $this->assertSame('connection_error', $down->error?->type);
        $this->assertSame('connection_error', json_decode($down->toJson(), true)['error']['type']);

        $this->expectException(NetworkException::class);
        $down->throw();
    }

    public function testThrowFalseClientReturnsErrorsForNetworkFailuresToo(): void
    {
        $client = HypCurl::client(['throw' => false]);

        $this->assertSame('not_found', $client->get(HYPCURL_TEST_SERVER . '/error/404')->send()->error?->type);
        $this->assertSame('connection_error', $client->get('http://127.0.0.1:1/')->send()->error?->type);
    }

    public function testJsonErrorRenderer(): void
    {
        $e = $this->catch(fn () => HypCurl::get('https://api.example.com/users')->proxy(HYPCURL_TEST_SERVER)->send());

        ob_start();
        JsonErrorHandler::render($e, debug: false);
        $out = json_decode((string) ob_get_clean(), true);

        $this->assertSame(false, $out['success']);
        $this->assertSame('proxy_error', $out['error']['type']);
        $this->assertSame(['type', 'message'], array_keys($out['error']), 'no internals without debug');
        $this->assertStringNotContainsString('127.0.0.1', json_encode($out));
        $this->assertSame(502, JsonErrorHandler::httpStatus($e));
        $this->assertSame(504, JsonErrorHandler::httpStatus(new TimeoutException('t')));
        $this->assertSame(500, JsonErrorHandler::httpStatus(new \RuntimeException('x')));
        $this->assertSame('internal_error', JsonErrorHandler::toArray(new \RuntimeException('secret'))['error']['type']);
        $this->assertSame('Internal error', JsonErrorHandler::toArray(new \RuntimeException('secret'))['error']['message']);
    }

    public function testRedactUrl(): void
    {
        $this->assertSame(
            'https://u:****@h/p?api_key=****&page=2&Token=****',
            Redact::url('https://u:pw@h/p?api_key=abc&page=2&Token=xyz'),
        );
    }

    private function catch(callable $call): HttpException
    {
        try {
            $call();
        } catch (HttpException $e) {
            return $e;
        }
        $this->fail('Expected an HttpException');
    }
}
