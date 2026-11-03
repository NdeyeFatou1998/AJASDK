<?php

declare(strict_types=1);

namespace Aja;

use InvalidArgumentException;

/**
 * Payment vocabulary translation.
 *
 * The wire format is frozen at four values, but shops name things their own
 * way — often in French. Rather than force every integrator to hand-write a
 * mapping, the SDK ships a table of common synonyms and lets a shop extend it
 * once at start-up with registerAliases().
 *
 * This table is generated from the Node SDK's source so the three clients
 * never drift apart.
 */
final class Payment
{
    /** The only payment states the API accepts. */
    public const STATUSES = ['pending', 'paid', 'failed', 'refunded'];

    /**
     * Synonyms the SDK resolves on its own.
     *
     * Two entries are judgement calls rather than translations, and both lean
     * the safe way:
     *   - partially_paid -> pending: money is not fully in, so do not tell the
     *     merchant the order is paid and shippable.
     *   - partially_refunded -> paid: the order *was* paid. Calling it refunded
     *     would drop it out of the merchant's "paid only" statistics entirely.
     *
     * @var array<string,string>
     */
    private const BUILT_IN = [
        // --- pending ---
        'pending' => 'pending',
        'unpaid' => 'pending',
        'not_paid' => 'pending',
        'awaiting_payment' => 'pending',
        'pending_payment' => 'pending',
        'payment_pending' => 'pending',
        'on_delivery' => 'pending',
        'cash_on_delivery' => 'pending',
        'cod' => 'pending',
        'partially_paid' => 'pending',
        'partial_payment' => 'pending',
        'deposit' => 'pending',
        'en_attente' => 'pending',
        'en_attente_de_paiement' => 'pending',
        'paiement_en_attente' => 'pending',
        'impaye' => 'pending',
        'non_paye' => 'pending',
        'a_payer' => 'pending',
        'contre_remboursement' => 'pending',
        'paiement_a_la_livraison' => 'pending',
        'partiellement_paye' => 'pending',
        'paiement_partiel' => 'pending',
        'acompte' => 'pending',
        // --- paid ---
        'paid' => 'paid',
        'captured' => 'paid',
        'completed' => 'paid',
        'complete' => 'paid',
        'settled' => 'paid',
        'success' => 'paid',
        'successful' => 'paid',
        'partially_refunded' => 'paid',
        'partial_refund' => 'paid',
        'paye' => 'paid',
        'regle' => 'paid',
        'encaisse' => 'paid',
        'paiement_recu' => 'paid',
        'paiement_valide' => 'paid',
        'partiellement_rembourse' => 'paid',
        'remboursement_partiel' => 'paid',
        // --- failed ---
        'failed' => 'failed',
        'declined' => 'failed',
        'rejected' => 'failed',
        'denied' => 'failed',
        'error' => 'failed',
        'expired' => 'failed',
        'echoue' => 'failed',
        'refuse' => 'failed',
        'rejete' => 'failed',
        'erreur' => 'failed',
        'expire' => 'failed',
        // --- refunded ---
        'refunded' => 'refunded',
        'chargeback' => 'refunded',
        'reversed' => 'refunded',
        'rembourse' => 'refunded',
        'remboursement' => 'refunded',
        'avoir' => 'refunded',
    ];

    /** @var array<string,string> */
    private static array $custom = [];

    /**
     * Fold a label into a comparable key: lowercase, accents removed, any
     * separator collapsed to "_". So "Partiellement Payé",
     * "partiellement-paye" and "PARTIALLY_PAID" all reach the same entry.
     */
    public static function foldLabel(string $value): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii === false) {
            // iconv is not guaranteed on every shared host; fall back to the
            // accents a French or English shop actually uses.
            $ascii = strtr($value, [
                'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a',
                'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
                'î' => 'i', 'ï' => 'i', 'í' => 'i',
                'ô' => 'o', 'ö' => 'o', 'ó' => 'o',
                'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u',
                'ç' => 'c', 'ñ' => 'n',
            ]);
        }
        $ascii = preg_replace("/['\x60^~\"]/", '', $ascii) ?? $ascii;
        $key = strtolower(trim($ascii));
        $key = preg_replace('/[\s.\/-]+/', '_', $key) ?? $key;

        return preg_replace('/_+/', '_', $key) ?? $key;
    }

    /**
     * Teach the SDK your own payment vocabulary, once, at start-up.
     *
     * Keys are matched loosely (case, accents and separators are ignored).
     * Registering a key that already exists overrides the built-in meaning,
     * which is how a shop disagrees with the defaults above.
     *
     * @param array<string,string> $aliases
     */
    public static function registerAliases(array $aliases): void
    {
        foreach ($aliases as $label => $target) {
            if (!in_array($target, self::STATUSES, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Payment::registerAliases: "%s" points at "%s", which is not one of %s.',
                    $label,
                    $target,
                    implode('|', self::STATUSES),
                ));
            }
            self::$custom[self::foldLabel((string) $label)] = $target;
        }
    }

    /** Forget every registered alias. Mostly useful in tests. */
    public static function clearAliases(): void
    {
        self::$custom = [];
    }

    /**
     * Translate a shop's own label into one of the four API values.
     * Returns null when nothing matches, so the caller decides how loudly to fail.
     */
    public static function resolve(string $value): ?string
    {
        $key = self::foldLabel($value);

        return self::$custom[$key] ?? self::BUILT_IN[$key] ?? null;
    }
}
