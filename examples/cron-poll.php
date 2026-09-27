<?php

declare(strict_types=1);

/**
 * Run every minute:  * * * * * php /path/to/cron-poll.php
 *
 * AJA cannot reach your server, so this script comes to collect the actions
 * the merchant took in the mobile app.
 */

require __DIR__ . '/../vendor/autoload.php';

use Aja\Client;
use Aja\Dto\Command;
use Aja\Exception\AjaException;

$aja = new Client(
    apiKey: (string) getenv('AJA_KEY'),
    baseUrl: (string) (getenv('AJA_URL') ?: 'https://ajabackend-production.up.railway.app'),
);

foreach ($aja->commands()->pending() as $command) {
    if ($command->type !== Command::TYPE_ORDER_TRANSITION) {
        // Unknown type: reject it so the queue keeps moving instead of stalling.
        $aja->commands()->ack($command->id, ok: false, reason: 'unsupported_type');
        continue;
    }

    $status = $command->targetStatus();
    if ($status === null) {
        $aja->commands()->ack($command->id, ok: false, reason: 'missing status');
        continue;
    }

    try {
        // TODO: your own code. It MUST be idempotent — if this script dies
        // before the ack below, the same command comes back next minute.
        $myShop->changeStatus($command->externalId, $status);

        $aja->commands()->ack(
            $command->id,
            ok: true,
            status: $status,
            allowedNext: $myShop->nextStatuses($command->externalId),
        );
    } catch (DomainReject $e) {
        // The shop is the source of truth: refusing is a normal answer.
        $aja->commands()->ack($command->id, ok: false, reason: $e->getMessage());
    } catch (AjaException $e) {
        error_log('AJA ack failed: ' . $e->getMessage());
    }
}
