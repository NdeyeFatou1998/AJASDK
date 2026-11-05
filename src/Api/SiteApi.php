<?php

declare(strict_types=1);

namespace Aja\Api;

use Aja\Client;
use Aja\Exception\ApiException;
use Aja\Exception\NetworkException;
use InvalidArgumentException;

/**
 * The shop itself, as AJA knows it: the currency it sells in and the time
 * zone it runs in.
 *
 * La boutique est la source de vérité. Revue du 4 novembre : toute boutique
 * était créée en XOF et rien ne corrigeait. Une boutique en euros voyait ses
 * prix lus en francs CFA dans l'application, et « 15 » tapé pour 15 € y
 * devenait 0,15 €. Déclarez donc le profil quand la boutique se connecte,
 * puis à chaque synchronisation de nuit, avec le formulaire produit.
 */
final class SiteApi
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * PUT /v1/site-profile — declare the shop's currency and time zone.
     *
     * Pass one or both; an empty string counts as not given. `$currency` is
     * an ISO 4217 code, upper-cased here. `$timezone` is an IANA name such as
     * "Africa/Dakar": a bare offset like "+02:00" is not one, and AJA refuses
     * it with VALIDATION, naming the field. Safe to repeat: AJA stores the
     * values and answers with what it stored.
     *
     * @return array<string,mixed> the stored profile: {currency, timezone}
     *
     * @throws InvalidArgumentException when there is nothing to declare, or
     *                                  the currency is not three letters
     * @throws ApiException|NetworkException
     */
    public function putProfile(?string $currency = null, ?string $timezone = null): array
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
        if ($body === []) {
            throw new InvalidArgumentException('putProfile needs a currency, a timezone, or both');
        }

        return $this->client->send('PUT', '/v1/site-profile', $body, [], true, true);
    }
}
