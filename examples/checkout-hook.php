<?php

declare(strict_types=1);

/**
 * Paste this right AFTER your existing "order saved" code.
 * It must never block the sale: AJA being down is not the shopper's problem.
 */

require __DIR__ . '/../vendor/autoload.php';

use Aja\Client;
use Aja\Dto\OrderInput;
use Aja\Exception\AjaException;
use Aja\Money;

$aja = new Client(
    apiKey: (string) getenv('AJA_KEY'),
    baseUrl: (string) (getenv('AJA_URL') ?: 'https://api.ajaflow.com'),
);

try {
    $aja->orders()->create(new OrderInput(
        externalId: (string) $order->id,          // your own primary key
        status: 'pending',
        allowedNext: ['processing', 'cancelled'],
        paymentStatus: 'pending',                 // pending|paid|failed|refunded
        paymentMode: 'cod',
        // Integers in the currency's ISO 4217 minor unit, whatever your shop
        // displays: "15000.00" in XOF is 15000, "12.50" in EUR is 1250.
        total: Money::toMinor($order->total, 'XOF'),
        currency: 'XOF',
        customer: [
            'name' => $order->customer_name,
            'phone' => $order->customer_phone,
            'email' => $order->customer_email,
        ],
        lines: [
            ['name' => 'Pagne', 'quantity' => 1, 'unit_price' => Money::toMinor($order->line_price, 'XOF')],
        ],
        extras: ['quartier' => 'Plateau'],
    ));
} catch (AjaException $e) {
    // Log and move on. Retry later from your own queue if you have one.
    error_log('AJA push failed: ' . $e->getMessage());
}
