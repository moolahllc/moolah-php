<?php

declare(strict_types=1);

namespace Moolah\Poynt\Resource;

use Moolah\Poynt\Enum\CardOnFileIntent;
use Moolah\Poynt\Enum\FundingSourceType;
use Moolah\Poynt\Model\Surcharge;

/** @internal */
final class Support
{
    public const DEFAULT_CURRENCY = 'USD';

    /**
     * Drop nulls so unset fields are omitted (an explicit mit: false is kept).
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    public static function compact(array $a): array
    {
        return \array_filter($a, static fn ($v) => $v !== null);
    }

    public static function cents(string $name, ?int $value, bool $required): void
    {
        if ($value === null) {
            if ($required) {
                throw new \InvalidArgumentException("$name is required (integer cents)");
            }
            return;
        }
        if ($value < 0) {
            throw new \InvalidArgumentException("$name must be a non-negative integer number of cents, got $value");
        }
    }

    public static function id(string $name, ?string $value): string
    {
        if ($value === null || \trim($value) === '') {
            throw new \InvalidArgumentException("$name is required");
        }
        return $value;
    }

    public static function seg(string $value): string
    {
        return \rawurlencode($value);
    }

    public static function hasText(?string $v): bool
    {
        return $v !== null && \trim($v) !== '';
    }

    /**
     * @param Surcharge|array<string, mixed>|null $surcharge
     * @return array<string, mixed>|null
     */
    public static function surcharge(Surcharge|array|null $surcharge): ?array
    {
        if ($surcharge instanceof Surcharge) {
            return Support::compact($surcharge->toArray());
        }
        return $surcharge;
    }

    /**
     * @param Surcharge|array<string, mixed>|null $surcharge
     * @return array<string, mixed>
     */
    public static function chargeBody(
        ?int $amount,
        ?string $paymentToken,
        FundingSourceType|string|null $fundingSourceType,
        ?string $currency,
        CardOnFileIntent|string|null $intent,
        ?bool $mit,
        Surcharge|array|null $surcharge,
        ?string $transactionNumber,
        ?string $email,
        ?string $notes,
        ?bool $partialAuthEnabled,
        ?string $cardToken,
    ): array {
        self::cents('amount', $amount, true);
        if (self::hasText($paymentToken) && self::hasText($cardToken)) {
            throw new \InvalidArgumentException('send paymentToken or cardToken, not both');
        }
        if (!self::hasText($paymentToken) && !self::hasText($cardToken)) {
            throw new \InvalidArgumentException('paymentToken (with fundingSourceType) is required');
        }
        // A paymentToken without fundingSourceType is treated by the API as ACH (legacy
        // behaviour). Never let a card token fall into that by omission.
        if (self::hasText($paymentToken) && ($fundingSourceType === null || $fundingSourceType === '')) {
            throw new \InvalidArgumentException('fundingSourceType is required with paymentToken (CARD or CHEQUE)');
        }
        return self::compact([
            'amount' => $amount,
            'currency' => $currency ?? self::DEFAULT_CURRENCY,
            'paymentToken' => $paymentToken,
            'fundingSourceType' => $fundingSourceType instanceof FundingSourceType ? $fundingSourceType->value : $fundingSourceType,
            'cardToken' => $cardToken,
            'email' => $email,
            'notes' => $notes,
            'partialAuthEnabled' => $partialAuthEnabled,
            'transactionNumber' => $transactionNumber,
            'surcharge' => self::surcharge($surcharge),
            'intent' => $intent instanceof CardOnFileIntent ? $intent->value : $intent,
            'mit' => $mit,
        ]);
    }

    /** @return array<string, mixed> */
    public static function obj(mixed $decoded): array
    {
        return \is_array($decoded) ? $decoded : [];
    }
}
