<?php

declare(strict_types=1);

namespace Aja\Exception;

use RuntimeException;

/**
 * Base class for every failure the SDK raises.
 * Integrators can catch this one to guard a checkout in a single block.
 */
abstract class AjaException extends RuntimeException
{
}
