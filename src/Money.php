<?php

declare(strict_types=1);

namespace Aja;

use InvalidArgumentException;

/** Amounts as AJA carries them: integers in the smallest unit of their currency, per ISO 4217. */
final class Money
{
    /** Currencies with no minor unit in use (exponent 0). */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG',
        'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /** Currencies with three decimals (exponent 3). */
    private const THREE_DECIMAL = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    /** Decimals of a currency's minor unit: 0, 2 (the usual case) or 3. */
    public static function exponent(string $currency): int
    {
        $code = strtoupper(trim($currency));
        if (in_array($code, self::ZERO_DECIMAL, true)) {
            return 0;
        }
        if (in_array($code, self::THREE_DECIMAL, true)) {
            return 3;
        }

        return 2;
    }

    /**
     * A decimal amount — "12.50", "15000", 12.5 — as the integer AJA expects.
     *
     * @throws InvalidArgumentException
     */
    public static function toMinor(int|float|string|null $amount, string $currency): ?int
    {
        if ($amount === null) {
            return null;
        }
        if (is_float($amount)) {
            if (!is_finite($amount)) {
                throw new InvalidArgumentException('amount must be a finite number');
            }
            $text = sprintf('%.10F', $amount);
        } else {
            $text = trim((string) $amount);
        }
        if ($text === '') {
            return null;
        }

        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $text, $parts) !== 1
            || ($parts[2] === '' && ($parts[3] ?? '') === '')
        ) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a decimal amount: write it like "12.50", with a dot and no thousands separator',
                $text,
            ));
        }

        $exponent = self::exponent($currency);
        $fraction = $parts[3] ?? '';
        $digits = $parts[2] . substr(str_pad($fraction, $exponent, '0'), 0, $exponent);
        if (strlen($fraction) > $exponent && $fraction[$exponent] >= '5') {
            $digits = self::increment($digits);
        }

        return self::toInt($parts[1] === '-', $digits);
    }

    /**
     * AJA's integer back to the decimal string a shop stores, with exactly as many decimals as the
     * currency has: 1250 EUR is "12.50", 15000 XOF is "15000", 1250 KWD is "1.250".
     *
     * @throws InvalidArgumentException
     */
    public static function fromMinor(int|float|string|null $minor, string $currency): string
    {
        if ($minor === null) {
            return '';
        }
        if (is_int($minor)) {
            $text = (string) $minor;
        } elseif (is_float($minor)) {
            if (!is_finite($minor) || floor($minor) !== $minor || abs($minor) > PHP_INT_MAX) {
                throw new InvalidArgumentException(sprintf(
                    '%s is not a whole number of minor units',
                    var_export($minor, true),
                ));
            }
            $text = sprintf('%.0F', $minor);
        } else {
            $text = trim($minor);
            if ($text === '') {
                return '';
            }
        }

        if (preg_match('/^([+-]?)(\d+)$/', $text, $parts) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not a whole number of minor units', $text));
        }

        $digits = ltrim($parts[2], '0');
        $negative = $parts[1] === '-' && $digits !== '';
        $digits = $digits === '' ? '0' : $digits;
        $exponent = self::exponent($currency);
        if ($exponent > 0) {
            $digits = str_pad($digits, $exponent + 1, '0', STR_PAD_LEFT);
            $digits = substr($digits, 0, -$exponent) . '.' . substr($digits, -$exponent);
        }

        return ($negative ? '-' : '') . $digits;
    }

    /** "1249" → "1250", "999" → "1000", "" → "1": one more, on the digits themselves. */
    private static function increment(string $digits): string
    {
        $i = strlen($digits) - 1;
        while ($i >= 0 && $digits[$i] === '9') {
            $digits[$i] = '0';
            $i--;
        }
        if ($i < 0) {
            return '1' . $digits;
        }
        $digits[$i] = (string) ((int) $digits[$i] + 1);

        return $digits;
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function toInt(bool $negative, string $digits): int
    {
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return 0;
        }
        $max = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new InvalidArgumentException('amount is too large for an integer');
        }
        $value = (int) $digits;

        return $negative ? -$value : $value;
    }
}
