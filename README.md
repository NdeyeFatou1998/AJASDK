# aja/sdk — client PHP

Client PHP de l'API AJA, pour un site déjà en production.

**Le sens des appels compte.** Votre site appelle AJA. AJA n'appelle jamais
votre site, n'entre pas dans votre base, votre FTP ni votre back-office. Vous
n'avez aucun port à ouvrir, aucune URL publique à exposer.

Conséquence directe : les décisions que le marchand prend dans l'application
mobile ne peuvent pas vous être poussées. C'est **vous** qui venez les
chercher, avec un cron. Ce SDK fait les deux sens.

---

## 1. Avant de commencer : obtenir une clé

Une clé API = une boutique = un espace dans AJA. Elle commence par
`aja_test_` (essai) ou `aja_live_` (production).

Vous l'obtenez depuis l'espace marchand AJA, section **Réglages → Nouvelle
boutique**. Elle n'est affichée **qu'une seule fois** : copiez-la
immédiatement.

Le SDK vise l'API de production par défaut :

```
https://ajabackend-production.up.railway.app
```

Vous n'avez donc **rien à configurer** pour démarrer. Ne surchargez `baseUrl`
que pour viser un autre environnement, typiquement un backend local :

```bash
AJA_URL=http://localhost:8080
```

---

## 2. Installation

Avec Composer :

```bash
composer require aja/sdk
```

Sans Composer (hébergement mutualisé, site legacy), copiez le dossier `src/`
dans votre projet et enregistrez un autoloader :

```php
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Aja\\')) {
        return;
    }
    $path = __DIR__ . '/aja-sdk/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
```

Le SDK n'a **aucune dépendance** : `ext-curl` et `ext-json`, présentes partout.

Stockez la clé hors du dépôt : variable d'environnement, `wp-config.php`, ou
fichier de configuration non versionné. **Jamais dans le code, jamais côté
navigateur.**

```php
use Aja\Client;

$aja = new Client(
    apiKey: getenv('AJA_KEY'),
    baseUrl: getenv('AJA_URL'),
);
```

### Déclarer votre boutique : devise et fuseau

AJA ne devine ni la devise de votre boutique ni son fuseau : c'est votre
boutique qui fait foi. Sans cette déclaration, l'application peut lire vos
prix dans une autre devise — 1250 lu comme 1 250 F CFA au lieu de 12,50 €.
Déclarez-la quand la boutique se connecte, puis une fois par nuit, avec
votre catalogue :

```php
$aja->site()->putProfile(currency: 'EUR', timezone: 'Europe/Paris');
```

`currency` est un code ISO 4217 ; `timezone`, un nom IANA (`Africa/Dakar`,
`Europe/Paris`). Un décalage nu comme `+02:00` n'en est pas un : AJA le
refuse avec `VALIDATION`. L'appel se répète sans risque.

**L'adresse de réveil.** Une boutique qui ne vient chercher ses actions que
sur une tâche planifiée déclenchée par les visites (WP-Cron) ne les applique
pas quand personne ne passe. Donnez à AJA une adresse publique en https qu'il
appellera (GET, au plus toutes les 30 s) quand une action vous attend :

```php
$aja->site()->putProfile(wakeUrl: 'https://votre-boutique.com/wp-cron.php?doing_wp_cron');
```

AJA n'appelle jamais une adresse interne (localhost, réseau privé) et ne lit
pas la réponse.

**La preuve de propriété.** N'importe qui peut déclarer le domaine d'une autre
boutique. Avant de passer un site en production, AJA vérifie donc que le
serveur derrière le domaine déclaré détient sa clé : il appelle votre adresse
de réveil avec un paramètre de plus, `aja_verify=<code>`, et attend que ce code
lui revienne avec la clé. Votre gestionnaire de réveil le renvoie en une ligne,
avant son travail habituel :

```php
// Le script de votre adresse de réveil
$aja->site()->handleWake($_GET);   // renvoie le code à AJA quand il le demande
// … puis votre tâche habituelle : commands()->pending(), ack(), etc.
```

Sans `aja_verify`, ou avec un code qui n'a pas la forme de ceux d'AJA,
`handleWake()` rend `null` sans rien envoyer. Un code qu'AJA refuse (inconnu,
expiré, ou domaine changé depuis) rend `['verified' => false]` sans lever :
une adresse publique reçoit n'importe quoi. Les autres erreurs (clé révoquée,
réseau) lèvent comme tout appel. Sur WordPress, l'adresse est `wp-cron.php` :
appelez `handleWake(wp_unslash($_GET))` depuis le crochet `init` — l'extension
AJA pour WooCommerce le fait déjà. `confirmVerification($code)` fait l'envoi
seul, si vous lisez le code vous-même.

