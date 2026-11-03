<?php

namespace App\Domain\Leave\Application\Policy;

/**
 * HR.9 (ADR 0065 §28.4): the CLOSED answer for one employment, one leave
 * type and one date. Structured codes only -- never free text.
 */
enum LeaveEligibilityOutcome: string
{
    /** The employment already has a policy assignment of this type covering the date (a run never touches it). */
    case ASSIGNED = 'assigned';
    /** No assignment; exactly one active version's rules match -- a run assigns it. */
    case ELIGIBLE = 'eligible';
    /** No assignment; rules of more than one active version match -- never resolved by a guess. */
    case AMBIGUOUS = 'ambiguous';
    /** No assignment; no active version's rule matches. */
    case NO_MATCHING_RULE = 'no_matching_rule';
    /** The employment is not planned or current (draft, separated, ended) or its Employee is inactive. */
    case EMPLOYMENT_NOT_ELIGIBLE = 'employment_not_eligible';
    /** Would be eligible, but it is the acting User's own employment: another person assigns it (G-4). */
    case OWN_EMPLOYMENT = 'own_employment';

    public function label(): string
    {
        return match ($this) {
            self::ASSIGNED => 'Assigned',
            self::ELIGIBLE => 'Eligible',
            self::AMBIGUOUS => 'Ambiguous',
            self::NO_MATCHING_RULE => 'No matching rule',
            self::EMPLOYMENT_NOT_ELIGIBLE => 'Employment not eligible',
            self::OWN_EMPLOYMENT => 'Your own employment',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $o) => ['value' => $o->value, 'label' => $o->label()], self::cases());
    }
}
