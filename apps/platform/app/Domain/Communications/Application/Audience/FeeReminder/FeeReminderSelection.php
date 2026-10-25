<?php

namespace App\Domain\Communications\Application\Audience\FeeReminder;

/**
 * ORG.9D-2 (ADR 0076 §74, PV1-11): WHICH Students a fee-due reminder is
 * about -- the population definition, never a list of Students or amounts.
 * The same filters the Outstanding fees page offers: balance status
 * (outstanding / overdue), academic year, the Charge's stored Campus (a
 * Campus id, or `unattributed` for whole-School Charges) and the Student's
 * current class (Grade or Section). Fixed when the draft is created and
 * bound by the approval fingerprint; the Students and balances themselves
 * are resolved fresh at preview and again at publish.
 */
final readonly class FeeReminderSelection
{
    public const string OUTSTANDING = 'outstanding';

    public const string OVERDUE = 'overdue';

    public function __construct(
        public string $balanceStatus = self::OUTSTANDING,
        public ?string $academicYearId = null,
        public ?string $campusId = null,
        public bool $campusUnattributed = false,
        public ?string $gradeLevelId = null,
        public ?string $sectionId = null,
    ) {
        if (! in_array($balanceStatus, [self::OUTSTANDING, self::OVERDUE], true)) {
            throw new \InvalidArgumentException('A fee reminder selects outstanding or overdue balances only.');
        }
        if ($campusUnattributed && $campusId !== null) {
            throw new \InvalidArgumentException('A fee reminder narrows to one Campus or to whole-School Charges, not both.');
        }
    }

    public function narrowsByClass(): bool
    {
        return $this->gradeLevelId !== null || $this->sectionId !== null;
    }

    /** @return array{balanceStatus: string, academicYearId: ?string, campusId: ?string, campusUnattributed: bool, gradeLevelId: ?string, sectionId: ?string} */
    public function toArray(): array
    {
        return [
            'balanceStatus' => $this->balanceStatus,
            'academicYearId' => $this->academicYearId,
            'campusId' => $this->campusId,
            'campusUnattributed' => $this->campusUnattributed,
            'gradeLevelId' => $this->gradeLevelId,
            'sectionId' => $this->sectionId,
        ];
    }
}
