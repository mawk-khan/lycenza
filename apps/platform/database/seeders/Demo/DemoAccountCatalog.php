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

    /** ORG.12E-D1: the canonical Organization demo world (docs/demo/ORGANIZATION-DEMO-DATA.md). */
    public const GROUP_ORGANIZATION = 'Organization demo (Northstar Network, standalone Schools)';

    /** ORG.12E-D1 personas, in the order DemoOrganizationData seeds them. */
    public const ORGANIZATION = [
        ['School Admin — Northstar Gachibowli', 'gachibowli.admin@example.test', 'Multi-Campus Network School: Setup & readiness (partial and blocked areas), rich School and Campus profiles with services, the Access Center and effective access.'],
        ['Accountant — Whole School', 'gachibowli.accounts@example.test', 'Effective access reads "Accountant — Whole School" (Finance enabled).'],
        ['Cashier — Campus: Gachibowli Main Campus', 'gachibowli.cashier@example.test', 'Effective on Gachibowli Main Campus only; never the Early Years Campus, never School-wide.'],
        ['School Admin — Greenfield (standalone, 1 Campus)', 'greenfield.admin@example.test', 'No School Group; core modules only, so optional Setup areas show "Not enabled".'],
        ['School Admin — Horizon Academy (standalone, 3 Campuses)', 'horizon.admin@example.test', 'No School Group; three Campuses with different services.'],
        ['Network Manager — Northstar', 'network.manager@example.test', 'Whole Network: Regions, Zones, classification, placement, removal and read-only viewers (enroll MFA first for classification, removal and viewers); cannot add or enter a School.'],
        ['Network Viewer — whole Network', 'network.viewer@example.test', 'Read-only: all six Northstar Schools, including the unclassified one.'],
        ['Network Viewer — Region: Telangana', 'network.region@example.test', 'Read-only: Telangana Schools only (Hyderabad Central, Hyderabad West, Warangal); never Karnataka.'],
        ['Network Viewer — Zone: Hyderabad Central', 'network.zone@example.test', 'Read-only: Jubilee Hills and Secunderabad only.'],
        ['Network + School — shared identity', 'network.shared@example.test', 'Karnataka Network Viewer AND Principal of Northstar Gachibowli; no role in any other School.'],
        // ORG.12F (ADR 0080 §11): a franchise School that keeps its own brand.
        ['School Admin — Northstar Jubilee Hills (franchise, own brand)', 'jubilee.admin@example.test', 'Keeps its own brand (the Network brand is never forced); core modules only, so its saved Library preference never shows Library.'],
    ];

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
            ['Platform Admin', 'platform.admin@example.test', 'Platform scope: the School Registry and lifecycle, elevated School entry (opens no School page), School Groups, platform audit log (needs MFA), Platform Manager and Auditor grants; no School membership.'],
            ['Platform Manager', 'platform.manager@example.test', 'Platform scope, onboarding: the School Registry and Groups (read), creating a School in provisioning with its bootstrap administrator (enroll MFA first); cannot activate, suspend or close a School.'],
            ['Platform Auditor', 'platform.auditor@example.test', 'Platform scope, read-only: the School Registry, School Groups and the platform audit log (enroll MFA first); changes nothing.'],
            ['Group Admin', 'group.admin@example.test', 'Group scope only: views the Lycenza Demo Trust and its two Schools, enters one via elevated access (MFA required); no School permission.'],
            ['Teacher', 'teacher@example.test', 'Teacher role + Staff Self-Service role, linked to an Employee: My Curriculum Delivery, My Attendance, My Learning Content and My Assignments for the one class she is assigned (G8-A Mathematics); My Leave, My Staff Attendance and My Payslips for herself; the School calendar (whole-School and Staff Events, ORG.9D-3R1); the Communication Hub inbox with only what she received (ORG.9D-1R1, no announcement list or administration); every other module is 403.'],
            ['Student', 'student@example.test', 'Linked Student account: no student portal exists; modules are 403.'],
            ['Guardian', 'guardian01@example.test', 'Activated Guardian account: the Guardian portal inbox, her child\'s attendance and fees, and replies in conversations the School starts with her (POR.1–POR.4, development only; all but the inbox need MFA); every staff module is 403.'],
        ];

        $operationsHints = [
            'finance' => 'Accountant: ledger posting, fee setup and runs, charges, payments, offline payment recording, outstanding fees, moving a payment to the right charge, and concession requests; payment reversals and refunds, ledger reversals and approvals stay with School Admin (Home > Finance).',
            'library' => 'Librarian: catalogue, circulation (check out, check in) and fines (view), linked from Home (Library: Catalogue, Circulation, Fine policy).',
            'transport' => 'Transport Coordinator: routes, vehicles, daily operations, Student assignments and transport fees, linked from Home (Transport: …).',
            'reception' => 'Front Office: visitor directory and check-in/out, linked from Home (Visitors: Visitors, Visits).',
            'hostel' => 'Hostel Warden: hostels, rooms, beds, residency and hostel fees, linked from Home (Hostel: …).',
            'canteen_stores' => 'Canteen Operator + Stores Officer (two roles): canteen outlets, items and orders plus inventory items, locations and stock, all linked from Home; canteen settings stay with School Admin.',
            'communications' => 'Communications Coordinator: Communication Hub messages, announcements, templates and Guardian conversations; approval stays with the Principal or School Admin.',
        ];

        $shortcuts = [];

        foreach ($people as [$persona, $email, $hint]) {
            $shortcuts[] = ['persona' => $persona, 'email' => $email, 'hint' => $hint, 'group' => self::GROUP_PEOPLE];
        }

        foreach (self::OPERATIONS_DESKS as $key => $desk) {
            $shortcuts[] = ['persona' => $desk['persona'], 'email' => $desk['email'], 'hint' => $operationsHints[$key], 'group' => self::GROUP_OPERATIONS];
        }

        foreach (self::ORGANIZATION as [$persona, $email, $hint]) {
            $shortcuts[] = ['persona' => $persona, 'email' => $email, 'hint' => $hint, 'group' => self::GROUP_ORGANIZATION];
        }

        return $shortcuts;
    }
}
