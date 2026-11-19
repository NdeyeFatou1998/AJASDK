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
 * Les sections 0 (les montants) et 0 bis (la preuve de propriété, contre un
 * transport factice) ne demandent aucun réseau et tournent en premier : sans
 * API, le scénario s'arrête juste après elles, en le disant.
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
use Aja\Exception\ValidationException;
use Aja\Http\HttpClient;
use Aja\Http\HttpResponse;
use Aja\Money;

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

/**
 * L'admin du site : depuis le 7 novembre au soir, c'est lui qui fait le
 * commerce, pas l'admin du compte. Son invitation ne part que par e-mail :
 * l'outil du banc la pose en base et l'accepte par l'API, comme depuis le lien
 * (AJABACKEND/src/tools/site-admin.ts ; AJA_BACKEND_DIR pour un autre dossier).
 */
function site_admin_token(string $siteId): string
{
    global $API;
    $backend = getenv('AJA_BACKEND_DIR') ?: dirname(__DIR__, 2) . '/AJABACKEND';
    $command = sprintf(
        'cd %s && AJA_API=%s npm run -s sync:site-admin -- %s',
        escapeshellarg($backend),
        escapeshellarg($API),
        escapeshellarg($siteId),
    );
    $lines = preg_split('/\R/', trim((string) shell_exec($command))) ?: [];
    $last = json_decode((string) end($lines), true);

    return is_array($last) ? (string) ($last['access_token'] ?? '') : '';
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

/** La valeur rendue, ou l'exception levée : un cas qui plante reste un cas, pas une erreur fatale. */
function outcome(callable $fn): mixed
{
    try {
        return $fn();
    } catch (Throwable $e) {
        return $e;
    }
}

function code(?Throwable $e): string
{
    return $e instanceof ApiException ? $e->getErrorCode() : ($e === null ? '' : get_class($e) . ': ' . $e->getMessage());
}

/** Un transport factice : il note chaque appel et rend la réponse suivante. */
final class Wire implements HttpClient
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $calls = [];

    /** @param list<HttpResponse> $replies */
    public function __construct(private array $replies = [])
    {
    }

    public function request(string $method, string $url, array $headers, ?string $body = null, ?int $timeoutSeconds = null): HttpResponse
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        return array_shift($this->replies) ?? new HttpResponse(500, '{}');
    }
}

$SCHEMA = [
    'version' => 1,
    'fields' => [
        ['key' => 'nom', 'label' => 'Nom', 'type' => 'text', 'required' => true],
        ['key' => 'prix', 'label' => 'Prix', 'type' => 'money', 'required' => true],
    ],
];