---

## 3. Étape 1 — envoyer vos commandes

À placer **juste après** votre enregistrement de commande existant. Ne
remplacez pas votre modèle : AJA garde une copie, votre base reste la
référence.

```php
use Aja\Dto\OrderInput;
use Aja\Exception\AjaException;

try {
    $aja->orders()->create(new OrderInput(
        externalId: (string) $commande->id,   // VOTRE identifiant
        status: 'pending',
        allowedNext: ['processing', 'cancelled'],
        paymentStatus: 'pending',
        paymentMode: 'cod',
        total: 15000,
        currency: 'XOF',
        customer: [
            'name'  => $commande->client_nom,
            'phone' => $commande->client_tel,   // sert aux boutons WhatsApp / appel
            'email' => $commande->client_email,
        ],
        lines: [
            ['name' => 'Pagne wax', 'quantity' => 1, 'unit_price' => 15000],
        ],
        extras: ['quartier' => 'Plateau'],
    ));
} catch (AjaException $e) {
    error_log('AJA: ' . $e->getMessage());
    // On continue : la vente a déjà eu lieu.
}
```

**Trois règles, dans l'ordre d'importance :**

1. **N'interrompez jamais la vente.** Entourez l'appel d'un `try/catch` et
   continuez. AJA indisponible n'est pas le problème de votre client.
2. **`externalId` est votre identifiant**, celui de votre base. C'est la clé
   d'idempotence : renvoyer la même commande la met à jour au lieu d'en créer
   une seconde.
3. **`allowedNext` décide des boutons** affichés au marchand. AJA n'a aucune
   machine à états : si vous ne listez pas `cancelled`, le marchand ne pourra
   pas annuler.

### Le statut de paiement : le SDK traduit votre vocabulaire

Le format d'échange est figé à quatre valeurs — `pending`, `paid`, `failed`,
`refunded` — mais **vous n'êtes pas obligé de les utiliser**. Le SDK reconnaît
les synonymes courants, en anglais comme en français, sans tenir compte de la
casse, des accents ni des séparateurs.

| Vous envoyez | Part en |
|---|---|
| `unpaid`, `impayé`, `contre-remboursement`, `à payer` | `pending` |
| `paid`, `payé`, `réglé`, `encaissé`, `captured` | `paid` |
| `failed`, `échoué`, `refusé`, `declined` | `failed` |
| `refunded`, `remboursé`, `avoir`, `chargeback` | `refunded` |

`Partiellement Payé`, `partiellement-paye` et `PARTIALLY_PAID` aboutissent au
même endroit : la comparaison ignore la forme.

**Deux cas sont des décisions métier, pas des traductions.** Le SDK tranche
dans le sens prudent, et le dit :

| Cas | Choix du SDK | Pourquoi |
|---|---|---|
| `partially_paid` | `pending` | L'argent n'est pas entièrement rentré : ne pas annoncer au marchand une commande payée et expédiable. |
| `partially_refunded` | `paid` | La commande **a bien été payée**. La dire remboursée la ferait disparaître des statistiques « payées uniquement ». |

Si votre boutique n'est pas d'accord, surchargez — voir ci-dessous.

### Votre propre vocabulaire

Un mot inconnu n'est pas envoyé au serveur : le SDK échoue immédiatement en
nommant le coupable. Déclarez votre vocabulaire une seule fois au démarrage :

```php
use Aja\Payment;

Payment::registerAliases([
    'en cours de vérification' => 'pending',
    'soldé' => 'paid',
    'partially_refunded' => 'refunded', // on assume le choix inverse
]);
```

Un alias déjà connu remplace la valeur par défaut : c'est ainsi qu'on impose
son propre arbitrage sur les deux cas ci-dessus.

### Les montants — l'erreur la plus fréquente

`total` et `unit_price` sont des **entiers**, dans la plus petite unité de la
devise **selon ISO 4217** — jamais le nombre de décimales que votre boutique
choisit d'afficher. Une boutique en francs CFA réglée pour afficher
« 15 000,00 F » envoie quand même `15000`.

| Devise | Exposant | 15 000 s'écrit | 12,50 s'écrit | 1,250 s'écrit |
|---|---|---|---|---|
| XOF, XAF, JPY, VND… | 0 | `15000` | — | — |
| EUR, USD, MAD… (toutes les autres) | 2 | `1500000` | `1250` | — |
| KWD, BHD, TND… | 3 | — | — | `1250` |

