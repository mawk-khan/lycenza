<?php

namespace App\Domain\Communications\Application\Audience\FeeReminder;

use Carbon\CarbonImmutable;

/**
 * ORG.9D-2 (ADR 0076 §74): what a fee reminder says to each Guardian
 * account, resolved at one instant (`asOf`, UTC). At preview it is advisory
 * and persisted nowhere; at publish it becomes each recipient's immutable
 * `communication_fee_reminder_lines`. Lines carry a Student's display name
 * and current outstanding only -- never an id, Charge, payment or
 * concession. A Guardian of several selected Students gets all of them in
 * ONE set of lines.
 */
final readonly class FeeReminderAudience
{
    /**
     * @param  array<string, list<array{studentName: string, amount: string, currency: string}>>  $linesByUser  userId => lines, ordered by name
     * @param  array<string, string>  $guardianByUser  userId => the Guardian persona it was reached as
     */
    public function __construct(
        public CarbonImmutable $asOf,
        public array $linesByUser,
        public array $guardianByUser,
        public int $studentCount,
        public int $studentsWithoutRecipient,
        public string $totalOutstanding,
        public string $currency,
    ) {}
}
