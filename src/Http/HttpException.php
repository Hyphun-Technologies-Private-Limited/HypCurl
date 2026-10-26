<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Http;

/**
 * Thrown for HTTP 4xx/5xx (response is set) and network errors (response is null).
 */
final class HttpException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?Response $response = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $response?->status ?? 0, $previous);
    }
}
