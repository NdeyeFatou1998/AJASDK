<?php

declare(strict_types=1);

/**
 * Call this when the status changes in YOUR back-office, so the app shows the
 * same thing as your admin. This is the opposite direction from cron-poll.php.
 */

require __DIR__ . '/../vendor/autoload.php';

use Aja\Client;
use Aja\Exception\ApiException;
use Aja\ErrorCode;

$aja = new Client(
    apiKey: (string) getenv('AJA_KEY'),
    baseUrl: (string) (getenv('AJA_URL') ?: 'https://ajabackend-production.up.railway.app'),
);

try {
    $aja->orders()->update('882', [
        'status' => 'processing',
        'allowed_next' => ['completed', 'cancelled'],
        'payment_status' => 'paid',
    ]);
} catch (ApiException $e) {
    if ($e->getErrorCode() === ErrorCode::ORDER_NOT_FOUND) {
        // Never pushed, or pushed under a different external_id: send it whole.
        return;
    }
    error_log('AJA update failed: ' . $e->getErrorCode() . ' ' . (string) $e->getRequestId());
}
