<?php

namespace App\Support\StudentReports;

/**
 * SIS 11.1: one column of a Student report, with its privacy class.
 *
 * - OPERATIONAL: the minimum a School office needs to act on a row (Student
 *   number and name, status, placement, a closed code's label, a date).
 * - RESTRICTED: shown only to a viewer who ALSO holds `requires` (whole
 *   School); otherwise it is absent from the payload, never blanked.
 * - Prohibited data never becomes a column: the constructor refuses every
 *   key in PROHIBITED (identity secrets, contact values, digests, internal
 *   identifiers, health and statutory identifiers).
 *
 * `exportable` records future export eligibility only. There is no export in
 * SIS 11.1 (the governed export contract, D-11, comes first), and
 * StudentReportRegistryTest pins every column to false.
 */
final readonly class StudentReportColumn
{
    public const string OPERATIONAL = 'operational';

    public const string RESTRICTED = 'restricted';

    /** Closed cell formats the shared report page knows how to render. */
    public const array FORMATS = ['text', 'date', 'student', 'student_list', 'placement', 'lifecycle_reason'];

    /**
     * Never a report column, whatever a reference screen shows (D-15, ADR
     * 0041, DATA-CLASSIFICATION): personal identity, contact and family
     * values, statutory and health identifiers, and internal or secret ones.
     */
    public const array PROHIBITED = [
        'date_of_birth', 'age', 'gender', 'photo', 'address', 'national_id', 'government_id', 'passport',
        'caste', 'religion', 'mother_tongue', 'disability', 'medical', 'blood_group',
        'guardian_contact', 'guardian_email', 'guardian_phone', 'guardian_name', 'email', 'phone',
        'value_encrypted', 'value_hmac', 'lookup_hmac', 'password', 'token', 'ip_address', 'user_agent',
        'id', 'school_id', 'acted_by_user_id', 'metadata',
    ];

    /** @param  list<string>  $requires  whole-School capabilities a RESTRICTED column also needs */
    public function __construct(
        public string $key,
        public string $label,
        public string $format = 'text',
        public string $privacy = self::OPERATIONAL,
        public array $requires = [],
        public bool $exportable = false,
    ) {
        if (preg_match('/^[a-z][a-z_]*$/', $key) !== 1 || in_array($key, self::PROHIBITED, true)) {
            throw new \InvalidArgumentException("Column {$key} is not an allowed report column.");
        }
        if (! in_array($format, self::FORMATS, true) || ! in_array($privacy, [self::OPERATIONAL, self::RESTRICTED], true)) {
            throw new \InvalidArgumentException("Column {$key} has an unknown format or privacy class.");
        }
        if (($privacy === self::RESTRICTED) !== ($requires !== [])) {
            throw new \InvalidArgumentException("Column {$key}: a RESTRICTED column names what it requires, and only it does.");
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'format' => $this->format];
    }
}
