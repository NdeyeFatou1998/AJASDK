<?php

declare(strict_types=1);

namespace Aja\Api;

use Aja\Client;
use Aja\Exception\ApiException;
use Aja\Exception\NetworkException;

/** Site-facing catalogue: declare the form, push the products. */
final class ProductsApi
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * PUT /v1/product-schema — declare the product form the shop's own admin already uses.
     *
     * @param array<string,mixed> $schema
     * @return array<string,mixed>
     * @throws ApiException|NetworkException
     */
    public function putSchema(array $schema): array
    {
        return $this->client->send('PUT', '/v1/product-schema', $schema, [], true, true);
    }

    /**
     * POST /v1/products — upsert on external_id, exactly like an order.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     * @throws ApiException|NetworkException
     */
    public function upsert(string $externalId, array $values, int $schemaVersion = 0): array
    {
        return $this->client->send(
            'POST',
            '/v1/products',
            [
                'external_id' => $externalId,
                'schema_version' => $schemaVersion,
                'values' => $values,
            ],
            [],
            true,
            true,
        );
    }

    /**
     * DELETE /v1/products/{external_id} — drop it from the copy.
     *
     * @throws ApiException|NetworkException
     */
    public function delete(string $externalId): void
    {
        $this->client->send(
            'DELETE',
            '/v1/products/' . rawurlencode($externalId),
            null,
            [],
            true,
            true,
        );
    }
}
