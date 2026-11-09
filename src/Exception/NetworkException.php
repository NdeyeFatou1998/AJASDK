<?php

declare(strict_types=1);

namespace Aja\Exception;

/** Transport failed before AJA produced a JSON envelope: DNS, TLS, timeout. */
final class NetworkException extends AjaException
{
}
