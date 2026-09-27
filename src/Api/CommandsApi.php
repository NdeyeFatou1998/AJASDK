<?php

declare(strict_types=1);

namespace Aja\Api;

use Aja\Client;
use Aja\Dto\Command;
use Aja\ErrorCode;
use Aja\Exception\ApiException;
use Aja\Exception\NetworkException;

/**
 * The queue the shop polls. AJA can never reach the shop, so every action the
 * merchant takes in the app waits here until the shop comes to collect it.
 */
final class CommandsApi
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * GET /v1/commands — oldest pending first. Also refreshes last_poll_at,
     * which is what the merchant sees as "dernier appel du site".
     *
     * Unknown types are returned as-is: the caller skips what it does not know
     * rather than the SDK hiding it.
     *
     * @return list<Command>
     *
     * @throws ApiException|NetworkException
     */
    public function pending(int $limit = 20): array
    {
        $response = $this->client->send(
            'GET',
            '/v1/commands',
            null,
            ['limit' => (string) $limit],
            true,
            true,
        );

        $rows = $response['commands'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = Command::fromArray($row);
            }
        }

        return $out;
    }

    /**
     * POST /v1/commands/{id}/ack — confirm or refuse an action.
     *
     * Call this only after the shop-side change actually succeeded. If the cron
     * dies between the local update and this call, the same command comes back
     * on the next poll, so the shop's own status change must be idempotent.
     *
     * COMMAND_ALREADY_ACKED is swallowed: a duplicate ack means the work is
     * done, which is exactly what the caller wanted.
     *
     * @param list<string> $allowedNext
     *
     * @throws ApiException|NetworkException
     */
    public function ack(
        string $commandId,
        bool $ok,
        ?string $status = null,
        array $allowedNext = [],
        ?string $reason = null,
    ): void {
        $body = ['ok' => $ok];
        if ($status !== null) {
            $body['status'] = $status;
        }
        if ($allowedNext !== []) {
            $body['allowed_next'] = array_values($allowedNext);
        }
        if ($reason !== null) {
            $body['reason'] = $reason;
        }

        try {
            // Transport retries only: a 429 or 5xx is not replayed automatically
            // because the next poll will hand us the command again anyway.
            $this->client->send(
                'POST',
                '/v1/commands/' . rawurlencode($commandId) . '/ack',
                $body,
                [],
                true,
                false,
            );
        } catch (ApiException $e) {
            if ($e->getErrorCode() === ErrorCode::COMMAND_ALREADY_ACKED) {
                return;
            }
            throw $e;
        }
    }
}
