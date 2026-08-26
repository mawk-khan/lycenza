<?php

namespace Tests\Unit\Support\Money;

use App\Support\Money\Exceptions\InvalidMoneyException;
use App\Support\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 0G.1: unit tests for the first-party Money value object committed by
 * ADR 0030 / docs/modules/FINANCE.md. No database involved -- pure
 * representation/validation, per Money's own documented 0G.1 scope
 * boundary (arithmetic is 0G.2's concern).
 */
class MoneyTest extends TestCase
{
    #[Test]
    #[DataProvider('validAmounts')]
    public function it_accepts_valid_decimal_amounts(string $amount): void
    {
        $money = Money::of($amount, 'INR');

        $this->assertSame($amount, $money->amount());
    }

    public static function validAmounts(): array
    {
        return [
            'small fraction' => ['0.01'],
            'whole number' => ['1'],
            'trailing zero fraction' => ['1.0'],
            'zero' => ['0'],
            'zero with fraction' => ['0.00'],
            'large amount' => ['999999999999.99'],
            'many fractional digits' => ['1.123456789012345'],
            'negative amount' => ['-5.00'],
        ];
    }

    #[Test]
    #[DataProvider('invalidAmounts')]
    public function it_rejects_invalid_decimal_amounts(string $amount): void
    {
        $this->expectException(InvalidMoneyException::class);

        Money::of($amount, 'INR');
    }

    public static function invalidAmounts(): array
    {
        return [
            'scientific notation' => ['1e10'],
            'scientific notation uppercase' => ['1E10'],
            'leading zero' => ['01'],
            'leading plus' => ['+1'],
            'leading whitespace' => [' 1'],
            'trailing whitespace' => ['1 '],
            'empty string' => [''],
            'comma separator' => ['1,000'],
            'trailing decimal point' => ['1.'],
            'not a number' => ['abc'],
            'infinity' => ['INF'],
            'nan' => ['NAN'],
        ];
    }

    #[Test]
    public function float_input_is_rejected_even_from_a_caller_without_strict_types(): void
    {
        // This test file deliberately has NO `declare(strict_types=1)` --
        // proving Money::of() rejects a float regardless of the
        // CALLER's strict_types setting (see Money::of()'s docblock
        // for why that, not TypeError, is the real guarantee).
        $this->expectException(InvalidMoneyException::class);

        /** @phpstan-ignore-next-line intentionally passing a float to prove it is rejected */
        Money::of(1.5, 'INR');
    }

    #[Test]
    public function it_rejects_a_lowercase_currency_code(): void
    {
        $this->expectException(InvalidMoneyException::class);

        Money::of('1.00', 'inr');
    }

    #[Test]
    public function it_rejects_a_currency_code_of_the_wrong_length(): void
    {
        $this->expectException(InvalidMoneyException::class);

        Money::of('1.00', 'INRR');
    }

    #[Test]
    public function equal_amounts_in_different_string_forms_are_equal(): void
    {
        $this->assertTrue(Money::of('1', 'INR')->equals(Money::of('1.0', 'INR')));
        $this->assertTrue(Money::of('0', 'INR')->equals(Money::of('0.00', 'INR')));
    }

    #[Test]
    public function different_amounts_are_not_equal(): void
    {
        $this->assertFalse(Money::of('1.00', 'INR')->equals(Money::of('1.01', 'INR')));
    }

    #[Test]
    public function the_same_amount_in_a_different_currency_is_not_equal(): void
    {
        $this->assertFalse(Money::of('1.00', 'INR')->equals(Money::of('1.00', 'USD')));
    }

    #[Test]
    public function zero_is_neither_positive_nor_negative(): void
    {
        $money = Money::of('0.00', 'INR');

        $this->assertTrue($money->isZero());
        $this->assertFalse($money->isPositive());
        $this->assertFalse($money->isNegative());
    }

    #[Test]
    public function a_positive_amount_reports_its_sign_correctly(): void
    {
        $money = Money::of('5.00', 'INR');

        $this->assertTrue($money->isPositive());
        $this->assertFalse($money->isNegative());
        $this->assertFalse($money->isZero());
    }

    #[Test]
    public function a_negative_amount_reports_its_sign_correctly(): void
    {
        $money = Money::of('-5.00', 'INR');

        $this->assertTrue($money->isNegative());
        $this->assertFalse($money->isPositive());
        $this->assertFalse($money->isZero());
    }

    #[Test]
    public function negated_flips_the_sign_without_changing_magnitude_or_currency(): void
    {
        $money = Money::of('5.00', 'INR');
        $negated = $money->negated();

        $this->assertSame('-5.00', $negated->amount());
        $this->assertSame('INR', $negated->currency());
        $this->assertTrue($money->equals($negated->negated()));
    }

    #[Test]
    public function negating_zero_returns_zero(): void
    {
        $money = Money::of('0.00', 'INR');

        $this->assertSame('0.00', $money->negated()->amount());
    }

    #[Test]
    public function json_serialization_preserves_the_decimal_string_exactly(): void
    {
        $money = Money::of('1.10', 'INR');

        $encoded = json_encode($money);

        $this->assertSame('{"amount":"1.10","currency":"INR"}', $encoded);
    }

    #[Test]
    public function string_conversion_is_human_readable(): void
    {
        $this->assertSame('1.10 INR', (string) Money::of('1.10', 'INR'));
    }
}
