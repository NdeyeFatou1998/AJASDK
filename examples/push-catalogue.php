<?php

declare(strict_types=1);

/**
 * Push the catalogue so the merchant can see it on their phone.
 *
 * Run it once to seed, then whenever a product changes in your own admin —
 * the same place you already call orders()->update() from.
 *
 * Declaring the form is what makes the app draw YOUR fields. Without it the
 * app still lists the products, but it has to guess what each key means.
 */

require __DIR__ . '/../vendor/autoload.php';

use Aja\Client;
use Aja\Exception\AjaException;
use Aja\Money;

$aja = new Client(
    apiKey: (string) getenv('AJA_KEY'),
    baseUrl: (string) (getenv('AJA_URL') ?: 'https://ajabackend-production.up.railway.app'),
);

// Bump `version` every time these fields change. An edit filled against an
// older version is refused rather than saved with stale keys.
$schema = [
    'version' => 3,
    'fields' => [
        ['key' => 'nom', 'label' => 'Nom du produit', 'type' => 'text', 'required' => true],
        ['key' => 'prix', 'label' => 'Prix', 'type' => 'money', 'required' => true],
        ['key' => 'stock', 'label' => 'Stock', 'type' => 'number'],
        ['key' => 'photo', 'label' => 'Photo', 'type' => 'image'],
        ['key' => 'perso', 'label' => 'Personnalisable', 'type' => 'boolean'],
        ['key' => 'prix_perso', 'label' => 'Prix personnalisation', 'type' => 'money', 'show_if' => 'perso'],
        ['key' => 'taille', 'label' => 'Taille', 'type' => 'select', 'options' => ['S', 'M', 'L', 'XL']],
    ],
];

try {
    // Say who the shop is first: the currency tells AJA how to read every
    // price below. Your shop is the source of truth; repeat it every night.
    $aja->site()->putProfile(currency: 'XOF', timezone: 'Africa/Dakar');
    $aja->products()->putSchema($schema);

    foreach ($myShop->allProducts() as $product) {
        // Keys are yours. AJA stores `values` without understanding it, which
        // is what lets two shops have nothing in common.
        $aja->products()->upsert(
            (string) $product->id,
            [
                'nom' => $product->name,
                // Money fields are integers in the ISO 4217 minor unit. An
                // empty price stays null — never 0, which would read as free.
                'prix' => Money::toMinor($product->price, 'XOF'),
                'stock' => $product->stock,
                'photo' => $product->imageUrl,
                'perso' => $product->customisable,
                'prix_perso' => Money::toMinor($product->customisationPrice, 'XOF'),
                'taille' => $product->size,
            ],
            schemaVersion: $schema['version'],
        );
    }
} catch (AjaException $e) {
    error_log('AJA catalogue push failed: ' . $e->getMessage());
}
