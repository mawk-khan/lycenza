<?php

namespace Tests\Unit\Support\Money;

use App\Support\Money\Exceptions\InvalidMoneyException;
use App\Support\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 0G.1/0G.2: unit tests for the first-party Money value object
 * committed by ADR 0030 / docs/modules/FINANCE.md. No database
 * involved -- pure representation/validation/arithmetic. 0G.1 covers
 * representation/validation; 0G.2 adds add() (see Money's own class
 * docblock for the exact scope boundary -- still no subtract()/
 * multiply()/divide()).
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

    // 0G.2: add() -- the one arithmetic operation LedgerService needs
    // (see Money's own class docblock for the scope boundary).

    #[Test]
    public function addition_is_exact_for_the_classic_float_unsafe_case(): void
    {
        $sum = Money::of('0.10', 'INR')->add(Money::of('0.20', 'INR'));

        $this->assertSame('0.30', $sum->amount());
        $this->assertTrue($sum->equals(Money::of('0.30', 'INR')));
    }

    #[Test]
    public function addition_uses_the_larger_operands_natural_scale(): void
    {
        $this->assertSame('3', Money::of('1', 'INR')->add(Money::of('2', 'INR'))->amount());
        $this->assertSame('3.5', Money::of('1', 'INR')->add(Money::of('2.5', 'INR'))->amount());
        $this->assertSame('3.75', Money::of('1.5', 'INR')->add(Money::of('2.25', 'INR'))->amount());
    }

    #[Test]
    public function addition_across_currencies_is_rejected(): void
    {
        $this->expectException(InvalidMoneyException::class);

        Money::of('1.00', 'INR')->add(Money::of('1.00', 'USD'));
    }

    #[Test]
    public function addition_handles_a_large_amount_exactly(): void
    {
        $sum = Money::of('999999999999.98', 'INR')->add(Money::of('0.01', 'INR'));

        $this->assertSame('999999999999.99', $sum->amount());
    }

    #[Test]
    public function addition_with_a_negative_operand_behaves_as_subtraction(): void
    {
        $sum = Money::of('10.00', 'INR')->add(Money::of('-3.00', 'INR'));

        $this->assertSame('7.00', $sum->amount());
    }

    #[Test]
    public function addition_returns_a_new_immutable_instance(): void
    {
        $a = Money::of('1.00', 'INR');
        $b = Money::of('2.00', 'INR');

        $sum = $a->add($b);

        $this->assertSame('1.00', $a->amount());
        $this->assertSame('2.00', $b->amount());
        $this->assertSame('3.00', $sum->amount());
    }

    #[Test]
    public function repeated_addition_of_many_small_amounts_stays_exact(): void
    {
        $total = Money::of('0', 'INR');

        for ($i = 0; $i < 10; $i++) {
            $total = $total->add(Money::of('0.10', 'INR'));
        }

        $this->assertTrue($total->equals(Money::of('1.00', 'INR')), "Expected 1.00, got {$total->amount()}");
    }
}
