<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Http;

/**
 * Hides secrets in URLs before they reach logs, exception messages or JSON errors.
 */
final class Redact
{
    /** Query parameters whose values are masked (case-insensitive, matched on the full name). */
    private const SECRET_PARAMS = [
        'token', 'access_token', 'refresh_token', 'id_token', 'apitoken', 'api_token', 'apikey', 'api_key', 'key',
        'secret', 'client_secret', 'password', 'passwd', 'pass', 'signature', 'sig', 'auth', 'session',
    ];

    /** http://user:secret@host/?token=abc  ->  http://user:****@host/?token=**** */
    public static function url(string $url): string
    {
        $url = (string) preg_replace('#(//[^:/@\s]+):[^@/\s]*@#', '$1:****@', $url);

        return (string) preg_replace_callback(
            '/([?&])([^=&#]+)=([^&#]*)/',
            static fn (array $m) => in_array(strtolower(urldecode($m[2])), self::SECRET_PARAMS, true)
                ? "{$m[1]}{$m[2]}=****"
                : $m[0],
            $url,
        );
    }
}