`Money` convertit la chaîne décimale que votre base stocke, sans passer par
un flottant, et arrondit au demi supérieur quand il y a trop de décimales :

```php
use Aja\Money;

Money::toMinor('12.50', 'EUR');     // 1250
Money::toMinor('15000.00', 'XOF');  // 15000
Money::toMinor('12.505', 'EUR');    // 1251
Money::toMinor('', 'EUR');          // null : pas de montant, jamais 0
Money::fromMinor(1250, 'EUR');      // "12.50" — pour réécrire un prix reçu d'AJA
Money::exponent('KWD');             // 3
```

Ne multipliez jamais un flottant : en binaire, `1.005` vaut
`1.00499999999999989…`, et `(int) (1.005 * 100)` donne `100`. Un flottant
dans `total` est refusé avant même l'appel réseau.

---

## 4. Étape 2 — le cron qui récupère les décisions

Sans cette étape, les statuts changés dans l'application **n'arriveront jamais
chez vous**. C'est la moitié de l'intégration.

```bash
* * * * * php /chemin/vers/aja-poll.php
```

```php
use Aja\Dto\Command;

foreach ($aja->commands()->pending() as $command) {
    if ($command->type !== Command::TYPE_ORDER_TRANSITION) {
        // Refuser, ne pas ignorer : la file est FIFO, un ordre laissé en
        // attente bloque tous les suivants.
        $aja->commands()->ack($command->id, false, null, [], 'unsupported_type');
        continue;
    }

    $status = $command->targetStatus();

    try {
        monSite_changerStatut($command->externalId, $status);

        $aja->commands()->ack(
            $command->id,
            true,
            $status,
            monSite_prochainsStatuts($command->externalId),
        );
    } catch (RefusMetier $e) {
        // Votre boutique est la source de vérité : refuser est une réponse
        // normale. AJA remet la commande dans son état précédent.
        $aja->commands()->ack($command->id, false, null, [], $e->getMessage());
    }
}
```

### Votre `changerStatut` doit être idempotent

Si le cron meurt **après** votre mise à jour locale mais **avant** l'`ack`, le
prochain passage vous redonnera le même ordre. Appliquer deux fois le même
statut doit être sans effet :

```php
function monSite_changerStatut(string $id, string $status): void
{
    $commande = trouver($id);
    if ($commande->statut === $status) {
        return;   // déjà fait, on ne refait rien
    }
    // …
}
```

**N'acquittez jamais avant d'avoir réussi.** L'`ack` signifie « c'est appliqué
chez moi », pas « j'ai reçu le message ».

---

## 5. Étape 3 — vos propres changements de statut

Quand le statut change dans **votre** back-office, prévenez AJA, sinon
l'application affichera une information périmée.

```php
$aja->orders()->update((string) $commande->id, [
    'status'         => 'processing',
    'allowed_next'   => ['completed', 'cancelled'],
    'payment_status' => 'paid',
]);
```

---

## 6. Référence

| Appel | Route | Rôle |
|---|---|---|
| `orders()->create(OrderInput)` | `POST /v1/orders` | Créer ou mettre à jour (upsert sur `externalId`) |
| `orders()->update(string, array)` | `PATCH /v1/orders/{external_id}` | Modifier une commande existante |
| `commands()->pending(int $limit = 20)` | `GET /v1/commands` | Lire la file, plus ancienne d'abord |
| `commands()->ack(id, ok, status, allowedNext, reason)` | `POST /v1/commands/{id}/ack` | Confirmer ou refuser |
| `site()->putProfile(currency, timezone, wakeUrl)` | `PUT /v1/site-profile` | Déclarer la devise, le fuseau et l'adresse de réveil de la boutique |
| `site()->handleWake(array $query)` | `POST /v1/site-verification` | Depuis l'adresse de réveil : renvoyer à AJA le code `aja_verify`, s'il y en a un |
| `site()->confirmVerification(string $code)` | `POST /v1/site-verification` | Renvoyer un code lu soi-même |

### `OrderInput`

| Champ | Type | Notes |
|---|---|---|
| `externalId` | `string` | Votre identifiant. Clé d'idempotence. |
| `status` | `string` | Votre vocabulaire, libre. |
| `allowedNext` | `string[]` | Les boutons proposés au marchand. |
| `paymentStatus` | `string` | `pending`, `paid`, `failed`, `refunded`. |
| `paymentMode` | `?string` | Libre : `cod`, `wave`, `card`… |
| `total` | `int` | **Entier.** Voir § montants. |
| `currency` | `string` | Code ISO à 3 lettres. |
| `customer` | `array` | `name` **ou** `phone` obligatoire. |
| `lines` | `array[]` | Au moins une ligne. |
| `extras` | `array` | JSON libre, non interprété par AJA. |

