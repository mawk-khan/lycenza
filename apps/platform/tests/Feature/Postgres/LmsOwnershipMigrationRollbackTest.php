<?php

namespace Tests\Feature\Postgres;

use App\Domain\LMS\Application\AssignmentService;
use App\Domain\LMS\Application\LearningContentService;
use App\Domain\LMS\Application\Ownership\SectionAudience;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesLmsOwnershipFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * TCH.5B (ADR 0063 section 34.9): the ownership migration's down() refuses
 * while any teacher-owned row or audience row exists -- dropping them would
 * turn Section-targeted teacher material into Offering-wide admin material
 * -- and, with none, removes the whole foundation and re-applies cleanly.
 *
 * Both are run on the admin connection inside ONE transaction that is
 * always rolled back, so the real schema is never changed. Non-transactional
 * on the runtime connection: the owned data must be COMMITTED for down() to
 * see it, and the admin DDL takes ACCESS EXCLUSIVE locks.
 */
class LmsOwnershipMigrationRollbackTest extends TestCase
{
    use CreatesLmsOwnershipFixtures, CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $schoolIds = [];

    private ?string $startedAt = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startedAt = now()->subSecond()->toDateTimeString();
    }

    protected function tearDown(): void
    {
        foreach ($this->schoolIds as $schoolId) {
            $this->deleteSchoolAsAdmin($schoolId);
        }

        $admin = DB::connection('pgsql_admin');
        $admin->table('roles')->where('key', 'like', 'test.capability_grant.%')->where('created_at', '>=', $this->startedAt)->delete();
        $admin->table('users')->where('created_at', '>=', $this->startedAt)->whereNotIn('id', $admin->table('platform_role_assignments')->select('user_id'))->delete();

        parent::tearDown();
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_05_090000_add_lms_ownership_and_section_audiences.php');
    }

    /** Runs $callback with the migration connection as default, inside a transaction that is always rolled back. */
    private function onAdminAndRollBack(callable $callback): void
    {
        $admin = DB::connection('pgsql_admin');
        $default = DB::getDefaultConnection();
        $admin->beginTransaction();
        DB::setDefaultConnection('pgsql_admin');

        try {
            $callback();
        } finally {
            DB::setDefaultConnection($default);
            $admin->rollBack();
        }
    }

    /** @return list<string> */
    private function foundation(): array
    {
        return collect(DB::connection('pgsql_admin')->select(
            "select table_name || '.' || column_name as n from information_schema.columns where column_name in ('owner_employee_id', 'ownership_txid') "
            ."union select tablename from pg_tables where tablename in ('learning_content_section_audiences', 'assignment_section_audiences') "
            ."union select proname from pg_proc where proname like 'lms\\_%' order by 1",
        ))->pluck('n')->all();
    }

    #[Test]
    #[DataProvider('lmsResources')]
    public function rollback_refuses_while_a_teacher_owned_row_exists(string $table): void
    {
        $w = $this->ownershipWorld();
        $this->schoolIds[] = $w['school']->id;
        $admin = $this->createUserWithCapabilities($w['school'], ['lms.content.manage', 'lms.assignments.manage']);
        $audience = new SectionAudience($w['employee']->id, [$w['sectionA']->id]);
        $owned = $table === 'learning_content'
            ? app(LearningContentService::class)->create($w['school'], $w['offering']->id, ['title' => 'Reading'], $admin, $audience)
            : app(AssignmentService::class)->create($w['school'], $w['offering']->id, ['title' => 'Worksheet'], $admin, $audience);
        $before = $this->foundation();

        $this->onAdminAndRollBack(function () use ($table) {
            try {
                $this->migration()->down();
                $this->fail('down() dropped teacher ownership.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('tch5b rollback: refusing', $e->getMessage());
                $this->assertStringContainsString($table, $e->getMessage());
            }
        });

        $this->assertSame($before, $this->foundation(), 'The schema is untouched.');
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $w['school']->id]);
        $this->assertSame($w['employee']->id, DB::connection('pgsql')->table($table)->where('id', $owned->id)->value('owner_employee_id'));
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, '']);
    }

    #[Test]
    public function with_no_teacher_ownership_rollback_removes_the_foundation_and_reapplies(): void
    {
        $w = $this->ownershipWorld();
        $this->schoolIds[] = $w['school']->id;
        // Offering-wide rows are what the rollback must preserve.
        $admin = $this->createUserWithCapabilities($w['school'], ['lms.content.manage']);
        $legacy = app(LearningContentService::class)->create($w['school'], $w['offering']->id, ['title' => 'Legacy'], $admin);
        $applied = $this->foundation();
        $this->assertCount(13, $applied, 'Four columns, two audience tables and seven trigger functions.');

        $this->onAdminAndRollBack(function () use ($legacy, $applied) {
            $this->migration()->down();
            $this->assertSame([], $this->foundation(), 'down() removed every column, table and function.');
            DB::connection('pgsql_admin')->select('select set_config(?, ?, true)', [TenantRls::SESSION_VAR, $legacy->school_id]);
            $this->assertSame(1, DB::connection('pgsql_admin')->table('learning_content')->where('id', $legacy->id)->count(), 'Offering-wide rows survive.');

            $this->migration()->up();
            $this->assertSame($applied, $this->foundation(), 'up() re-applies the same foundation.');
        });

        $this->assertSame($applied, $this->foundation());
    }
}
