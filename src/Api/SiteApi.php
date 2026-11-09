<?php

declare(strict_types=1);

namespace Aja\Api;

use Aja\Client;
use Aja\ErrorCode;
use Aja\Exception\ApiException;
use Aja\Exception\NetworkException;
use InvalidArgumentException;

/**
 * The shop itself, as AJA knows it: the currency it sells in and the time zone it runs in — and the
 * proof that its server holds the key.
 */
final class SiteApi
{
    /** The query parameter AJA adds to the wake URL when it asks for the proof. */
    private const VERIFY_PARAM = 'aja_verify';

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * PUT /v1/site-profile — declare the shop's currency, time zone and wake URL.
     *
     * @return array<string,mixed>
     * @throws InvalidArgumentException
     * @throws ApiException|NetworkException
     */
    public function putProfile(?string $currency = null, ?string $timezone = null, ?string $wakeUrl = null): array
    {
        $body = [];
        $currency = strtoupper(trim((string) $currency));
        if ($currency !== '') {
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'currency "%s" is not a three-letter ISO 4217 code such as "XOF" or "EUR"',
                    $currency,
                ));
            }
            $body['currency'] = $currency;
        }
        $timezone = trim((string) $timezone);
        if ($timezone !== '') {
            $body['timezone'] = $timezone;
        }
        $wakeUrl = trim((string) $wakeUrl);
        if ($wakeUrl !== '') {
            if (preg_match('#^https?://#i', $wakeUrl) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'wake URL "%s" must be an http(s) address, such as https://your-shop.com/wp-cron.php?doing_wp_cron',
                    $wakeUrl,
                ));
            }
            $body['wake_url'] = $wakeUrl;
        }
        if ($body === []) {
            throw new InvalidArgumentException('putProfile needs a currency, a timezone or a wake URL');
        }

        return $this->client->send('PUT', '/v1/site-profile', $body, [], true, true);
    }

    /**
     * Answer AJA's ownership check — call it first thing in your wake-URL handler.
     *
     * @param array<string,mixed> $query
     * @return array<string,mixed>|null
     * @throws ApiException|NetworkException
     */
    public function handleWake(array $query): ?array
    {
        $code = $query[self::VERIFY_PARAM] ?? null;
        if (!is_string($code) || !self::isVerificationCode($code)) {
            return null;
        }

        try {
            return $this->confirmVerification($code);
        } catch (ApiException $e) {
            if ($e->getErrorCode() !== ErrorCode::VERIFICATION_FAILED) {
                throw $e;
            }

            return ['verified' => false];
        }
    }

    /**
     * POST /v1/site-verification — send AJA back the code it put on the wake URL.
     *
     * @return array<string,mixed>
     * @throws InvalidArgumentException
     * @throws ApiException
     * @throws NetworkException
     */
    public function confirmVerification(string $code): array
    {
        if (!self::isVerificationCode($code)) {
            throw new InvalidArgumentException(
                'verification code must be 32 to 128 characters among A-Z, a-z, 0-9, "_" and "-"',
            );
        }

        return $this->client->send('POST', '/v1/site-verification', ['nonce' => $code], [], true, true);
    }

    /** AJA's codes: 32 to 128 characters of the URL-safe alphabet, nothing around them. */
    private static function isVerificationCode(string $code): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $code) === 1;
    }
}
