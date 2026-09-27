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

    /** @param array<string,mixed> $payload */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $externalId,
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

        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['type'] ?? ''),
            (string) ($row['external_id'] ?? ''),
            is_array($payload) ? $payload : [],
            (string) ($row['created_at'] ?? ''),
        );
    }

    /** Target status for an order.transition, or null for other types. */
    public function targetStatus(): ?string
    {
        $status = $this->payload['status'] ?? null;

        return is_string($status) && $status !== '' ? $status : null;
    }
}