### `create()` ou `update()` ? La règle

| Votre situation | Appel | Comportement |
|---|---|---|
| Nouvelle commande | `create()` | **remplace** tout l'enregistrement |
| Re-synchroniser une commande entière | `create()` | **remplace** tout l'enregistrement |
| Changer un ou deux champs | `update()` | **fusionne** : ce que vous omettez est conservé |

`create()` remplace, il ne fusionne pas. Renvoyer une commande sans son email
ou ses `extras` les efface. Deux façons de ne jamais tomber dans le piège :
envoyer l'objet complet, ou passer par `update()` pour un changement partiel.

Bonus sur `update()` : si le statut que vous envoyez correspond à celui que le
marchand venait de demander depuis l'application, AJA **acquitte
automatiquement** l'ordre en attente. Vous n'avez rien à faire de plus.

---

## 7. Erreurs

Branchez-vous sur `getErrorCode()`. **Jamais sur `getMessage()`** : il est en
anglais et destiné aux journaux, pas à vos clients.

```php
use Aja\Exception\ApiException;
use Aja\ErrorCode;

try {
    $aja->orders()->create($commande);
} catch (ApiException $e) {
    match ($e->getErrorCode()) {
        ErrorCode::SITE_KEY_INVALID  => alerterAdmin('Clé AJA invalide'),
        ErrorCode::VALIDATION        => error_log('Payload refusé : ' . json_encode($e->getDetails())),
        default                      => error_log('AJA ' . $e->getErrorCode() . ' req=' . $e->getRequestId()),
    };
}
```

| Code | Signification | Le SDK |
|---|---|---|
| `SITE_KEY_INVALID` / `_REVOKED` | Clé fausse ou révoquée | lève aussitôt, **aucun retry** |
| `VALIDATION` | Payload refusé — voir `fields()` | lève, aucun retry |
| `ORDER_NOT_FOUND` | `external_id` inconnu d'AJA | lève, aucun retry |
| `COMMAND_ALREADY_ACKED` | Déjà acquitté | **traité comme un succès** |
| `TEST_TRIAL_EXPIRED` | Essai terminé | lève, aucun retry |
| `VERIFICATION_FAILED` | Code de preuve inconnu, expiré, ou domaine changé (HTTP 422) | lève, aucun retry — `handleWake()` rend `['verified' => false]` |
| `RATE_LIMITED`, 502/503/504 | Incident passager | 3 tentatives, 200 ms × 2 |
| *réseau, DNS, timeout* | `NetworkException` | 3 tentatives puis lève |

`ValidationException::fields()` donne le détail champ par champ — c'est là
qu'on voit un `total` envoyé en flottant.

---

## 8. Passer en production

1. Intégrez avec une clé `aja_test_`.
2. Vérifiez les deux sens : une commande arrive dans l'application, **et** un
   changement de statut fait depuis l'application arrive chez vous.
3. Votre adresse de réveil appelle `site()->handleWake($_GET)` (§2, « La
   preuve de propriété ») : AJA vérifie ainsi que votre serveur détient la clé
   avant de passer le site en production.
4. Créez une clé `aja_live_` dans l'espace marchand, remplacez la valeur.

Rien d'autre ne change : même code, même contrat.

---

## 9. Dépannage

| Symptôme | Cause la plus probable |
|---|---|
| `NetworkException` immédiate | `AJA_URL` absente ou domaine inactif |
| `SITE_KEY_INVALID` | Clé tronquée à la copie, ou espace parasite |
| Les commandes arrivent, les statuts ne reviennent jamais | Le cron ne tourne pas |
| Un statut revient sans cesse | Votre `changerStatut` échoue, ou vous n'acquittez pas |
| Boutons absents dans l'application | `allowedNext` vide au moment du push |
| Montants multipliés ou divisés par 100 | Conversion faite avec les décimales affichées par la boutique au lieu de l'exposant ISO 4217 — passez par `Money::toMinor()` |
| Prix lus dans la mauvaise devise dans l'application | Profil jamais déclaré : `site()->putProfile()` |

Pour signaler un incident à AJA, joignez le `getRequestId()` : il identifie
l'appel exact dans les journaux serveur.
