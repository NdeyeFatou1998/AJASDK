<?php

declare(strict_types=1);

namespace Aja\Http;

use Aja\Exception\NetworkException;

/**
 * Transport seam. Exists so tests can drive the SDK without a network,
 * and so a host (WordPress) may swap in its own HTTP stack later.
 */
interface HttpClient
{
    /**
     * @param array<string,string> $headers
     *
     * @throws NetworkException when no HTTP response could be obtained
     */
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body = null,
        /**
         * Délai total pour CET appel, en secondes.
         *
         * Le long polling demande au serveur de tenir la requête : un délai
         * client figé plus court tuerait la requête qu'on vient de demander.
         */
        ?int $timeoutSeconds = null,
    ): HttpResponse;
}
