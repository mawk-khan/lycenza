<?php

namespace Database\Seeders\Demo;

/**
 * LOCAL DDEV DEMO ONLY -- the single catalog of demo login accounts:
 * used by DemoDataBuilder (which creates them, guarded by
 * DemoEnvironmentGuard) and by App\Support\Demo\DemoLoginPanel (which
 * lists them as login-form shortcuts, under the same guard). See
 * docs/development/DDEV-DEMO-REVIEW.md.
 *
 * SR.3 (ADR 0071 §17): the operations-desk personas hold the PRODUCTION
 * staff roles (the fixed catalogue seeded by CapabilityAndRoleSeeder) --
 * no demo-only role recreates a merged or broader job. A person with two
 * jobs holds two roles (canteen + stores). The only demo-only role left is
 * DemoDataBuilder::DEMO_STATUTORY_ROLE_KEY (the legally gated statutory
 * payroll screens, which no production role carries).
 */
final class DemoAccountCatalog
{
    public const GROUP_PEOPLE = 'People';

    public const GROUP_OPERATIONS = 'Operations desks (production roles)';

    /**
     * Persona => the production School role keys it holds (additive grants).
     *
     * @var array<string, array{email: string, user: string, persona: string, roles: list<string>}>
     */
    public const OPERATIONS_DESKS = [
        'finance' => [
            'email' => 'finance.officer@example.test',
            'user' => 'Neha Kapoor (Accountant)',
            'persona' => 'Accountant',
            // ADR 0071 §4.4: no ledger reversal, period closing or concession
            // approval -- School Admin approves (maker/checker).
            'roles' => ['accountant'],
        ],
        'library' => [
            'email' => 'library.operator@example.test',
            'user' => 'Leela Nair (Librarian)',
            'persona' => 'Librarian',
            'roles' => ['librarian'],
        ],
        'transport' => [
            'email' => 'transport.operator@example.test',
            'user' => 'Babu Rao (Transport Coordinator)',
            'persona' => 'Transport Coordinator',
            'roles' => ['transport_coordinator'],
        ],
        'reception' => [
            'email' => 'reception@example.test',
            'user' => 'Joseph Thomas (Front Office)',
            'persona' => 'Front Office',
            'roles' => ['front_office'],
        ],
        'hostel' => [
            'email' => 'hostel.warden@example.test',
            'user' => 'Savita Kulkarni (Hostel Warden)',
            'persona' => 'Hostel Warden',
            'roles' => ['hostel_warden'],
        ],
        'canteen_stores' => [
            'email' => 'canteen.operator@example.test',
            'user' => 'Ravi Menon (Canteen Operator + Stores Officer)',
            'persona' => 'Canteen & Stores',
            // Two ADDITIVE production roles, never a merged canteen+stores role.
            'roles' => ['canteen_operator', 'stores_officer'],
        ],
        'communications' => [
            'email' => 'communications@example.test',
            'user' => 'Anita Desai (Communications Coordinator)',
            'persona' => 'Communications Coordinator',
            'roles' => ['communications_coordinator'],
        ],
    ];

    public static function loginShortcuts(): array
    {
        $people = [
            ['School Admin', 'school.admin@example.test', 'Broad school administration: every module incl. Finance and Payroll administration.'],
            ['Principal', 'principal@example.test', 'Academics, students, admissions, communications, operations, curriculum Analytics; Finance and Payroll are 403.'],
            ['HR & Payroll', 'hr.payroll@example.test', 'HR Officer + HR Sensitive Records + Payroll Officer (production roles) plus the demo-only statutory-payroll role: HR incl. sensitive records, preparing payroll runs, statutory screens; approval and posting stay with School Admin.'],
            ['Multi-school Admin', 'multi.school@example.test', 'Principal at Demo School, School Admin at Annexe: School switching and tenant isolation.'],
            ['Annexe School Admin', 'annexe.admin@example.test', 'School Admin of the second School only: cannot see Demo School records.'],
            ['Platform Admin', 'platform.admin@example.test', 'Platform scope: elevated School entry (opens no School page), School Groups, platform audit log (needs MFA), Platform Auditor grants; no School membership.'],
            ['Platform Auditor', 'platform.auditor@example.test', 'Platform scope, review only: the platform audit log (enroll MFA first); no School, Group or other platform action.'],
            ['Group Admin', 'group.admin@example.test', 'Group scope only: views the Lycenza Demo Trust and its two Schools, enters one via elevated access (MFA required); no School permission.'],
            ['Teacher', 'teacher@example.test', 'Teacher role + Staff Self-Service role, linked to an Employee: My Curriculum Delivery, My Attendance, My Learning Content and My Assignments for the one class she is assigned (G8-A Mathematics); My Leave, My Staff Attendance and My Payslips for herself; every other module is 403.'],
            ['Student', 'student@example.test', 'Linked Student account: no student portal exists; modules are 403.'],
            ['Guardian', 'guardian01@example.test', 'Activated Guardian account: the Guardian portal inbox, her child\'s attendance and fees, and replies in conversations the School starts with her (POR.1–POR.4, development only; all but the inbox need MFA); every staff module is 403.'],
        ];

        $operationsHints = [
            'finance' => 'Accountant: ledger posting, fee setup and runs, charges, payments, offline payment recording and concession requests; reversals and approvals stay with School Admin (Dashboard > Finance).',
            'library' => 'Librarian: catalogue, circulation and fines (view) at /app/library/titles and /app/library/circulation (no menu link).',
            'transport' => 'Transport Coordinator: routes, vehicles, operations and assignments at /app/transport/routes (no menu link).',
            'reception' => 'Front Office: visitor directory and check-in/out at /app/visitor/directory and /app/visitor/visits (no menu link).',
            'hostel' => 'Hostel Warden: hostels, rooms, beds and residency at /app/hostels and /app/hostel-residency (no menu link).',
            'canteen_stores' => 'Canteen Operator + Stores Officer (two roles): canteen outlets, items and orders (Dashboard) plus inventory at /app/inventory-stock; canteen settings stay with School Admin.',
            'communications' => 'Communications Coordinator: Communication Hub messages, announcements, templates and Guardian conversations; approval stays with the Principal or School Admin.',
        ];

        $shortcuts = [];

        foreach ($people as [$persona, $email, $hint]) {
            $shortcuts[] = ['persona' => $persona, 'email' => $email, 'hint' => $hint, 'group' => self::GROUP_PEOPLE];
        }

        foreach (self::OPERATIONS_DESKS as $key => $desk) {
            $shortcuts[] = ['persona' => $desk['persona'], 'email' => $desk['email'], 'hint' => $operationsHints[$key], 'group' => self::GROUP_OPERATIONS];
        }

        return $shortcuts;
    }
}