echo "0. Money — les montants, sans réseau\n";
// Revue du 4 novembre : le connecteur WooCommerce comptait les montants avec
// le nombre de décimales que la boutique affiche, quand l'application, le web
// et les notifications divisent par l'exposant ISO 4217. Chaque exemple du
// contrat (§1) est un cas ici, avec le même libellé dans les trois SDK.
$toMinor = [
    ['« 12.5 » EUR → 1250', '12.5', 'EUR', 1250],
    ['« 12.505 » EUR → 1251, le demi arrondi au-dessus', '12.505', 'EUR', 1251],
    ['« 15000 » XOF → 15000', '15000', 'XOF', 15000],
    ['« 15000.00 » XOF → 15000', '15000.00', 'XOF', 15000],
    ['« 1.2345 » KWD → 1235', '1.2345', 'KWD', 1235],
    ['« -3.10 » EUR → -310', '-3.10', 'EUR', -310],
    ['« » (vide) → pas de montant, jamais 0', '', 'EUR', null],
    // Hors des exemples du contrat : un nombre suit le chemin de sa chaîne, et
    // le demi d'un montant négatif s'éloigne de zéro, comme round() de PHP.
    // Un choix à garder identique dans les trois SDK.
    ['le nombre 12.5 EUR → 1250, comme la chaîne', 12.5, 'EUR', 1250],
    ['le nombre 1.005 EUR → 101 : jamais de flottant multiplié', 1.005, 'EUR', 101],
    ['le nombre entier 15000 XOF → 15000', 15000, 'XOF', 15000],
    ['« -3.105 » EUR → -311, le demi s\'éloigne de zéro', '-3.105', 'EUR', -311],
];
foreach ($toMinor as [$label, $amount, $currency, $want]) {
    $got = outcome(fn () => Money::toMinor($amount, $currency));
    check($label, $got === $want, $got instanceof Throwable ? code($got) : var_export($got, true));
}
$comma = raised(fn () => Money::toMinor('12,50', 'EUR'));
check(
    '« 12,50 » est refusé : la virgule ne dit pas si elle sépare les milliers',
    $comma instanceof InvalidArgumentException,
    code($comma),
);
$fromMinor = [
    ['1250 EUR → « 12.50 »', 1250, 'EUR', '12.50'],
    ['15000 XOF → « 15000 »', 15000, 'XOF', '15000'],
    ['1250 KWD → « 1.250 »', 1250, 'KWD', '1.250'],
    ['-310 EUR → « -3.10 »', -310, 'EUR', '-3.10'],
    ['pas de montant (null) → « »', null, 'EUR', ''],
];
foreach ($fromMinor as [$label, $minor, $currency, $want]) {
    $got = outcome(fn () => Money::fromMinor($minor, $currency));
    check($label, $got === $want, $got instanceof Throwable ? code($got) : var_export($got, true));
}
$exponents = outcome(fn () => array_map(Money::exponent(...), ['XOF', 'eur', 'KWD', 'ZZZ']));
check(
    'la table des exposants : XOF 0, eur 2, KWD 3, une devise inconnue 2',
    $exponents === [0, 2, 3, 2],
    $exponents instanceof Throwable ? code($exponents) : (string) json_encode($exponents),
);

// Préparation du lancement : la clé d'API voyage dans chaque requête — jamais
// en clair vers un serveur public. Mêmes libellés dans les trois SDK.
$public = outcome(fn () => new Client('aja_test_conformance', 'http://api.ajaflow.com'));
check("une adresse d'API en http vers un serveur public est refusée", $public instanceof InvalidArgumentException, $public instanceof Throwable ? get_class($public) : 'acceptée');
$local = outcome(fn () => new Client('aja_test_conformance', 'http://localhost:8080'));
check("en http vers la machine locale (banc d'essai), acceptée", $local instanceof Client, $local instanceof Throwable ? $local->getMessage() : 'acceptée');

