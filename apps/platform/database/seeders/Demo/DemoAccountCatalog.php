<?php

namespace Database\Seeders\Demo;

/**
 * LOCAL DDEV DEMO ONLY -- the single catalog of demo login accounts:
 * used by DemoDataBuilder (which creates them, guarded by
 * DemoEnvironmentGuard) and by App\Support\Demo\DemoLoginPanel (which
 * lists them as login-form shortcuts, under the same guard). See
 * docs/development/DDEV-DEMO-REVIEW.md.
 *
 * Operations-desk roles are DEMO-ONLY, non-system school roles built
 * exclusively from capabilities that already exist in
 * CapabilityAndRoleSeeder -- each is one existing capability family
 * (finance.*, library.*, ...), so a reviewer can see that module in
 * isolation. They are never created by DatabaseSeeder and grant nothing
 * the catalog does not already define.
 */
final class DemoAccountCatalog
{
    public const GROUP_PEOPLE = 'People';

    public const GROUP_OPERATIONS = 'Operations desks (demo-only roles)';

    /**
     * @var array<string, array{name: string, email: string, user: string, persona: string, capabilities: list<string>}>
     */
    public const OPERATIONS_DESK_ROLES = [
        'demo.finance_officer' => [
            'name' => 'Demo: Finance Officer',
            'email' => 'finance.officer@example.test',
            'user' => 'Neha Kapoor (Finance, demo role)',
            'persona' => 'Finance Officer',
            'capabilities' => [
                'finance.ledger.view', 'finance.ledger.post', 'finance.ledger.reverse',
                'finance.charges.view', 'finance.charges.manage', 'finance.payments.view',
                'finance.payments.record',
                // FEE.1: fee setup and ledger-account administration. The
                // FEE.3 concession approval stays with School Admin (ADR
                // 0062 §19 maker/checker separation).
                'finance.accounts.manage', 'finance.fee_structures.view', 'finance.fee_structures.manage',
                'finance.fee_assessments.run',
                // FEE.3: view and request concessions; approval stays with
                // School Admin so the demo shows maker/checker.
                'finance.fee_concessions.view', 'finance.fee_concessions.request',
            ],
        ],
        'demo.librarian' => [
            'name' => 'Demo: Librarian',
            'email' => 'library.operator@example.test',
            'user' => 'Leela Nair (Librarian, demo role)',
            'persona' => 'Librarian',
            'capabilities' => [
                'library.catalogue.view', 'library.catalogue.manage',
                'library.circulation.view', 'library.circulation.manage',
            ],
        ],
        'demo.transport_coordinator' => [
            'name' => 'Demo: Transport Coordinator',
            'email' => 'transport.operator@example.test',
            'user' => 'Babu Rao (Transport, demo role)',
            'persona' => 'Transport Coordinator',
            'capabilities' => [
                'transport.routes.view', 'transport.routes.manage',
                'transport.vehicles.view', 'transport.vehicles.manage',
                'transport.assignments.view', 'transport.assignments.manage',
            ],
        ],
        'demo.reception' => [
            'name' => 'Demo: Reception / Visitor Desk',
            'email' => 'reception@example.test',
            'user' => 'Joseph Thomas (Reception, demo role)',
            'persona' => 'Reception / Visitor Desk',
            'capabilities' => [
                'visitor.directory.view', 'visitor.directory.manage',
                'visitor.visits.view', 'visitor.visits.manage',
            ],
        ],
        'demo.hostel_warden' => [
            'name' => 'Demo: Hostel Warden',
            'email' => 'hostel.warden@example.test',
            'user' => 'Savita Kulkarni (Hostel, demo role)',
            'persona' => 'Hostel Warden',
            'capabilities' => [
                'hostel.directory.view', 'hostel.directory.manage',
                'hostel.residency.view', 'hostel.residency.manage',
            ],
        ],
        'demo.canteen_stores' => [
            'name' => 'Demo: Canteen & Stores',
            'email' => 'canteen.operator@example.test',
            'user' => 'Ravi Menon (Canteen & Stores, demo role)',
            'persona' => 'Canteen & Stores',
            'capabilities' => [
                'canteen.directory.view', 'canteen.directory.manage',
                'canteen.orders.view', 'canteen.orders.manage',
                'canteen.settings.view', 'canteen.settings.manage',
                'inventory.directory.view', 'inventory.directory.manage',
                'inventory.stock.view', 'inventory.stock.manage',
            ],
        ],
        'demo.communications_coordinator' => [
            'name' => 'Demo: Communications Coordinator',
            'email' => 'communications@example.test',
            'user' => 'Anita Desai (Communications, demo role)',
            'persona' => 'Communications Coordinator',
            // Exactly the communications grant the seeded `principal`
            // system role already carries -- nothing broader.
            'capabilities' => [
                'communications.view', 'communications.send', 'communications.reply',
                'communications.announce', 'communications.templates.manage', 'communications.approve',
                'communications.conversations.guardians',
            ],
        ],
    ];

    /**
     * Login-page shortcuts, in display order: persona, email, a short
     * review hint describing the account's REAL access, and its group.
     *
     * @return list<array{persona: string, email: string, hint: string, group: string}>
     */
    public static function loginShortcuts(): array
    {
        $people = [
            ['School Admin', 'school.admin@example.test', 'Broad school administration: every module incl. Finance and Payroll administration.'],
            ['Principal', 'principal@example.test', 'Academics, students, admissions, communications, operations, curriculum Analytics; Finance and Payroll are 403.'],
            ['HR & Payroll', 'hr.payroll@example.test', 'Demo role: HR incl. sensitive records, payroll runs, payslips, statutory screens.'],
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
            'demo.finance_officer' => 'Ledger accounts, journal entries, fee setup and runs, fee charges, payments, offline payment recording, and concession requests (School Admin approves) (Dashboard > Finance).',
            'demo.librarian' => 'Catalogue and circulation at /app/library/titles and /app/library/circulation (no menu link).',
            'demo.transport_coordinator' => 'Routes, vehicles, operations and assignments at /app/transport/routes (no menu link).',
            'demo.reception' => 'Visitor directory and check-in/out at /app/visitor/directory and /app/visitor/visits (no menu link).',
            'demo.hostel_warden' => 'Hostels, rooms, beds and residency at /app/hostels and /app/hostel-residency (no menu link).',
            'demo.canteen_stores' => 'Canteen outlets, items, orders, settings (Dashboard) plus inventory at /app/inventory-stock.',
            'demo.communications_coordinator' => 'Communication Hub with the Principal\'s communications access; nothing else.',
        ];

        $shortcuts = [];

        foreach ($people as [$persona, $email, $hint]) {
            $shortcuts[] = ['persona' => $persona, 'email' => $email, 'hint' => $hint, 'group' => self::GROUP_PEOPLE];
        }

        foreach (self::OPERATIONS_DESK_ROLES as $key => $role) {
            $shortcuts[] = ['persona' => $role['persona'], 'email' => $role['email'], 'hint' => $operationsHints[$key], 'group' => self::GROUP_OPERATIONS];
        }

        return $shortcuts;
    }
}
