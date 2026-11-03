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
     * Both order.* and product.* arrive here. Unknown types are returned
     * as-is: the caller refuses what it does not know rather than the SDK
     * hiding it — and must refuse it, because the queue is FIFO and one
     * command left pending holds up every command behind it.
     *
     * `$wait` demande à AJA de tenir la requête jusqu'à ce qu'une action
     * arrive, au lieu de répondre vide tout de suite. Même appel, même
     * direction : la latence tombe d'une minute à presque rien. 0 se comporte
     * exactement comme avant.
     *
     * @return list<Command>
     *
     * @throws ApiException|NetworkException
     */
    public function pending(int $limit = 20, int $wait = 0): array
    {
        // Plafonné à 20 et non 25 : max_execution_time vaut souvent 30 s sur
        // un hébergement mutualisé, et le script doit avoir le temps de
        // traiter ce qu'il reçoit après avoir attendu.
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
            // Le délai client doit dépasser l'attente demandée, sinon cURL
            // coupe la requête que nous venons de demander au serveur de
            // tenir. Dix secondes de marge pour le réseau et la réponse.
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
     * Call this only after the shop-side change actually succeeded. If the cron
     * dies between the local update and this call, the same command comes back
     * on the next poll, so the shop's own status change must be idempotent.
     *
     * COMMAND_ALREADY_ACKED is swallowed: a duplicate ack means the work is
     * done, which is exactly what the caller wanted.
     *
     * @param list<string> $allowedNext
     * @param ?string      $externalId the id the shop attributed, when acking
     *                                 a product.create. Without it AJA drops
     *                                 the draft and waits for the shop's own
     *                                 catalogue push to bring the real row.
     *
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
