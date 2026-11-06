<?php

declare(strict_types=1);

namespace Aja;

use InvalidArgumentException;

use Aja\Api\CommandsApi;
use Aja\Api\OrdersApi;
use Aja\Api\ProductsApi;
use Aja\Api\SiteApi;
use Aja\Exception\ApiException;
use Aja\Exception\NetworkException;
use Aja\Exception\ValidationException;
use Aja\Http\CurlHttpClient;
use Aja\Http\HttpClient;

/**
 * Entry point. Holds the key and the retry policy; the Api classes only
 * describe routes.
 *
 * The shop always calls AJA — there is no inbound traffic to receive, so this
 * client has no server, no webhook and no listener.
 */
final class Client
{
    public const VERSION = '1.0';
    public const USER_AGENT = 'aja-php-sdk/1.0';

    private const DEFAULT_BASE_URL = 'https://ajabackend-production.up.railway.app';

    private readonly string $baseUrl;
    private readonly HttpClient $http;
    private readonly OrdersApi $orders;
    private readonly CommandsApi $commands;
    private readonly ProductsApi $products;
    private readonly SiteApi $site;

    public function __construct(
        private readonly string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?HttpClient $http = null,
        private readonly int $maxAttempts = 3,
        private readonly int $backoffMs = 200,
    ) {
        $this->baseUrl = rtrim($baseUrl === '' ? self::DEFAULT_BASE_URL : $baseUrl, '/');
        self::assertSafeBaseUrl($this->baseUrl);
        $this->http = $http ?? new CurlHttpClient();
        $this->orders = new OrdersApi($this);
        $this->commands = new CommandsApi($this);
        $this->products = new ProductsApi($this);
        $this->site = new SiteApi($this);
    }

    /**
     * The API key travels in every request: never in clear text to a public
     * server. http is accepted only for a local test server (localhost, a
     * private network address, *.test, *.local, host.docker.internal).
     *
     * @throws InvalidArgumentException
     */
    private static function assertSafeBaseUrl(string $baseUrl): void
    {
        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        $host = strtolower(trim((string) parse_url($baseUrl, PHP_URL_HOST), '[]'));
        if ($scheme === 'https') {
            return;
        }
        if ($scheme === 'http' && self::isLocalHost($host)) {
            return;
        }
        throw new InvalidArgumentException(sprintf(
            'AJA base URL "%s" must use https: the API key would travel in clear text (http is only for a local test server)',
            $baseUrl,
        ));
    }

    private static function isLocalHost(string $host): bool
    {
        if (in_array($host, ['localhost', 'host.docker.internal'], true)
            || preg_match('/\.(localhost|local|test)$/', $host) === 1
        ) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    public function orders(): OrdersApi
    {
        return $this->orders;
    }

    public function commands(): CommandsApi
    {
        return $this->commands;
    }

    public function products(): ProductsApi
    {
        return $this->products;
    }

    public function site(): SiteApi
    {
        return $this->site;
    }

    /**
     * Perform one API call, applying the retry policy.
     *
     * @param array<string,mixed>|null $body
     * @param array<string,string>     $query
     *
     * @return array<string,mixed> decoded JSON, or [] for 204 responses
     *
     * @throws ApiException|NetworkException
     */
    public function send(
        string $method,
        string $path,
        ?array $body = null,
        array $query = [],
        bool $retryTransport = true,
        bool $retryTransient = false,
        ?int $timeoutSeconds = null,
    ): array {
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => self::USER_AGENT,
        ];
        $payload = null;
        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                throw new NetworkException('Unable to encode request body as JSON');
            }
            $headers['Content-Type'] = 'application/json';
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $response = $this->http->request($method, $url, $headers, $payload, $timeoutSeconds);
            } catch (NetworkException $e) {
                if ($retryTransport && $attempt < $this->maxAttempts) {
                    $this->sleepBeforeRetry($attempt);
                    continue;
                }
                throw $e;
            }

            if ($response->status === 204 || $response->body === '') {
                return [];
            }

            /** @var array<string,mixed>|null $decoded */
            $decoded = json_decode($response->body, true);

            if ($response->status >= 200 && $response->status < 300) {
                return is_array($decoded) ? $decoded : [];
            }

            $error = $this->toApiException($response->status, is_array($decoded) ? $decoded : []);
            $transient = ErrorCode::isRetryable($error->getErrorCode())
                && in_array($response->status, [429, 502, 503, 504], true);

            if ($retryTransient && $transient && $attempt < $this->maxAttempts) {
                $this->sleepBeforeRetry($attempt);
                continue;
            }

            throw $error;
        }
    }

    /** Map an error envelope onto the right exception class. */
    private function toApiException(int $status, array $decoded): ApiException
    {
        $envelope = $decoded['error'] ?? [];
        $envelope = is_array($envelope) ? $envelope : [];
        $code = (string) ($envelope['code'] ?? ErrorCode::INTERNAL);
        $message = (string) ($envelope['message'] ?? 'AJA request failed');
        $details = $envelope['details'] ?? [];
        $requestId = isset($decoded['request_id']) ? (string) $decoded['request_id'] : null;

        if ($code === ErrorCode::VALIDATION) {
            return new ValidationException(
                $status,
                $code,
                $message,
                $requestId,
                is_array($details) ? $details : [],
            );
        }

        return new ApiException(
            $status,
            $code,
            $message,
            $requestId,
            is_array($details) ? $details : [],
        );
    }

    /** Exponential backoff: 200 ms, 400 ms, 800 ms. */
    private function sleepBeforeRetry(int $attempt): void
    {
        usleep($this->backoffMs * (2 ** ($attempt - 1)) * 1000);
    }
}
