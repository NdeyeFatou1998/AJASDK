<?php
/**
 * Le SDK contre une vraie API AJA.
 *
 * La CI vérifie que le SDK est du PHP valide. Ce scénario vérifie qu'il parle
 * correctement à l'API : chaque étape passe par le SDK, comme chez un
 * intégrateur. Seuls les gestes du marchand — inscription, boutique,
 * application — passent par HTTP directement.
 *
 * Le même scénario existe dans AJASDK-NODE et AJASDK-PYTHON, avec les mêmes
 * libellés, pour que les trois se comparent ligne à ligne.
 *
 *   AJA_API=http://localhost:8080 php conformance/run.php
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Aja\\')) {
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

use Aja\Client;
use Aja\Dto\OrderInput;
use Aja\ErrorCode;
use Aja\Exception\ApiException;

$API = rtrim(getenv('AJA_API') ?: 'http://localhost:8080', '/');
const PIXEL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    echo '  ' . ($ok ? '✓' : '✗') . ' ' . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
    if (!$ok) {
        $failures++;
    }
}

/** Ce que fait le marchand, hors SDK. @return array{0:int,1:array<string,mixed>} */
function app(string $method, string $path, ?string $token = null, ?array $body = null): array
{
    global $API;
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    $ch = curl_init($API . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $text = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [$status, $text === '' ? [] : (json_decode($text, true) ?? [])];
}

function raised(callable $fn): ?Throwable
{
    try {
        $fn();

        return null;
    } catch (Throwable $e) {
        return $e;
    }
}

function code(?Throwable $e): string
{
    return $e instanceof ApiException ? $e->getErrorCode() : ($e === null ? '' : get_class($e) . ': ' . $e->getMessage());
}

$SCHEMA = [
    'version' => 1,
    'fields' => [
        ['key' => 'nom', 'label' => 'Nom', 'type' => 'text', 'required' => true],
        ['key' => 'prix', 'label' => 'Prix', 'type' => 'money', 'required' => true],
    ],
];

echo "1. Le marchand ouvre une boutique\n";
[, $signup] = app('POST', '/v1/web/auth/register', null, [
    'email' => 'sdk-php-' . (int) (microtime(true) * 1000) . '@example.test',
    'password' => 'motdepasse-assez-long',
    'name' => 'SDK',
    'phone' => '+2250700000004',
    'business_name' => 'Boutique SDK',
    'country' => 'CI',
    'id_type' => 'cni',
    'id_number' => 'CI0000000004',
    'id_front' => PIXEL,
    'id_back' => PIXEL,
]);
$token = $signup['access_token'];
[$status, $site] = app('POST', '/v1/app/sites', $token, ['name' => 'SDK', 'platform' => 'custom', 'currency' => 'XOF']);
check('boutique créée', $status === 201, "HTTP $status");
$siteId = $site['id'];
$client = new Client($site['api_key'], $API);

echo "\n2. products()->putSchema\n";
$first = $client->products()->putSchema($SCHEMA);
check('le formulaire est déposé', ($first['version'] ?? null) === 1);
$again = $client->products()->putSchema($SCHEMA);
check("le redéposer à l'identique rend unchanged", ($again['unchanged'] ?? null) === true);
$changed = raised(fn () => $client->products()->putSchema([
    'version' => 1,
    'fields' => [...$SCHEMA['fields'], ['key' => 'poids', 'label' => 'Poids', 'type' => 'number']],
]));
check(
    'le changer sans changer de version lève ApiException VALIDATION',
    $changed instanceof ApiException && $changed->getErrorCode() === ErrorCode::VALIDATION,
    code($changed),
);
$details = $changed instanceof ApiException ? $changed->getDetails() : [];
check(
    '…qui nomme le champ en cause dans details',
    is_string($details['fields']['version'] ?? null),
    json_encode($details, JSON_UNESCAPED_UNICODE),
);

echo "\n3. commands()->pending et ack — la sonde\n";
$queue = $client->commands()->pending();
$probe = null;
foreach ($queue as $c) {
    if ($c->type === 'product.probe') {
        $probe = $c;
    }
}
check('la sonde arrive comme une Command', $probe !== null, implode(', ', array_map(fn ($c) => $c->type, $queue)));
$client->commands()->ack($probe->id, true);
[, $opened] = app('GET', "/v1/app/products?site_id=$siteId", $token);
check("son acquittement ouvre l'édition", ($opened['can_edit'] ?? null) === true);
$twice = raised(fn () => $client->commands()->ack($probe->id, true));
check('un second ack du même id ne lève rien', $twice === null, code($twice));

echo "\n4. products()->upsert et delete\n";
$client->products()->upsert('P-1', ['nom' => 'Boubou', 'prix' => 31500], 1);
[, $list] = app('GET', "/v1/app/products?site_id=$siteId", $token);
check(
    "le produit poussé apparaît dans l'application",
    (bool) array_filter($list['items'], fn ($i) => $i['external_id'] === 'P-1' && ($i['values']['prix'] ?? null) === 31500),
);
$client->products()->delete('P-1');
[, $list] = app('GET', "/v1/app/products?site_id=$siteId", $token);
check('supprimé, il disparaît', !array_filter($list['items'], fn ($i) => $i['external_id'] === 'P-1'));

echo "\n5. orders()->create, puis une transition demandée par l'application\n";
$client->orders()->create(new OrderInput(
    externalId: 'O-1',
    status: 'pending',
    allowedNext: ['processing'],
    paymentStatus: 'pending',
    paymentMode: null,
    total: 15000,
    currency: 'XOF',
    customer: ['name' => 'Awa'],
    lines: [['name' => 'Pagne', 'quantity' => 1, 'unit_price' => 15000]],
));
[, $orders] = app('GET', "/v1/app/orders?site_id=$siteId", $token);
$order = array_values(array_filter($orders['items'], fn ($o) => $o['external_id'] === 'O-1'))[0] ?? null;
check("la commande est dans l'application", $order !== null);
// La transition part d'un autre processus, une demi-seconde plus tard, pendant
// que pending() attend : c'est le seul moyen de voir le réveil en PHP.
$later = proc_open(
    [PHP_BINARY, '-r', sprintf(
        'usleep(500000); $c = curl_init(%s); curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => "POST", '
        . 'CURLOPT_HTTPHEADER => ["Content-Type: application/json", "Authorization: Bearer " . %s], '
        . 'CURLOPT_POSTFIELDS => \'{"status":"processing"}\', CURLOPT_RETURNTRANSFER => true]); curl_exec($c);',
        var_export($API . '/v1/app/orders/' . $order['id'] . '/transition', true),
        var_export($token, true),
    )],
    [],
    $pipes,
);
$started = microtime(true);
$woken = $client->commands()->pending(20, 5);
$elapsed = (int) ((microtime(true) - $started) * 1000);
proc_close($later);
$transition = null;
foreach ($woken as $c) {
    if ($c->type === 'order.transition') {
        $transition = $c;
    }
}
check('pending(wait) se réveille dès que la transition arrive', $transition !== null && $elapsed < 4000, "$elapsed ms");
check(
    'externalId et targetStatus sont lus',
    $transition !== null && $transition->externalId === 'O-1' && $transition->targetStatus() === 'processing',
);
$client->commands()->ack($transition->id, true, 'processing', ['completed']);
[, $moved] = app('GET', "/v1/app/orders?site_id=$siteId", $token);
$movedOrder = array_values(array_filter($moved['items'], fn ($o) => $o['external_id'] === 'O-1'))[0];
check("l'ack avec status fait avancer la commande", $movedOrder['status'] === 'processing');

echo "\n6. Un product.create venu de l'application\n";
[, $draft] = app('POST', '/v1/app/products', $token, [
    'site_id' => $siteId, 'schema_version' => 1, 'values' => ['nom' => 'Grand boubou', 'prix' => 45000],
]);
$create = null;
foreach ($client->commands()->pending() as $c) {
    if ($c->type === 'product.create') {
        $create = $c;
    }
}
check('il arrive sans externalId', $create !== null && $create->externalId === null);
check(
    'values() et schemaVersion() rendent la saisie',
    $create !== null && ($create->values()['prix'] ?? null) === 45000 && $create->schemaVersion() === 1,
);
$client->commands()->ack($create->id, true, externalId: 'SHOP-1');
[, $list] = app('GET', "/v1/app/products?site_id=$siteId", $token);
$named = array_values(array_filter($list['items'], fn ($i) => $i['id'] === $draft['id']))[0] ?? [];
check("l'ack avec externalId nomme le brouillon", ($named['external_id'] ?? null) === 'SHOP-1');

echo "\n7. Une file vide\n";
$t0 = microtime(true);
$empty = $client->commands()->pending(20, 2);
$held = (int) ((microtime(true) - $t0) * 1000);
check('pending(wait=2) tient la requête et rend []', $empty === [] && $held >= 1500, "$held ms");

echo "\n8. Une mauvaise clé\n";
$stranger = new Client('aja_test_placeholder_wrong_key', $API);
$bad = raised(fn () => $stranger->commands()->pending());
check(
    'lève ApiException SITE_KEY_INVALID',
    $bad instanceof ApiException && $bad->getErrorCode() === ErrorCode::SITE_KEY_INVALID,
    code($bad),
);

echo "\n9. Le marchand reprend son formulaire\n";
$taken = $SCHEMA;
$taken['version'] = 2;
$taken['fields'][0]['label'] = 'Nom (AJA)';
app('PUT', "/v1/app/sites/$siteId/product-schema", $token, ['take_ownership' => true, 'schema' => $taken]);
$locked = raised(fn () => $client->products()->putSchema([...$SCHEMA, 'version' => 3]));
check(
    'putSchema lève ApiException PRODUCT_SCHEMA_LOCKED',
    $locked instanceof ApiException && $locked->getErrorCode() === ErrorCode::PRODUCT_SCHEMA_LOCKED,
    code($locked),
);

echo $failures === 0 ? "\nLe SDK PHP parle correctement à l'API.\n" : "\n$failures vérification(s) en échec.\n";
exit($failures === 0 ? 0 : 1);
