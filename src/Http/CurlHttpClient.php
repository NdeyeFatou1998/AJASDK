<?php

declare(strict_types=1);

namespace Aja\Http;

use Aja\Exception\NetworkException;
use CurlHandle;

/**
 * ext-curl transport. Deliberately the only HTTP dependency: shops on shared
 * hosting cannot always install Guzzle, and the connector vendors this folder.
 */
final class CurlHttpClient implements HttpClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 10,
        private readonly int $connectTimeoutSeconds = 5,
    ) {
    }

    /**
     * @param array<string,string> $headers
     *
     * @throws NetworkException
     */
    public function request(
        string $method,
        string $url,
        array $headers,
        ?string $body = null,
        ?int $timeoutSeconds = null,
    ): HttpResponse {
        $handle = curl_init();
        if (!$handle instanceof CurlHandle) {
            throw new NetworkException('Unable to initialise curl');
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => $timeoutSeconds ?? $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($errno !== 0 || !is_string($raw)) {
            // Never interpolate the URL: it is safe, but the key lives in a header
            // and we keep transport logs free of anything request-shaped.
            throw new NetworkException(sprintf('curl error %d: %s', $errno, $error));
        }

        return new HttpResponse($status, $raw);
    }
}
