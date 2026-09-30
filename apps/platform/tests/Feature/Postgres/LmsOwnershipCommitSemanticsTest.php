<?php

namespace Tests\Feature\Postgres;

use App\Domain\LMS\Application\AssignmentService;
use App\Domain\LMS\Application\LearningContentService;
use App\Domain\LMS\Application\Ownership\LmsResourceOwnershipReader;
use App\Domain\LMS\Application\Ownership\SectionAudience;
use App\Domain\LMS\Application\Ownership\SectionAudienceWriter;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesLmsOwnershipFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * TCH.5B (ADR 0063 section 35) -- the invariants that only a REAL commit
 * or a SEPARATE later transaction can show, for both resources:
 *
 * - an owned row with no audience fails at COMMIT and leaves nothing;
 * - once committed, an owned row's audience can never grow (a later
 *   transaction's insert is refused, even with a spoofed txid);
 * - the audience-update trigger binds the migration role too;
 * - a teacher-owned row created through the real LMS service commits, reads
 *   back as teacher-owned, and still follows every ordinary lifecycle rule.
 *
 * Non-transactional: every School is removed through the admin connection.
 */
class LmsOwnershipCommitSemanticsTest extends TestCase
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
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, '']);
        foreach ($this->schoolIds as $schoolId) {
            $this->deleteSchoolAsAdmin($schoolId);
        }

        $admin = DB::connection('pgsql_admin');
        $admin->table('roles')->where('key', 'like', 'test.capability_grant.%')->where('created_at', '>=', $this->startedAt)->delete();
        $admin->table('users')->where('created_at', '>=', $this->startedAt)->whereNotIn('id', $admin->table('platform_role_assignments')->select('user_id'))->delete();

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $w = $this->ownershipWorld();
        $this->schoolIds[] = $w['school']->id;
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $w['school']->id]);

        return $w;
    }

    private function insertParent(string $table, array $w, ?string $ownerId): string
    {
        $id = (string) new UuidV7;
        DB::connection('pgsql')->table($table)->insert(array_merge([
            'id' => $id, 'school_id' => $w['school']->id, 'subject_offering_id' => $w['offering']->id,
            'title' => 'Resource', 'status' => 'draft', 'owner_employee_id' => $ownerId,
            'created_at' => now(), 'updated_at' => now(),
        ], $this->lmsResource($table)['columns']));

        return $id;
    }

    private function insertAudience(string $table, array $w, string $parentId, $section): void
    {
        $r = $this->lmsResource($table);
        DB::connection('pgsql')->table($r['bridge'])->insert([
            'id' => (string) new UuidV7, 'school_id' => $w['school']->id, $r['fk'] => $parentId,
            'subject_offering_id' => $w['offering']->id, 'academic_year_id' => $section->academic_year_id,
            'campus_id' => $section->campus_id, 'grade_level_id' => $section->grade_level_id,
            'section_id' => $section->id, 'created_at' => now(),
        ]);
    }

    private function assertRefused(string $needle, callable $op, string $message, string $connection = 'pgsql'): void
    {
        try {
            DB::connection($connection)->transaction($op);
            $this->fail($message);
        } catch (QueryException|PDOException $e) {
            // A deferred check fails inside COMMIT, which Laravel does not
            // wrap: that surfaces as the raw PDOException.
            $this->assertStringContainsString($needle, $e->getMessage(), $message);
        }
    }

    #[Test]
    #[DataProvider('lmsResources')]
    public function an_owned_row_without_an_audience_fails_at_commit_and_leaves_nothing(string $table): void
    {
        $w = $this->world();
        $id = (string) new UuidV7;

        $this->assertRefused('needs at least one Section audience', function () use ($table, $w, &$id) {
            $id = $this->insertParent($table, $w, $w['employee']->id);
        }, 'The deferred check refuses the commit.');

        $this->assertSame(0, DB::connection('pgsql')->table($table)->where('id', $id)->count());
    }

    #[Test]
    #[DataProvider('lmsResources')]
    public function a_committed_audience_can_never_grow_or_be_rewritten(string $table): void
    {
        $w = $this->world();
        $r = $this->lmsResource($table);
        $owned = DB::connection('pgsql')->transaction(function () use ($table, $w) {
            $id = $this->insertParent($table, $w, $w['employee']->id);
            $this->insertAudience($table, $w, $id, $w['sectionA']);

            return $id;
        });

        $this->assertRefused('immutable after its creating transaction',
            fn () => $this->insertAudience($table, $w, $owned, $w['sectionB']), 'A later transaction cannot add a Section.');

        // Neither can a later transaction that also touches the parent row.
        $this->assertRefused('immutable after its creating transaction', function () use ($table, $w, $owned) {
            DB::connection('pgsql')->table($table)->where('id', $owned)->update(['title' => 'Touched']);
            $this->insertAudience($table, $w, $owned, $w['sectionB']);
        }, 'Updating the parent does not reopen its audience.');

        // The update trigger binds the migration role as well; only a
        // School's cascade removes audience rows.
        $this->assertRefused('Section audience is immutable', fn () => DB::connection('pgsql_admin')->table($r['bridge'])
            ->where($r['fk'], $owned)->update(['section_id' => $w['sectionB']->id]), 'admin repoint', 'pgsql_admin');

        $this->assertSame([$w['sectionA']->id], DB::connection('pgsql')->table($r['bridge'])->where($r['fk'], $owned)->pluck('section_id')->all());
    }

    #[Test]
    public function a_teacher_owned_row_created_through_the_lms_services_commits_and_keeps_its_lifecycle(): void
    {
        $w = $this->world();
        $admin = $this->createUserWithCapabilities($w['school'], ['lms.content.view', 'lms.content.manage', 'lms.assignments.view', 'lms.assignments.manage']);
        $reader = app(LmsResourceOwnershipReader::class);
        $audience = new SectionAudience($w['employee']->id, [$w['sectionA']->id, $w['sectionB']->id]);

        $contentService = app(LearningContentService::class);
        $content = $contentService->create($w['school'], $w['offering']->id, ['title' => 'Reading'], $admin, $audience);
        $contentService->update($w['school'], $content, ['title' => 'Reading, revised'], $admin);
        $contentService->publish($w['school'], $content, $admin);
        $contentService->archive($w['school'], $content, $admin);
        $contentService->publish($w['school'], $content, $admin);

        $assignmentService = app(AssignmentService::class);
        $assignment = $assignmentService->create($w['school'], $w['offering']->id, ['title' => 'Worksheet', 'due_on' => '2026-09-30'], $admin, $audience);
        $assignmentService->publish($w['school'], $assignment, $admin);
        $assignmentService->close($w['school'], $assignment, $admin);
        $assignmentService->publish($w['school'], $assignment, $admin);

        foreach ([$reader->forLearningContent($w['school'], $content->id), $reader->forAssignment($w['school'], $assignment->id)] as $ownership) {
            $this->assertTrue($ownership->isEmployeeOwned());
            $this->assertSame($w['employee']->id, $ownership->ownerEmployeeId);
            $this->assertEqualsCanonicalizing([$w['sectionA']->id, $w['sectionB']->id], $ownership->audienceSectionIds);
        }

        $this->withinSchool($w['school'], function () use ($content, $assignment) {
            $this->assertSame('published', DB::connection('pgsql')->table('learning_content')->where('id', $content->id)->value('status'));
            $this->assertSame('published', DB::connection('pgsql')->table('assignments')->where('id', $assignment->id)->value('status'));
        });
    }

    #[Test]
    public function the_audience_writer_refuses_to_run_outside_a_transaction(): void
    {
        $w = $this->world();
        $admin = $this->createUserWithCapabilities($w['school'], ['lms.content.view', 'lms.content.manage']);
        $content = app(LearningContentService::class)->create($w['school'], $w['offering']->id, ['title' => 'Reading'], $admin);

        $this->assertSame(0, DB::transactionLevel());
        $this->expectException(LogicException::class);
        app(SectionAudienceWriter::class)->attach($content, $w['offering'], new SectionAudience($w['employee']->id, [$w['sectionA']->id]));
    }
}
