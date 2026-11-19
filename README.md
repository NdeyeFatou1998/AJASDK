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

## Brancher votre site (une fois)

Tant que ces deux étapes ne sont pas faites, AJA refuse les commandes de votre site (`SITE_NOT_VERIFIED`) et la page de votre boutique reste « en attente ».

**1. Une adresse de réveil.** Une route publique, en https, sur le domaine exact que vous avez indiqué dans AJA (`api.maboutique.sn` si votre serveur est séparé de la vitrine ; « www. » ne compte pas). Une adresse sur un autre domaine est refusée (`domain_mismatch`). AJA l'appelle pour vérifier que ce domaine est bien le vôtre, puis à chaque décision du marchand. Répondez tout de suite : AJA n'attend que quelques secondes. Travaillez ensuite.

```php
// aja-wake.php, publié à https://api.maboutique.sn/aja-wake.php
http_response_code(204);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();             // répondre d'abord
}
$aja->site()->handleWake($_GET);          // renvoie le code si AJA vérifie le domaine
appliquerLesDecisions($aja);              // voir plus bas
```

**2. Se présenter à AJA**, au démarrage de votre serveur : la devise, le fuseau horaire et l'adresse de réveil. AJA appelle aussitôt cette adresse, votre site lui renvoie le code, et le domaine est prouvé, sans rien d'autre à faire.

```php
$aja->site()->putProfile('XOF', 'Africa/Dakar', 'https://api.maboutique.sn/aja-wake.php');
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

Ce code est votre `appliquerLesDecisions($aja)` : l'adresse de réveil l'appelle à chaque décision du marchand. Une tâche planifiée chaque minute reste un filet de sécurité, si un appel d'AJA se perd :

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
