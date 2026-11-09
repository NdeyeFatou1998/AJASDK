<?php

declare(strict_types=1);

namespace Aja\Api;

use Aja\Client;
use Aja\Dto\Command;
use Aja\ErrorCode;
use Aja\Exception\ApiException;
use Aja\Exception\NetworkException;

/** The queue the shop polls. */
final class CommandsApi
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * GET /v1/commands — oldest pending first.
     *
     * @return list<Command>
     * @throws ApiException|NetworkException
     */
    public function pending(int $limit = 20, int $wait = 0): array
    {
        $wait = max(0, min($wait, 20));
        $query = ['limit' => (string) $limit];
        if ($wait > 0) {
            $query['wait'] = (string) $wait;
        }

        $response = $this->client->send(
            'GET',
            '/v1/commands',
            null,
            $query,
            true,
            true,
            $wait > 0 ? $wait + 10 : null,
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
     * @param list<string> $allowedNext
     * @param ?string $externalId
     * @throws ApiException|NetworkException
     */
    public function ack(
        string $commandId,
        bool $ok,
        ?string $status = null,
        array $allowedNext = [],
        ?string $reason = null,
        ?string $externalId = null,
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
        if ($externalId !== null) {
            $body['external_id'] = $externalId;
        }

        try {
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
