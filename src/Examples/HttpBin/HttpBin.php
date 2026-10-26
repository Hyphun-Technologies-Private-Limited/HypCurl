<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Examples\HttpBin;

use Hyphun\HypCurl\Api\ApiClient;
use Hyphun\HypCurl\Core\Config;
use Hyphun\HypCurl\Http\Response;

/**
 * https://httpbin.org — echoes back what you send. Good for practising auth,
 * uploads, downloads, error codes and retries.
 * This service puts methods directly on the client (no Resource classes) — both styles work.
 */
final class HttpBin extends ApiClient
{
    protected function options(): array
    {
        return [
            'baseUrl' => Config::instance()->get('HTTPBIN_BASE_URL', 'https://httpbin.org'),
        ];
    }

    /** Echo back the headers you sent. */
    public function headers(): Response
    {
        return $this->get('/headers')->send();
    }

    /** Echo any method/body/query back. */
    public function anything(string $method = 'POST', array $data = [], array $query = []): Response
    {
        $request = $this->http->request($method, '/anything')->query($query);
        if ($data !== []) {
            $request->json($data);
        }

        return $request->send();
    }

    /** Bearer-token auth. Token defaults to HTTPBIN_TOKEN from config. */
    public function bearer(?string $token = null): Response
    {
        return $this->get('/bearer')
            ->bearer($token ?? Config::instance()->require('HTTPBIN_TOKEN'))
            ->send();
    }

    /** Basic auth against /basic-auth/{user}/{pass}. */
    public function basicAuth(string $user = 'demo', string $password = 'secret'): Response
    {
        return $this->get('/basic-auth/' . rawurlencode($user) . '/' . rawurlencode($password))
            ->basicAuth($user, $password)
            ->send();
    }

    /** Server sets cookies via Set-Cookie (like a login would), then redirects to /cookies. */
    public function setCookies(array $cookies): Response
    {
        return $this->get('/cookies/set')->query($cookies)->send();
    }

    /** Echo back the cookies this client sent — proves the session is being reused. */
    public function cookies(): Response
    {
        return $this->get('/cookies')->send();
    }

    /** Your public IP as seen by the server — compare with and without a proxy. */
    public function ip(): Response
    {
        return $this->get('/ip')->send();
    }

    /** Submit an urlencoded HTML-style form. */
    public function submitForm(array $fields): Response
    {
        return $this->post('/post')->form($fields)->send();
    }

    /** Multipart file upload. */
    public function upload(string $file, string $note = ''): Response
    {
        return $this->post('/post')->multipart(['note' => $note, 'file' => "@$file"])->send();
    }

    /** Download a PNG to a file. */
    public function downloadImage(string $saveTo): Response
    {
        return $this->get('/image/png')->header('Accept', 'image/png')->saveTo($saveTo)->send();
    }

    /** Respond with a specific status code — try 404, 500 (watch the retries), etc. */
    public function status(int $code, int $retries = 0): Response
    {
        return $this->get("/status/$code")->retries($retries)->send();
    }

    /** Server waits N seconds — try with a smaller timeout to see timeouts. */
    public function delay(int $seconds, int $timeout = 30): Response
    {
        return $this->get("/delay/$seconds")->timeout($timeout)->send();
    }
}
