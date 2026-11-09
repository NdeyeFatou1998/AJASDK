<?php

declare(strict_types=1);

namespace Aja\Http;

/** Raw transport result. */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }
}
