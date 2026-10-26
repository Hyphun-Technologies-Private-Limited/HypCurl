<?php

declare(strict_types=1);

namespace Hyphun\HypCurl\Http;

/** TLS/certificate problem (self-signed, expired, wrong host, unknown CA, ...). */
class SslException extends NetworkException
{
}
