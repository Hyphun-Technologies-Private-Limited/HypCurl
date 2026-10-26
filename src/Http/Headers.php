<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Http;

/**
 * Header-list helpers shared by Request and HttpClient.
 * Header names are case-insensitive: setting "accept" replaces "Accept".
 * A null value means "remove this header", including ones curl adds itself.
 */
final class Headers
{
    /**
     * @param array<string, ?string> $headers
     * @return array<string, ?string>
     */
    public static function set(array $headers, string $name, ?string $value): array
    {
        foreach (array_keys($headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                unset($headers[$existing]);
            }
        }
        $headers[$name] = $value;

        return $headers;
    }

    /**
     * Later lists override earlier ones.
     *
     * @param array<string, ?string> ...$lists
     * @return array<string, ?string>
     */
    public static function merge(array ...$lists): array
    {
        $merged = [];
        foreach ($lists as $list) {
            foreach ($list as $name => $value) {
                $merged = self::set($merged, (string) $name, $value);
            }
        }

        return $merged;
    }

    /**
     * Parse "Name: value" strings, or one string with several separated by "|":
     *   "X-App: my-app | X-Env: dev"
     *
     * @return array<string, string>
     */
    public static function parse(string ...$lines): array
    {
        $headers = [];
        foreach ($lines as $line) {
            foreach (explode('|', $line) as $part) {
                if (!str_contains($part, ':')) {
                    if (trim($part) !== '') {
                        throw new \InvalidArgumentException("Bad header '$part' (expected \"Name: value\")");
                    }
                    continue;
                }
                [$name, $value] = explode(':', $part, 2);
                $headers = self::set($headers, trim($name), trim($value));
            }
        }

        return $headers;
    }

    /**
     * Lines for CURLOPT_HTTPHEADER. "Name:" (no value) tells curl to drop the header.
     *
     * @param array<string, ?string> $headers
     * @return list<string>
     */
    public static function toCurl(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $value === null ? "$name:" : "$name: $value";
        }

        return $lines;
    }
}
