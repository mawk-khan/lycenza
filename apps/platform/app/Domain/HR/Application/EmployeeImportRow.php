<?php

namespace App\Domain\HR\Application;

use App\Domain\HR\Application\Exceptions\EmployeeImportUnknownFieldException;
use App\Domain\HR\Application\Exceptions\EmployeeImportValidationException;
use App\Domain\HR\Application\Workforce\EmploymentType;
use App\Domain\HR\Application\Workforce\StaffType;

/**
 * Phase 8A.12 -- one normalized, validated, ALLOW-LISTED Employee
 * import row. This is the untrusted-input boundary itself: `fromArray()`
 * is the only way to construct one, and it accepts NOTHING outside
 * `ALLOWED_KEYS` -- a caller-supplied `school_id`, `employee_id`,
 * `uploaded_by_user_id`, `storage_path`, or any Highly Sensitive field
 * (a government id, a bank account/tax identifier, ...) is rejected
 * with `EmployeeImportUnknownFieldException`, never silently dropped
 * (checkpoint brief sections 4/44/62 -- "no generic `$service->create($row)`
 * with unchecked headings").
 *
 * Deliberately excludes (8A.0 through 8A.12 scope decision, see
 * docs/modules/HR.md's 8A.12 as-built section): Qualifications,
 * Experience, Certifications, EmployeeDocuments, reporting-manager
 * assignment, historical Employment/Assignment rows, and Employee
 * `record_status`/Employment lifecycle `status` (rule 29/30 -- a new
 * Employee/Employment always starts in its authoritative services' own
 * default state, never a caller-forged one).
 */
final class EmployeeImportRow
{
    public const array ALLOWED_KEYS = [
        'full_name', 'user_id',
        'date_of_birth', 'nationality', 'marital_status', 'preferred_language',
        'personal_email', 'personal_phone', 'alternate_phone',
        'employment_type', 'employment_starts_on', 'probation_ends_on',
        // HR.1A: optional; absent = the employment is recorded unclassified.
        'staff_type',
        'position_code', 'department_code', 'campus_code', 'assignment_starts_on',
    ];

    public function __construct(
        public readonly string $fullName,
        public readonly ?string $userId,
        public readonly ?string $dateOfBirth,
        public readonly ?string $nationality,
        public readonly ?string $maritalStatus,
        public readonly ?string $preferredLanguage,
        public readonly ?string $personalEmail,
        public readonly ?string $personalPhone,
        public readonly ?string $alternatePhone,
        public readonly ?string $employmentType,
        public readonly ?string $employmentStartsOn,
        public readonly ?string $probationEndsOn,
        public readonly ?string $positionCode,
        public readonly ?string $departmentCode,
        public readonly ?string $campusCode,
        public readonly ?string $assignmentStartsOn,
        public readonly ?string $staffType = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        foreach (array_keys($data) as $key) {
            if (! in_array($key, self::ALLOWED_KEYS, true)) {
                throw new EmployeeImportUnknownFieldException((string) $key);
            }
        }

        $fullName = self::stringOrNull($data['full_name'] ?? null);
        if ($fullName === null || trim($fullName) === '') {
            throw new EmployeeImportValidationException('full_name', 'full_name is required and cannot be blank.');
        }

        $row = new self(
            fullName: $fullName,
            userId: self::stringOrNull($data['user_id'] ?? null),
            dateOfBirth: self::dateOrNull('date_of_birth', $data),
            nationality: self::stringOrNull($data['nationality'] ?? null),
            maritalStatus: self::stringOrNull($data['marital_status'] ?? null),
            preferredLanguage: self::stringOrNull($data['preferred_language'] ?? null),
            personalEmail: self::stringOrNull($data['personal_email'] ?? null),
            personalPhone: self::stringOrNull($data['personal_phone'] ?? null),
            alternatePhone: self::stringOrNull($data['alternate_phone'] ?? null),
            employmentType: self::stringOrNull($data['employment_type'] ?? null),
            employmentStartsOn: self::dateOrNull('employment_starts_on', $data),
            probationEndsOn: self::dateOrNull('probation_ends_on', $data),
            positionCode: self::stringOrNull($data['position_code'] ?? null),
            departmentCode: self::stringOrNull($data['department_code'] ?? null),
            campusCode: self::stringOrNull($data['campus_code'] ?? null),
            assignmentStartsOn: self::dateOrNull('assignment_starts_on', $data),
            staffType: self::stringOrNull($data['staff_type'] ?? null),
        );

        $row->assertConsistent();

        return $row;
    }

