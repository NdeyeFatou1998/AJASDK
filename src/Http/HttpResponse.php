<?php

declare(strict_types=1);

namespace Aja\Http;

/**
 * Raw transport result. Decoding and error mapping happen in Client,
 * so a fake HttpClient in tests only has to return status + body.
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }
}
