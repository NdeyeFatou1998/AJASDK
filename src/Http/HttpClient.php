<?php

declare(strict_types=1);

namespace Aja\Http;

use Aja\Exception\NetworkException;

/** Transport seam. */
interface HttpClient
{
    /**
     * @param array<string,string> $headers
     * @throws NetworkException
     */
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body = null,
        /** Délai total pour CET appel, en secondes. */
        ?int $timeoutSeconds = null,
    ): HttpResponse;
}