    private function assertConsistent(): void
    {
        $hasEmploymentType = $this->employmentType !== null;
        $hasEmploymentStartsOn = $this->employmentStartsOn !== null;

        if ($hasEmploymentType xor $hasEmploymentStartsOn) {
            throw new EmployeeImportValidationException('employment_type', 'employment_type and employment_starts_on must both be supplied together, or both omitted.');
        }

        // HR.1A (S-12): the one closed catalogue (EmploymentType), shared with every write path.
        if ($hasEmploymentType && EmploymentType::tryFrom((string) $this->employmentType) === null) {
            throw new EmployeeImportValidationException('employment_type', "employment_type '{$this->employmentType}' is not a supported value.");
        }

        if ($this->staffType !== null && (! $hasEmploymentType || StaffType::tryFrom($this->staffType) === null)) {
            throw new EmployeeImportValidationException('staff_type', 'staff_type must be teaching, non_teaching or outsourced, and needs employment data.');
        }

        if ($this->positionCode !== null && ! $this->hasEmploymentData()) {
            throw new EmployeeImportValidationException('position_code', 'Importing an Assignment (position_code) requires employment_type and employment_starts_on to also be supplied.');
        }
    }

    public function hasPersonalData(): bool
    {
        return $this->dateOfBirth !== null || $this->nationality !== null || $this->maritalStatus !== null
            || $this->preferredLanguage !== null || $this->personalEmail !== null
            || $this->personalPhone !== null || $this->alternatePhone !== null;
    }

    public function hasEmploymentData(): bool
    {
        return $this->employmentType !== null && $this->employmentStartsOn !== null;
    }

    public function hasAssignmentData(): bool
    {
        return $this->positionCode !== null;
    }

    /**
     * @return array{date_of_birth: ?string, nationality: ?string, marital_status: ?string, preferred_language: ?string, personal_email: ?string, personal_phone: ?string, alternate_phone: ?string}
     */
    public function personalAttributes(): array
    {
        return [
            'date_of_birth' => $this->dateOfBirth,
            'nationality' => $this->nationality,
            'marital_status' => $this->maritalStatus,
            'preferred_language' => $this->preferredLanguage,
            'personal_email' => $this->personalEmail,
            'personal_phone' => $this->personalPhone,
            'alternate_phone' => $this->alternatePhone,
        ];
    }

    /**
     * @return array{employment_type: string, starts_on: string, probation_ends_on: ?string, staff_type: ?string}
     */
    public function employmentAttributes(): array
    {
        return [
            'employment_type' => $this->employmentType,
            'staff_type' => $this->staffType,
            'starts_on' => $this->employmentStartsOn,
            'probation_ends_on' => $this->probationEndsOn,
        ];
    }

    /**
     * The Assignment's own `starts_on` defaults to the Employment's
     * `starts_on` when not separately supplied -- the common case (an
     * imported Employee's Assignment begins the same day their
     * Employment does).
     */
    public function assignmentStartsOn(): string
    {
        return $this->assignmentStartsOn ?? $this->employmentStartsOn;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            throw new EmployeeImportValidationException(null, 'Import field values must be strings.');
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private static function dateOrNull(string $field, array $data): ?string
    {
        $value = self::stringOrNull($data[$field] ?? null);

        if ($value === null) {
            return null;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new EmployeeImportValidationException($field, "{$field} must be a date in YYYY-MM-DD format.");
        }

        return $value;
    }
}
