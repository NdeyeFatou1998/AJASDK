# aja/sdk — SDK PHP d'AJA

Relie une boutique en ligne à AJA : ses commandes arrivent sur le téléphone du marchand, et les statuts qu'il change reviennent à la boutique.

## Installation

```bash
composer require aja/sdk
```

PHP 8.1 ou plus, avec les extensions curl et json. Aucune autre dépendance.

## La clé

Le marchand crée la clé de sa boutique dans son espace AJA, page **Boutiques**. Gardez-la côté serveur, dans une variable d'environnement : jamais dans le navigateur, jamais dans un dépôt.

```php
use Aja\Client;

$aja = new Client(apiKey: getenv('AJA_KEY'));
```

## Envoyer une commande

Juste après l'enregistrement de la commande chez vous. Une panne d'AJA ne doit jamais bloquer la vente.

```php
use Aja\Dto\OrderInput;
use Aja\Exception\AjaException;

try {
    $aja->orders()->create(new OrderInput(
        externalId: (string) $order->id,
        status: 'pending',
        allowedNext: ['processing', 'cancelled'],
        paymentStatus: 'paid',
        paymentMode: 'wave',
        total: 44500,
        currency: 'XOF',
        customer: ['name' => 'Khadija Ndiaye', 'phone' => '+221770001122'],
        lines: [
            ['name' => 'Ensemble bazin riche', 'quantity' => 1, 'unit_price' => 42000],
        ],
    ));
} catch (AjaException $e) {
    error_log('AJA: ' . $e->getMessage());
}
```

## Appliquer les décisions du marchand

Chaque minute, avec une tâche planifiée :

```bash
* * * * * php /var/www/aja-poll.php
```

```php
use Aja\Dto\Command;

foreach ($aja->commands()->pending() as $command) {
    if ($command->type !== Command::TYPE_ORDER_TRANSITION) {
        $aja->commands()->ack($command->id, false, null, [], 'unsupported_type');
        continue;
    }
    $status = $command->targetStatus();
    try {
        applyStatus($command->externalId, $status);
        $aja->commands()->ack($command->id, true, $status, nextStatuses($command->externalId));
    } catch (ShopRefusal $refusal) {
        $aja->commands()->ack($command->id, false, null, [], $refusal->getMessage());
    }
}
```

## Documentation

Le guide complet (profil de la boutique, catalogue, montants, erreurs, mise en service) est dans votre espace marchand AJA :
https://www.ajaflow.com/app/documentation

## Licence

MIT
