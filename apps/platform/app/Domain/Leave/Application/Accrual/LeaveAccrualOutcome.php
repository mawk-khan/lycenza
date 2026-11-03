<?php

namespace App\Domain\Leave\Application\Accrual;

/**
 * HR.10 (ADR 0065 §29.5): the CLOSED, DISJOINT answer for one employment in
 * one accrual month. Exactly one per examined employment, so a run
 * reconciles: examined = the sum of every outcome's count. Mirrors
 * `leave_accrual_run_items_shape_check`.
 */
enum LeaveAccrualOutcome: string
{
    /** Credited by this run. */
    case CREDITED = 'credited';
    /** The month was already credited (an earlier run or attempt): never twice. */
    case ALREADY_CREDITED = 'already_credited';
    /** The version's timing makes the month postable only later. */
    case NOT_DUE = 'not_due';
    /** The employment's dates cover only part of the month (joining or leaving in it): never prorated (ADR 0065 §22.2). */
    case PARTIAL_PERIOD = 'partial_period';
    /** No policy of the type is assigned in the month. */
    case NO_POLICY = 'no_policy';
    /** The assignment does not cover the whole month, or the version changes inside it. */
    case ASSIGNMENT_NOT_WHOLE_PERIOD = 'assignment_not_whole_period';
    /** The assigned version is funded by the annual allocation. */
    case NOT_MONTHLY_POLICY = 'not_monthly_policy';
    /** The account already holds an annual allocation for the year (funding is exclusive). */
    case ANNUAL_ALLOCATION_EXISTS = 'annual_allocation_exists';

    public function label(): string
    {
        return match ($this) {
            self::CREDITED => 'Credited',
            self::ALREADY_CREDITED => 'Already credited',
            self::NOT_DUE => 'Not yet due',
            self::PARTIAL_PERIOD => 'Part of the month only (not prorated)',
            self::NO_POLICY => 'No policy assigned',
            self::ASSIGNMENT_NOT_WHOLE_PERIOD => 'Policy assignment does not cover the whole month',
            self::NOT_MONTHLY_POLICY => 'Policy is annual',
            self::ANNUAL_ALLOCATION_EXISTS => 'Annual allocation already funds the year',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $o) => ['value' => $o->value, 'label' => $o->label()], self::cases());
    }
}
