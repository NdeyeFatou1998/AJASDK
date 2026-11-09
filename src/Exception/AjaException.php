<?php

declare(strict_types=1);

namespace Aja\Exception;

use RuntimeException;

/** Base class for every failure the SDK raises. */
abstract class AjaException extends RuntimeException
{
}
