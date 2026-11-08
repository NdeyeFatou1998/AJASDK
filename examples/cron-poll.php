<?php

declare(strict_types=1);

/**
 * Run every minute:  * * * * * php /path/to/cron-poll.php
 *
 * AJA cannot reach your server, so this script comes to collect the actions
 * the merchant took in the mobile app.
 *
 * V2 adds four product types to the same queue, plus a probe. There is no
 * second cron to write: a V1 integration grows a few branches in the match
 * below, and one that does not want the catalogue simply keeps refusing them.
 *
 * Le long polling : `pending(wait: 20)` demande à AJA de tenir la requête
 * jusqu'à ce qu'une action arrive, au lieu de répondre vide tout de suite. La
 * latence tombe d'une minute à presque rien, sans rien changer d'autre.
 */

require __DIR__ . '/../vendor/autoload.php';

use Aja\Client;
use Aja\Dto\Command;
use Aja\Exception\AjaException;

$aja = new Client(
    apiKey: (string) getenv('AJA_KEY'),
    baseUrl: (string) (getenv('AJA_URL') ?: 'https://api.ajaflow.com'),
);

foreach ($aja->commands()->pending(wait: 20) as $command) {
    try {
        match ($command->type) {
            Command::TYPE_ORDER_TRANSITION => applyTransition($aja, $myShop, $command),
            Command::TYPE_PRODUCT_UPDATE => applyProductUpdate($aja, $myShop, $command),
            Command::TYPE_PRODUCT_CREATE => applyProductCreate($aja, $myShop, $command),
            Command::TYPE_PRODUCT_DELETE => applyProductDelete($aja, $myShop, $command),
            Command::TYPE_PRODUCT_STOCK => applyProductStock($aja, $myShop, $command),
            // La sonde : AJA demande si ce cron sait traiter les types
            // product.*. N'acquitter positivement QUE si les branches
            // ci-dessus sont réelles — un acquittement complaisant ouvre
            // l'édition du catalogue au marchand, et ses modifications se
            // perdront ensuite sans que personne ne sache pourquoi.
            Command::TYPE_PRODUCT_PROBE => $aja->commands()->ack($command->id, ok: true),
            // Never leave an unknown type pending: the queue is FIFO, and one
            // stuck command holds up every command behind it.
            default => $aja->commands()->ack($command->id, ok: false, reason: 'unsupported_type'),
        };
    } catch (DomainReject $e) {
        // The shop is the source of truth: refusing is a normal answer. The
        // app rolls back and shows this message to the merchant.
        $aja->commands()->ack($command->id, ok: false, reason: $e->getMessage());
    } catch (AjaException $e) {
        error_log('AJA ack failed: ' . $e->getMessage());
    }
}

function applyTransition(Client $aja, $myShop, Command $command): void
{
    $status = $command->targetStatus();
    if ($status === null) {
        $aja->commands()->ack($command->id, ok: false, reason: 'missing status');

        return;
    }

    // TODO: your own code. It MUST be idempotent — if this script dies before
    // the ack below, the same command comes back next minute.
    $myShop->changeStatus($command->externalId, $status);

    $aja->commands()->ack(
        $command->id,
        ok: true,
        status: $status,
        allowedNext: $myShop->nextStatuses($command->externalId),
    );
}

function applyProductUpdate(Client $aja, $myShop, Command $command): void
{
    // Validate with the rules you already wrote for your own admin. You do not
    // have to loosen anything for AJA — a refusal is shown to the merchant.
    // Money fields arrive as integers in the ISO 4217 minor unit:
    // Money::fromMinor($values['prix'], 'XOF') gives back the decimal string
    // your own form stores, and "" when the merchant cleared the field.
    $myShop->updateProduct($command->externalId, $command->values());
    $aja->commands()->ack($command->id, ok: true);
}

function applyProductCreate(Client $aja, $myShop, Command $command): void
{
    // external_id is null here: the product does not exist yet. Create it,
    // then hand back the id you attributed so AJA can stop calling it a draft.
    $newId = $myShop->createProduct($command->values());
    $aja->commands()->ack($command->id, ok: true, externalId: (string) $newId);
}

function applyProductDelete(Client $aja, $myShop, Command $command): void
{
    // Delete or merely deactivate — your shop, your call.
    $myShop->deleteProduct($command->externalId);
    $aja->commands()->ack($command->id, ok: true);
}

function applyProductStock(Client $aja, $myShop, Command $command): void
{
    $myShop->setStock($command->externalId, (int) $command->stock());
    $aja->commands()->ack($command->id, ok: true);
}
