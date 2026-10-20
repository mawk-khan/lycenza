<?php

namespace App\Domain\HR\Application;

/**
 * ORG.7E-1 (ADR 0076 §43): the COMPLETE Campus onboarding field set -- core
 * work identity, the employment type and start date, and the first
 * placement (Campus, Position, optional Department). Nothing else is
 * accepted: no account link, personal, contact, address, qualification,
 * document, note, category or pay field.
 */
final class EmployeeOnboardingData
{
    public function __construct(
        public readonly string $fullName,
        public readonly ?string $workEmail,
        public readonly ?string $workPhone,
        public readonly string $employmentType,
        public readonly string $startsOn,
        public readonly string $campusId,
        public readonly string $positionId,
        public readonly ?string $departmentId,
    ) {}

    /** The validation rules every onboarding ingress applies (web and API alike). */
    public static function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'work_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'work_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'employment_type' => ['required', 'string', 'max:255'],
            'starts_on' => ['required', 'date'],
            'campus_id' => ['required', 'uuid'],
            'position_id' => ['required', 'uuid'],
            'department_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    /** @param  array<string, mixed>  $validated */
    public static function fromValidated(array $validated): self
    {
        return new self(
            fullName: (string) $validated['full_name'],
            workEmail: isset($validated['work_email']) && $validated['work_email'] !== '' ? (string) $validated['work_email'] : null,
            workPhone: isset($validated['work_phone']) && $validated['work_phone'] !== '' ? (string) $validated['work_phone'] : null,
            employmentType: (string) $validated['employment_type'],
            startsOn: (string) $validated['starts_on'],
            campusId: strtolower((string) $validated['campus_id']),
            positionId: (string) $validated['position_id'],
            departmentId: isset($validated['department_id']) && $validated['department_id'] !== '' ? (string) $validated['department_id'] : null,
        );
    }
}
