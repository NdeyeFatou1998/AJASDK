<?php

declare(strict_types=1);

namespace Aja\Exception;

/**
 * Transport failed before AJA produced a JSON envelope: DNS, TLS, timeout.
 * Retrying is reasonable; the request may or may not have reached the server,
 * which is why only idempotent calls are retried automatically.
 */
final class NetworkException extends AjaException
{
}
