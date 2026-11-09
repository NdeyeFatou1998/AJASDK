<?php

declare(strict_types=1);

namespace Aja\Api;

use Aja\Client;
use Aja\ErrorCode;
use Aja\Exception\ApiException;
use Aja\Exception\NetworkException;
use InvalidArgumentException;

/**
 * The shop itself, as AJA knows it: the currency it sells in and the time
 * zone it runs in — and the proof that its server holds the key.
 *
 * La boutique est la source de vérité : AJA ne devine ni sa devise ni son
 * fuseau. Sans profil, une boutique en euros verrait ses prix lus dans une
 * autre devise. Déclarez donc le profil quand la boutique se connecte, puis à
 * chaque synchronisation de nuit, avec le formulaire produit.
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
     * Pass any of them; an empty string counts as not given. `$currency` is
     * an ISO 4217 code, upper-cased here. `$timezone` is an IANA name such as
     * "Africa/Dakar": a bare offset like "+02:00" is not one, and AJA refuses
     * it with VALIDATION, naming the field. `$wakeUrl` is a public https
     * address AJA calls (GET, at most every 30 s) to wake your polling task
     * when an action awaits — on WordPress, `wp-cron.php?doing_wp_cron`: a
     * shop with no visitors otherwise never runs its cron. Safe to repeat:
     * AJA stores the values and answers with what it stored.
     *
     * @return array<string,mixed> the stored profile: {currency, timezone, wake_url}
     *
     * @throws InvalidArgumentException when there is nothing to declare, or
     *                                  the currency is not three letters
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
     * Fraude au domaine : n'importe qui peut déclarer le domaine d'une autre
     * boutique. Avant qu'un site passe en production, AJA appelle donc son
     * adresse de réveil avec un paramètre de plus, `aja_verify=<code>`, et
     * attend que ce code lui revienne avec la clé du site : le serveur derrière
     * le domaine déclaré détient la clé. Passez ici la requête de réveil
     * (`$_GET`), puis faites votre travail habituel ; AJA ne lit pas la réponse.
     *
     * Une adresse publique reçoit n'importe quoi : sans code, ou avec un code
     * qui n'a pas la forme de ceux d'AJA, rien ne part et la méthode rend null ;
     * un code qu'AJA refuse rend ['verified' => false] au lieu de lever.
     *
     * @param array<string,mixed> $query the wake request's query parameters, typically $_GET
     *
     * @return array<string,mixed>|null null when there is nothing to answer;
     *                                  ['verified' => true, 'domain' => 'shop.example'] once proven;
     *                                  ['verified' => false] when AJA refused the code
     *
     * @throws ApiException|NetworkException any other failure — a revoked key, the network — as for every call
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
     * The lower half of handleWake(), for a handler that reads the code
     * itself. The same code from the same key proves the same thing: a dropped
     * connection or a passing 429/5xx is retried like any idempotent call.
     *
     * @return array<string,mixed> what AJA answers: {verified: true, domain}
     *
     * @throws InvalidArgumentException before any call, when the code is not shaped like AJA's
     * @throws ApiException VERIFICATION_FAILED (HTTP 422, never retried) when AJA does not know
     *                      the code, it expired, or the shop's domain changed since it was sent
     * @throws NetworkException
     */
    public function confirmVerification(string $code): array
    {
        if (!self::isVerificationCode($code)) {
            // Jamais le code lui-même dans le message : il finit dans les journaux.
            throw new InvalidArgumentException(
                'verification code must be 32 to 128 characters among A-Z, a-z, 0-9, "_" and "-"',
            );
        }

        return $this->client->send('POST', '/v1/site-verification', ['nonce' => $code], [], true, true);
    }

    /** AJA's codes: 32 to 128 characters of the URL-safe alphabet, nothing around them. */
    private static function isVerificationCode(string $code): bool
    {
        // D : sans lui, $ accepterait un saut de ligne final.
        return preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $code) === 1;
    }
}
