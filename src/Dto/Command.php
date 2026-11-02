<?php

declare(strict_types=1);

namespace Aja\Dto;

/**
 * One queued action the merchant asked for from the app.
 *
 * AJA cannot reach the shop, so nothing is pushed: the shop polls this queue,
 * applies the action locally, then acknowledges it.
 */
final class Command
{
    public const TYPE_ORDER_TRANSITION = 'order.transition';
    public const TYPE_PRODUCT_CREATE = 'product.create';
    public const TYPE_PRODUCT_UPDATE = 'product.update';
    public const TYPE_PRODUCT_DELETE = 'product.delete';
    public const TYPE_PRODUCT_STOCK = 'product.stock';

    /**
     * @param array<string,mixed> $payload
     * @param ?string             $externalId null for a product.create — the
     *                                        shop attributes the id itself and
     *                                        returns it in the ack
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly ?string $externalId,
        public readonly array $payload,
        public readonly string $createdAt,
    ) {
    }

    /**
     * Build from one GET /v1/commands entry.
     *
     * @param array<string,mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $payload = $row['payload'] ?? [];
        $externalId = $row['external_id'] ?? null;

        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['type'] ?? ''),
            is_string($externalId) && $externalId !== '' ? $externalId : null,
            is_array($payload) ? $payload : [],
            (string) ($row['created_at'] ?? ''),
        );
    }

    /** True for the catalogue types a V1 integration can safely refuse. */
    public function isProduct(): bool
    {
        return str_starts_with($this->type, 'product.');
    }

    /** Target status for an order.transition, or null for other types. */
    public function targetStatus(): ?string
    {
        $status = $this->payload['status'] ?? null;

        return is_string($status) && $status !== '' ? $status : null;
    }

    /**
     * Fields to write, for a product.create or product.update.
     *
     * Keys are the shop's own, as declared in its schema — AJA never renames
     * or invents one.
     *
     * @return array<string,mixed>
     */
    public function values(): array
    {
        $values = $this->payload['values'] ?? null;

        return is_array($values) ? $values : [];
    }

    /** Version of the form the merchant filled, so the shop can refuse a stale one. */
    public function schemaVersion(): int
    {
        $version = $this->payload['schema_version'] ?? null;

        return is_int($version) ? $version : 0;
    }

    /** New stock level for a product.stock, or null for other types. */
    public function stock(): ?int
    {
        $stock = $this->payload['stock'] ?? null;

        return is_int($stock) ? $stock : null;
    }
}
