<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Http;

/** No HTTP response arrived: DNS failure, refused or dropped connection, too many redirects, invalid URL. $e->response is null. */
class NetworkException extends HttpException
{
}
