<?php

namespace App\Domain\Identity\Application\Access;

/**
 * ORG.12E (ADR 0080 §10.8, OD-12.15): the code-owned catalogue of the
 * sensitive personal-data FAMILIES that actually exist in Lycenza today --
 * what each covers, its classification, the capability that already gates
 * it, and whether a School may narrow it with a deny-only policy.
 *
 * - Built from the ORG.12E field audit: only real fields, never a theoretical
 *   family (no government-ID, immigration, medical or bank-account data is
 *   stored, so none is listed as configurable).
 * - EVERY family is currently NOT configurable. A deny-only School policy is
 *   safe only when every exposure path (web, API, search, lookups, reports,
 *   exports, notifications) goes through one projection that the policy can
 *   intercept, and only when a denial can be evaluated per role path. Neither
 *   holds yet (ADR 0080 §10.8): the reason is recorded per family. Turning one
 *   on needs that central projection and an owner decision first -- never a
 *   UI-only hide.
 * - Never an authorization input: the gate stays the listed capability, its
 *   scope and its ownership rules. This catalogue only explains them.
 */
final class SensitiveDataCatalogue
{
    /**
     * @var array<string, array{label: string, domain: string, classification: string, fields: list<string>, baseline: list<string>, configurable: false, reason: string}>
     */
    public const FAMILIES = [
        'guardian_contact' => [
            'label' => 'Guardian contact details',
            'domain' => 'Student Records',
            'classification' => 'Sensitive',
            'fields' => ['Guardian email address', 'Guardian mobile number'],
            'baseline' => ['guardians.view'],
            'configurable' => false,
            'reason' => 'Shown by several separately built pages and API responses, and used to deliver messages; a denial needs one shared projection first.',
        ],
        'employee_personal_contact' => [
            'label' => 'Employee personal contact details',
            'domain' => 'Staff Records',
            'classification' => 'Restricted',
            'fields' => ['Personal email', 'Personal phone', 'Alternate phone'],
            'baseline' => ['hr.employees.personal.view'],
            'configurable' => false,
            'reason' => 'Part of the HR profile as a whole (no separate section gate yet), and echoed by edit responses.',
        ],
        'employee_home_address' => [
            'label' => 'Employee home address',
            'domain' => 'Staff Records',
            'classification' => 'Restricted',
            'fields' => ['Address lines', 'City', 'State or region', 'Postal code', 'Country'],
            'baseline' => ['hr.employees.personal.view'],
            'configurable' => false,
            'reason' => 'Part of the HR profile as a whole (no separate section gate yet), and echoed by edit responses.',
        ],
        'employee_emergency_contact' => [
            'label' => 'Employee emergency contacts',
            'domain' => 'Staff Records',
            'classification' => 'Restricted',
            'fields' => ['Contact name', 'Phone', 'Alternate phone', 'Email'],
            'baseline' => ['hr.employees.personal.view'],
            'configurable' => false,
            'reason' => 'Part of the HR profile as a whole (no separate section gate yet), and echoed by edit responses.',
        ],
        'date_of_birth' => [
            'label' => 'Date of birth',
            'domain' => 'Student Records, Admissions, Staff Records',
            'classification' => 'Sensitive',
            'fields' => ['Student date of birth', 'Applicant date of birth', 'Employee date of birth'],
            'baseline' => ['students.view', 'admissions.view', 'hr.employees.personal.view'],
            'configurable' => false,
            'reason' => 'Operationally required (age rules, duplicate detection) and shown by several separately built pages; already hidden on every Campus-scoped surface.',
        ],
        'statutory_identifier' => [
            'label' => 'Statutory identifiers (PAN, UAN, PF member, ESIC IP number)',
            'domain' => 'Payroll',
            'classification' => 'Highly Sensitive',
            'fields' => ['Masked identifier', 'Full identifier (revealed and audited)'],
            'baseline' => ['payroll.statutory.view', 'payroll.statutory.identifiers.view'],
            'configurable' => false,
            'reason' => 'Legally gated: masked by default, the full value needs its own capability and every reveal is audited; statutory filings need the value.',
        ],
        'hr_highly_sensitive_documents' => [
            'label' => 'Highly sensitive employee documents',
            'domain' => 'Staff Records',
            'classification' => 'Highly Sensitive',
            'fields' => ['Documents classified highly sensitive'],
            'baseline' => ['hr.employees.sensitive.view'],
            'configurable' => false,
            'reason' => 'Already behind a dedicated capability granted only through a grant right; a further School switch would add no protection.',
        ],
        'visitor_contact' => [
            'label' => 'Visitor phone number',
            'domain' => 'Visitors',
            'classification' => 'Sensitive',
            'fields' => ['Visitor phone'],
            'baseline' => ['visitor.directory.view', 'visitor.visits.view'],
            'configurable' => false,
            'reason' => 'Shown by several separately built pages and API responses; a denial needs one shared projection first.',
        ],
    ];

    /** Never exposed by any surface and never configurable (security integrity). */
    public const NEVER_EXPOSED = [
        'Passwords and password hashes',
        'Multi-factor secrets and recovery codes',
        'API tokens and client secrets',
        'Audit and security internals',
    ];

    /** Families Lycenza does not store today, so nothing can be configured for them. */
    public const NOT_STORED = [
        'Government identity numbers (e.g. Aadhaar, passport)',
        'Immigration or visa identifiers',
        'Medical or health information',
        'Bank account or card details',
    ];

    /**
     * The families a set of capabilities reaches (any baseline held).
     *
     * @param  list<string>  $capabilities
     * @return list<string>
     */
    public static function reachedBy(array $capabilities): array
    {
        return array_keys(array_filter(self::FAMILIES, fn (array $family): bool => array_intersect($family['baseline'], $capabilities) !== []));
    }

    /** @return list<array{key: string, label: string, domain: string, classification: string, fields: list<string>, baseline: list<string>, configurable: bool, reason: string}> */
    public static function present(): array
    {
        return array_map(fn (string $key, array $family): array => ['key' => $key, ...$family], array_keys(self::FAMILIES), self::FAMILIES);
    }
}
