<?php

namespace App\Domain\Identity\Application\Staff;

use App\Support\Authorization\CapabilityClasses;

/**
 * SR.3 (ADR 0071 §20, §25): how the fixed School staff catalogue is PRESENTED
 * in Settings -> Staff accounts -- a functional group, a stable order, a
 * one-line purpose, an "add-on" marker and sensitivity labels.
 *
 * Presentation only: role keys appear here as catalogue identity (ADR 0071
 * §19 permits keys in catalogue presentation), never as an authorization
 * input -- who may grant what is RoleGrantAuthority's decision alone. The
 * sensitivity labels are derived from the role's capability CLASSES
 * (CapabilityClasses), never a capability key or a grant right, so a viewer
 * learns what kind of data a role reaches, not its internal permission list.
 */
final class StaffRolePresentation
{
    /** @var array<string, string> group => label, in display order */
    public const GROUPS = [
        'administration' => 'School administration',
        'teaching' => 'Teaching and self-service',
        'people' => 'HR and payroll',
        'finance' => 'Finance',
        'operations' => 'Operations desks',
        'admissions' => 'Admissions and communications',
    ];

    /** @var array<string, array{group: string, purpose: string, addOn?: bool}> in display order */
    public const ROLES = [
        'school_admin' => ['group' => 'administration', 'purpose' => 'Administers the School: staff access, settings and every module, including approvals and reversals.'],
        'principal' => ['group' => 'administration', 'purpose' => 'Academic administration: academics, students, admissions, communications and operations; no Finance or Payroll.'],
        'teacher' => ['group' => 'teaching', 'purpose' => 'Teacher surfaces for the classes a Teaching Assignment gives them, and nothing School-wide.'],
        'staff_self_service' => ['group' => 'teaching', 'purpose' => 'An employee\'s own leave, attendance and payslips.'],
        'hr_officer' => ['group' => 'people', 'purpose' => 'Employee records, employment, departments, positions, leave and staff attendance administration.'],
        'hr_sensitive_records' => ['group' => 'people', 'purpose' => 'Highly sensitive employee records. Granted in addition to HR Officer, never on its own.', 'addOn' => true],
        'payroll_officer' => ['group' => 'people', 'purpose' => 'Salary structures, compensation, payroll periods and preparing payroll runs; approval and posting stay with an administrator.'],
        'accountant' => ['group' => 'finance', 'purpose' => 'Ledger posting, charges, fee setup and assessment runs, payments and concession requests; reversals, period closing and approvals stay with an administrator.'],
        'cashier' => ['group' => 'finance', 'purpose' => 'Records payments received and views charges.'],
        'librarian' => ['group' => 'operations', 'purpose' => 'Library catalogue and circulation; views fines (fine policy and voiding stay with an administrator).'],
        'transport_coordinator' => ['group' => 'operations', 'purpose' => 'Transport routes, vehicles and Student transport assignments.'],
        'hostel_warden' => ['group' => 'operations', 'purpose' => 'Hostels, rooms and Student residency.'],
        'front_office' => ['group' => 'operations', 'purpose' => 'Visitor directory and visitor check-in and check-out.'],
        'stores_officer' => ['group' => 'operations', 'purpose' => 'Inventory items and stock.'],
        'canteen_operator' => ['group' => 'operations', 'purpose' => 'Canteen outlets, items and orders; canteen billing settings stay with an administrator.'],
        'admissions_officer' => ['group' => 'admissions', 'purpose' => 'Admission applications, decisions and conversion.'],
        'communications_coordinator' => ['group' => 'admissions', 'purpose' => 'Messages, announcements, templates and Guardian conversations; approval and emergency messages stay with leadership.'],
    ];

    /** @var array<string, string> capability class => label */
    public const CLASS_LABELS = [
        'authority' => 'Administrative authority',
        'hr-sensitive' => 'Highly sensitive HR data',
        'payroll-sensitive' => 'Payroll amounts',
        'hr' => 'HR data',
        'financial' => 'Financial',
        'children' => 'Children\'s data',
        'sensitive' => 'Sensitive personal data',
        'legal-gated' => 'Legally gated',
        'owned-scope' => 'Own or assigned records only',
        'operational' => 'Operational',
    ];

    /**
     * @param  list<string>  $capabilities  the role's capability keys
     * @return array{group: string, groupLabel: string, order: int, purpose: ?string, addOn: bool, sensitivity: list<string>}
     */
    public static function describe(string $roleKey, array $capabilities): array
    {
        $position = array_search($roleKey, array_keys(self::ROLES), true);
        $definition = self::ROLES[$roleKey] ?? null;
        $group = $definition['group'] ?? 'other';
        $classes = CapabilityClasses::union($capabilities);

        return [
            'group' => $group,
            'groupLabel' => self::GROUPS[$group] ?? 'Other roles',
            'order' => $position === false ? PHP_INT_MAX : $position,
            'purpose' => $definition['purpose'] ?? null,
            'addOn' => (bool) ($definition['addOn'] ?? false),
            // In CLASS_LABELS order (most sensitive first), never a capability key.
            'sensitivity' => array_values(array_intersect_key(self::CLASS_LABELS, array_flip($classes))),
        ];
    }
}
