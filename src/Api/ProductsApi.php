<?php

declare(strict_types=1);

namespace Aja\Api;

use Aja\Client;
use Aja\Exception\ApiException;
use Aja\Exception\NetworkException;

/**
 * Site-facing catalogue: declare the form, push the products.
 *
 * The shop stays the only source of truth. AJA keeps a copy because one
 * cannot list what one does not store — the same reasoning that already
 * applies to orders.
 */
final class ProductsApi
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * PUT /v1/product-schema — declare the product form the shop's own admin
     * already uses.
     *
     * AJA never interprets these fields; the app draws them as declared.
     * Bump `version` whenever the fields change: an edit filled against an
     * older version is refused rather than saved with stale keys.
     *
     * @param array<string,mixed> $schema {version: int, fields: list<array>}
     *
     * @return array<string,mixed>
     *
     * @throws ApiException|NetworkException
     */
    public function putSchema(array $schema): array
    {
        return $this->client->send('PUT', '/v1/product-schema', $schema, [], true, true);
    }

    /**
     * POST /v1/products — upsert on external_id, exactly like an order.
     *
     * Safe to retry: a timeout followed by a retry cannot create a duplicate.
     * `$values` is whatever the shop's own form holds; AJA stores it without
     * understanding it.
     *
     * @param array<string,mixed> $values
     *
     * @return array<string,mixed>
     *
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
     * The product itself is the shop's business; this only stops AJA showing
     * something the shop no longer sells.
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
