<?php

declare(strict_types=1);

namespace Aja\Api;

use Aja\Client;
use Aja\Dto\OrderInput;
use Aja\Exception\ApiException;
use Aja\Exception\NetworkException;

/**
 * Site-facing orders: create (upsert) and patch by the shop's external_id.
 */
final class OrdersApi
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * POST /v1/orders. Safe to retry: the backend upserts on external_id, so a
     * timeout followed by a retry cannot create a duplicate order.
     *
     * @return array<string,mixed> the stored order as AJA sees it
     *
     * @throws ApiException|NetworkException
     */
    public function create(OrderInput $input): array
    {
        return $this->client->send(
            'POST',
            '/v1/orders',
            $input->toArray(),
            [],
            true,
            true,
        );
    }

    /**
     * PATCH /v1/orders/{external_id} for a change born on the shop side —
     * the merchant edited the order in their own admin, not in the AJA app.
     *
     * Only send the keys that changed: status, allowed_next, payment_status,
     * payment_mode, extras.
     *
     * @param array<string,mixed> $changes
     *
     * @return array<string,mixed>
     *
     * @throws ApiException|NetworkException
     */
    public function update(string $externalId, array $changes): array
    {
        return $this->client->send(
            'PATCH',
            '/v1/orders/' . rawurlencode($externalId),
            $changes,
            [],
            true,
            true,
        );
    }
}
