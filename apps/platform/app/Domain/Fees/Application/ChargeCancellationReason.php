<?php

namespace App\Domain\Fees\Application;

use App\Domain\Fees\Application\Exceptions\ChargeCancellationReasonException;

/**
 * ORG.8G (ADR 0076 §65, owner decision OD-8.3): the closed, code-owned reasons
 * a person gives for ending a Charge. Every one says the Charge should not
 * stand -- an error or a correction. None is a waiver: a valid Charge the
 * School chooses not to collect goes through a fee concession (maker/checker),
 * never through cancellation. `other` needs the bounded note. Mirrored by
 * `charges_cancellation_reason_code_check`.
 */
final class ChargeCancellationReason
{
    public const CANCEL = 'charge_cancelled';

    public const ASSESSMENT_VOID = 'fee_assessment_voided';

    public const LATE_FEE_VOID = 'late_fee_voided';

    /** A source domain's own void (e.g. a Library fine void); the source keeps its own reason. */
    public const SOURCE_VOID = 'source_voided';

    public const CODES = [
        self::CANCEL => ['charged_in_error', 'duplicate_charge', 'wrong_student_or_amount', 'service_not_provided', 'other'],
        self::ASSESSMENT_VOID => ['assessment_error', 'duplicate_assessment', 'wrong_placement', 'other'],
        self::LATE_FEE_VOID => ['late_fee_in_error', 'payment_was_on_time', 'other'],
        self::SOURCE_VOID => ['source_voided'],
    ];

    /** @return list<string> every code, as the database lists them */
    public static function all(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::CODES))));
    }

    public static function require(string $action, string $code, ?string $note): void
    {
        if (! in_array($code, self::CODES[$action] ?? [], true)) {
            throw new ChargeCancellationReasonException;
        }
        if ($code === 'other' && trim((string) $note) === '') {
            throw new ChargeCancellationReasonException('Describe the reason when choosing "other".');
        }
    }
}