echo "\n0 bis. site()->handleWake — la preuve de propriété, sans réseau\n";
// Fraude au domaine : avant de passer un site en production, AJA appelle son
// adresse de réveil avec aja_verify=<code>, et la boutique lui renvoie le code
// avec sa clé. Une adresse publique reçoit n'importe quoi : rien ne part sans
// un code bien formé. Mêmes libellés dans les trois SDK.
$CODE = str_repeat('aB3_-', 8);
$wire = new Wire();
$offline = new Client('aja_test_conformance', 'http://aja.test', $wire, 3, 0);
$quiet = outcome(fn () => [$offline->site()->handleWake([]), $offline->site()->handleWake(['doing_wp_cron' => ''])]);
check('sans aja_verify, handleWake rend null sans appeler AJA', $quiet === [null, null] && $wire->calls === [], $quiet instanceof Throwable ? code($quiet) : count($wire->calls) . ' appel(s)');
$malformed = [str_repeat('a', 31), str_repeat('a', 129), str_repeat('a', 31) . '/', $CODE . "\n", [$CODE, $CODE]];
$ignored = outcome(fn () => array_map(fn ($value) => $offline->site()->handleWake(['aja_verify' => $value]), $malformed));
check(
    'un code mal formé — trop court, trop long, hors alphabet, saut de ligne final, en double — rend null sans appeler AJA',
    $ignored === [null, null, null, null, null] && $wire->calls === [],
    $ignored instanceof Throwable ? code($ignored) : (string) json_encode($ignored) . ', ' . count($wire->calls) . ' appel(s)',
);
$wire = new Wire([new HttpResponse(200, '{"verified":true,"domain":"shop.example"}')]);
$offline = new Client('aja_test_conformance', 'http://aja.test', $wire, 3, 0);
$proven = outcome(fn () => $offline->site()->handleWake(['doing_wp_cron' => '', 'aja_verify' => $CODE]));
$sent = $wire->calls[0] ?? ['method' => '', 'url' => '', 'headers' => [], 'body' => null];
check(
    'un code bien formé part en POST /v1/site-verification, avec la clé et {nonce}',
    count($wire->calls) === 1 && $sent['method'] === 'POST' && $sent['url'] === 'http://aja.test/v1/site-verification'
        && ($sent['headers']['Authorization'] ?? '') === 'Bearer aja_test_conformance'
        && json_decode((string) $sent['body'], true) === ['nonce' => $CODE],
    $sent['method'] . ' ' . $sent['url'] . ' ' . $sent['body'],
);
check(
    'AJA confirme : handleWake rend verified true et le domaine',
    $proven === ['verified' => true, 'domain' => 'shop.example'],
    $proven instanceof Throwable ? code($proven) : (string) json_encode($proven),
);
$failed = '{"error":{"code":"VERIFICATION_FAILED","message":"unknown or expired code"}}';
$wire = new Wire([new HttpResponse(422, $failed), new HttpResponse(422, $failed)]);
$offline = new Client('aja_test_conformance', 'http://aja.test', $wire, 3, 0);
$refused = outcome(fn () => $offline->site()->handleWake(['aja_verify' => $CODE]));
check(
    'AJA refuse le code (VERIFICATION_FAILED) : handleWake rend verified false, sans nouvel essai',
    $refused === ['verified' => false] && count($wire->calls) === 1,
    ($refused instanceof Throwable ? code($refused) : (string) json_encode($refused)) . ', ' . count($wire->calls) . ' appel(s)',
);
$wire = new Wire([new HttpResponse(422, $failed), new HttpResponse(422, $failed)]);
$offline = new Client('aja_test_conformance', 'http://aja.test', $wire, 3, 0);
$verificationFailed = raised(fn () => $offline->site()->confirmVerification($CODE));
check(
    'confirmVerification lève ApiException VERIFICATION_FAILED, jamais réessayé',
    $verificationFailed instanceof ApiException && $verificationFailed->getErrorCode() === ErrorCode::VERIFICATION_FAILED
        && $verificationFailed->getHttpStatus() === 422 && count($wire->calls) === 1 && !ErrorCode::isRetryable(ErrorCode::VERIFICATION_FAILED),
    code($verificationFailed) . ', ' . count($wire->calls) . ' appel(s)',
);
$waiting = [
    ErrorCode::SITE_NOT_VERIFIED,
    ErrorCode::SITE_IP_NOT_CONFIRMED,
    ErrorCode::COMMAND_UNSUPPORTED_TYPE,
    ErrorCode::ORDER_TRANSITION_NOT_ALLOWED,
];
$retried = array_values(array_filter($waiting, fn (string $c) => ErrorCode::isRetryable($c)));
check(
    'les refus qui attendent le marchand ou le site (pas prouvé, adresse IP non confirmée, type inconnu, transition interdite) ne se réessaient jamais',
    $retried === [],
    $retried === [] ? 'aucun réessayé' : implode(', ', $retried),
);
$wire = new Wire([new HttpResponse(401, '{"error":{"code":"SITE_KEY_INVALID","message":"invalid key"}}')]);
$offline = new Client('aja_test_conformance', 'http://aja.test', $wire, 3, 0);
$badKey = raised(fn () => $offline->site()->handleWake(['aja_verify' => $CODE]));
check(
    'une autre erreur (SITE_KEY_INVALID) lève, comme pour tout appel',
    $badKey instanceof ApiException && $badKey->getErrorCode() === ErrorCode::SITE_KEY_INVALID,
    code($badKey),
);
$wire = new Wire();
$offline = new Client('aja_test_conformance', 'http://aja.test', $wire, 3, 0);
$early = raised(fn () => $offline->site()->confirmVerification('trop-court'));
check('confirmVerification refuse un code mal formé avant tout appel', $early instanceof InvalidArgumentException && $wire->calls === [], code($early));

