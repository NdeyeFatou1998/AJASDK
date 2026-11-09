<?php

declare(strict_types=1);

namespace Aja\Dto;

use Aja\Payment;
use InvalidArgumentException;

/** One order as the shop knows it, ready for POST /v1/orders. */
final class OrderInput
{
    /** Wire value, after the shop's own vocabulary has been translated. */
    private readonly string $resolvedPaymentStatus;

    /**
     * @param list<string> $allowedNext
     * @param array{name?:?string,phone?:?string,email?:?string} $customer
     * @param list<array{name:string,quantity:int,unit_price:int,extras?:array<string,mixed>}> $lines
     * @param array<string,mixed> $extras
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $status,
        public readonly array $allowedNext,
        public readonly string $paymentStatus,
        public readonly ?string $paymentMode,
        public readonly int $total,
        public readonly string $currency,
        public readonly array $customer,
        public readonly array $lines,
        public readonly array $extras = [],
    ) {
        if ($externalId === '') {
            throw new InvalidArgumentException('externalId must not be empty');
        }
        if ($lines === []) {
            throw new InvalidArgumentException('an order needs at least one line');
        }
        if ($total < 0) {
            throw new InvalidArgumentException('total must not be negative');
        }
        $name = trim((string) ($customer['name'] ?? ''));
        $phone = trim((string) ($customer['phone'] ?? ''));
        if ($name === '' && $phone === '') {
            throw new InvalidArgumentException('customer needs a name or a phone');
        }
        $resolved = Payment::resolve($paymentStatus);
        if ($resolved === null) {
            throw new InvalidArgumentException(sprintf(
                'paymentStatus "%s" is not recognised. It must end up as one of %s. '
                . 'Either pass one of those, or teach the SDK your vocabulary once '
                . 'at start-up: Payment::registerAliases(["%s" => "pending"]).',
                $paymentStatus,
                implode('|', Payment::STATUSES),
                $paymentStatus,
            ));
        }
        $this->resolvedPaymentStatus = $resolved;
    }

    /**
     * snake_case body expected by the HTTP contract.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'external_id' => $this->externalId,
            'status' => $this->status,
            'allowed_next' => array_values($this->allowedNext),
            'payment_status' => $this->resolvedPaymentStatus,
            'payment_mode' => $this->paymentMode,
            'total' => $this->total,
            'currency' => $this->currency,
            'customer' => $this->customer,
            'lines' => array_map(
                static fn (array $line): array => array_key_exists('extras', $line)
                    ? ['extras' => (object) $line['extras']] + $line
                    : $line,
                array_values($this->lines),
            ),
            'extras' => (object) $this->extras,
        ];
    }
}
