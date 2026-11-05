<?php

namespace App\Support\HrReports;

/**
 * HR.14: one column of an HR report, with its privacy class.
 *
 * - OPERATIONAL: shown to every viewer of the report.
 * - RESTRICTED: shown only to a viewer who ALSO holds every `requires` key
 *   for the whole School; otherwise it is absent from the payload (and from
 *   the selectable set), never blanked.
 * - `default`: on a customizable report, whether the column is shown when
 *   the viewer has not chosen columns. Choosing is only ever among the
 *   columns this viewer may see; anything else is a validation error.
 * - Prohibited data never becomes a column (PROHIBITED): personal identity
 *   and contact values, family, statutory and bank identifiers, pay,
 *   protected characteristics, health, free text and internal identifiers.
 *   A reference screen showing a field is not a reason to report it.
 *
 * There is no export (owner decision 2026-11-05): HR reports are
 * browser-only.
 */
final readonly class HrReportColumn
{
    public const string OPERATIONAL = 'operational';

    public const string RESTRICTED = 'restricted';

    /** Closed cell formats the shared HR report page knows how to render. */
    public const array FORMATS = ['text', 'date', 'datetime', 'number', 'employee'];

    public const array PROHIBITED = [
        'date_of_birth', 'birthday', 'birth_date', 'age', 'gender', 'marital_status', 'spouse', 'father_name', 'mother_name',
        'address', 'personal_email', 'personal_phone', 'alternate_phone', 'emergency_contact', 'family',
        'bank_account', 'bank', 'ifsc', 'pan', 'aadhaar', 'uan', 'esi_number', 'pf_number', 'national_id', 'government_id', 'passport',
        'salary', 'gross_pay', 'net_pay', 'pay', 'compensation', 'deduction', 'ctc',
        'religion', 'caste', 'blood_group', 'medical', 'health', 'disability', 'photo', 'biometric',
        'remarks', 'feedback', 'note', 'notes', 'description', 'reason_text',
        'id', 'school_id', 'user_id', 'metadata', 'password', 'token', 'ip_address', 'user_agent',
    ];

    /** @param  list<string>  $requires  whole-School capabilities a RESTRICTED column also needs */
    public function __construct(
        public string $key,
        public string $label,
        public string $format = 'text',
        public string $privacy = self::OPERATIONAL,
        public array $requires = [],
        public bool $default = true,
    ) {
        if (preg_match('/^[a-z][a-z_]*$/', $key) !== 1 || in_array($key, self::PROHIBITED, true)) {
            throw new \InvalidArgumentException("Column {$key} is not an allowed HR report column.");
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
        return ['key' => $this->key, 'label' => $this->label, 'format' => $this->format, 'default' => $this->default];
    }
}