// Tout ce qui suit parle à l'API. Sans elle, on s'arrête ici en le disant,
// plutôt que sur une erreur fatale à la première réponse vide.
[$health] = app('GET', '/health');
if ($health !== 200) {
    echo "\nL'API ne répond pas sur $API : seules les vérifications sans réseau ont tourné.\n";
    echo $failures === 0 ? '' : "$failures vérification(s) en échec.\n";
    exit(1);
}

echo "\n1. Le marchand ouvre une boutique\n";
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
$ownerToken = $signup['access_token'];
[$status, $site] = app('POST', '/v1/app/sites', $ownerToken, ['name' => 'SDK', 'platform' => 'custom', 'currency' => 'XOF']);
check('boutique créée', $status === 201, "HTTP $status");
$siteId = $site['id'];
// Le commerce de la boutique : son admin, invité par l'admin du compte.
$token = site_admin_token($siteId);
check("l'admin du site rejoint la boutique", $token !== '');
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

echo "\n10. site()->putProfile — la boutique dit sa devise et son fuseau\n";
// Revue du 4 novembre : la boutique de la section 1 est créée en XOF, comme
// toutes celles du tableau de bord. C'est elle qui corrige ensuite.
$profile = outcome(fn () => $client->site()->putProfile(currency: 'eur', timezone: 'Europe/Paris'));
check(
    'la devise et le fuseau déclarés sont ceux enregistrés',
    is_array($profile) && ($profile['currency'] ?? null) === 'EUR' && ($profile['timezone'] ?? null) === 'Europe/Paris',
    $profile instanceof Throwable ? code($profile) : (string) json_encode($profile, JSON_UNESCAPED_SLASHES),
);
[, $catalogue] = app('GET', "/v1/app/products?site_id=$siteId", $token);
check(
    "l'application lit le catalogue dans la devise déclarée",
    ($catalogue['currency'] ?? null) === 'EUR',
    (string) ($catalogue['currency'] ?? ''),
);
$repeated = outcome(fn () => $client->site()->putProfile(currency: 'EUR', timezone: 'Europe/Paris'));
check("la redéclarer à l'identique rend la même chose", $repeated === $profile);
$badZone = raised(fn () => $client->site()->putProfile(timezone: 'Mars/Olympus_Mons'));
check(
    'un fuseau inconnu lève ValidationException, qui nomme timezone',
    $badZone instanceof ValidationException && array_key_exists('timezone', $badZone->fields()),
    $badZone instanceof ApiException ? (string) json_encode($badZone->getDetails()) : code($badZone),
);
$nothing = raised(fn () => $client->site()->putProfile());
check('sans rien à déclarer, le SDK refuse avant tout appel', $nothing instanceof InvalidArgumentException, code($nothing));

echo "\n11. site()->handleWake — la preuve de propriété\n";
// Seul le refus se rejoue ici : un vrai code part d'AJA vers l'adresse de
// réveil publique du site, que ce banc n'a pas.
$unknown = bin2hex(random_bytes(20));
$stray = outcome(fn () => $client->site()->handleWake(['aja_verify' => $unknown]));
check(
    "un code qu'AJA n'a pas émis : handleWake rend verified false",
    $stray === ['verified' => false],
    $stray instanceof Throwable ? code($stray) : (string) json_encode($stray),
);
$strayRaised = raised(fn () => $client->site()->confirmVerification($unknown));
check(
    'confirmVerification lève ApiException VERIFICATION_FAILED (HTTP 422)',
    $strayRaised instanceof ApiException && $strayRaised->getErrorCode() === ErrorCode::VERIFICATION_FAILED && $strayRaised->getHttpStatus() === 422,
    code($strayRaised),
);

echo $failures === 0 ? "\nLe SDK PHP parle correctement à l'API.\n" : "\n$failures vérification(s) en échec.\n";
exit($failures === 0 ? 0 : 1);
