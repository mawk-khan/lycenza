<?php

namespace Tests\Feature\LMS;

use App\Domain\Documents\Infrastructure\Document;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\LMS\Infrastructure\Assignment;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Domain\TeachingAssignments\Infrastructure\TeachingAssignment;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesLmsOwnershipFixtures;
use Tests\Concerns\CreatesTeacherDeliveryFixtures;
use Tests\Concerns\CreatesTeacherLearningContentFixtures;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * TCH.5D (ADR 0063 sections 20, 37): an owned teacher Assignment write -- an
 * edit, a multi-Section creation, an attachment -- racing everything
 * that removes the teacher's authority: a TeachingAssignment ending (one of
 * several, too), the membership suspended, the Employee unlinked or archived,
 * the employment ended. Real OS processes with forced, observed overlap.
 *
 * Both orders serialize: the write first commits and the change waits; the
 * change first commits and the write is refused. A last race proves the
 * audience lock order is the sorted Section order, not the client's.
 */
class TeacherAssignmentConcurrencyTest extends TestCase
{
    use CreatesLmsOwnershipFixtures, CreatesTeacherDeliveryFixtures, CreatesTeacherLearningContentFixtures, CreatesTeachingAssignmentFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

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
        $admin = DB::connection('pgsql_admin');

        foreach ($this->schoolIds as $schoolId) {
            // The whole tenant directory, not just the files: a run as root
            // (the isolated container) must leave nothing behind on the
            // bind-mounted storage.
            Storage::disk('local')->deleteDirectory("schools/{$schoolId}");
            $admin->table('documents')->where('school_id', $schoolId)->delete();
            $admin->table('teaching_assignments')->where('school_id', $schoolId)->delete();
            $this->deleteSchoolAsAdmin($schoolId);
        }

        $admin->table('roles')->where('key', 'like', 'test.capability_grant.%')->where('created_at', '>=', $this->startedAt)->delete();
        $admin->table('users')->where('created_at', '>=', $this->startedAt)->whereNotIn('id', $admin->table('platform_role_assignments')->select('user_id'))->delete();

        parent::tearDown();
    }

    private function script(string $file, string ...$args): array
    {
        return ['php', __DIR__."/../../Support/{$file}", ...$args];
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $w = $this->contentWorld();
        $this->schoolIds[] = $w['school']->id;

        return $w;
    }

    private function op(array $w, string $op, User $user, string ...$args): array
    {
        return $this->script('teacher-assignment-op.php', $op, $w['school']->id, $user->id, ...$args);
    }

    private function end(array $w, TeachingAssignment $assignment): array
    {
        return $this->script('teaching-assignment-op.php', 'end', $w['school']->id, $w['admin']->id, $assignment->id, '2026-08-31', 'reassigned');
    }

    private function title(array $w, Assignment $assignment): string
    {
        return app(TenantContext::class)->withSchool($w['school'], fn () => $assignment->fresh()->title);
    }

    private function documents(array $w): int
    {
        return app(TenantContext::class)->withSchool($w['school'], fn () => Document::query()->count());
    }

    #[Test]
    public function an_edit_racing_the_teaching_assignment_end_serializes_both_ways(): void
    {
        $w = $this->world();

        // Edit first: the end waits on the held assignment row.
        [$user, , , [$a]] = $this->contentTeacher($w);
        $row = $this->teacherAssignment($w, $user);
        [$holder, $contender] = $this->raceWithHeldHolder($this->op($w, 'update', $user, $row->id), $this->end($w, $a));
        $this->assertSame(['updated', 'ended'], [$holder, $contender]);

        // End first: the edit waits on the assignment row, then finds no
        // coverage under its lock.
        [$user2, , , [$a2]] = $this->contentTeacher($w);
        $row2 = $this->teacherAssignment($w, $user2);
        [$holder, $contender] = $this->raceWithHeldHolder($this->end($w, $a2), $this->op($w, 'update', $user2, $row2->id));
        $this->assertSame(['ended', 'rejected:ASSIGNMENT_OUTSIDE_TEACHING_ASSIGNMENT'], [$holder, $contender]);
        $this->assertSame('My worksheet', $this->title($w, $row2));
    }

    #[Test]
    public function ending_one_of_several_audience_teaching_assignments_serializes_both_ways(): void
    {
        $w = $this->world();

        [$user, , , [, $b]] = $this->contentTeacher($w, ['sectionA', 'sectionB']);
        $row = $this->teacherAssignment($w, $user, ['sectionA', 'sectionB'], 'published');
        [$holder, $contender] = $this->raceWithHeldHolder($this->op($w, 'update', $user, $row->id), $this->end($w, $b));
        $this->assertSame(['updated', 'ended'], [$holder, $contender]);

        [$user2, , , [, $b2]] = $this->contentTeacher($w, ['sectionA', 'sectionB']);
        $row2 = $this->teacherAssignment($w, $user2, ['sectionA', 'sectionB'], 'published');
        [$holder, $contender] = $this->raceWithHeldHolder($this->end($w, $b2), $this->op($w, 'update', $user2, $row2->id));
        $this->assertSame(['ended', 'rejected:ASSIGNMENT_OUTSIDE_TEACHING_ASSIGNMENT'], [$holder, $contender]);
        $this->assertSame('My worksheet', $this->title($w, $row2));
    }

    #[Test]
    public function an_attachment_write_racing_the_teaching_assignment_end_serializes_both_ways(): void
    {
        $w = $this->world();

        [$user, , , [$a]] = $this->contentTeacher($w);
        $row = $this->teacherAssignment($w, $user);
        [$holder, $contender] = $this->raceWithHeldHolder($this->op($w, 'attach', $user, $row->id), $this->end($w, $a));
        $this->assertStringStartsWith('attached:', $holder);
        $this->assertSame('ended', $contender);

        [$user2, , , [$a2]] = $this->contentTeacher($w);
        $row2 = $this->teacherAssignment($w, $user2);
        [$holder, $contender] = $this->raceWithHeldHolder($this->end($w, $a2), $this->op($w, 'attach', $user2, $row2->id));
        $this->assertSame(['ended', 'rejected:ASSIGNMENT_OUTSIDE_TEACHING_ASSIGNMENT'], [$holder, $contender]);
        $this->assertSame(1, $this->documents($w), 'The refused attachment left no Document row.');
    }

    #[Test]
    public function a_creation_racing_suspension_unlink_archive_or_employment_end_serializes_both_ways(): void
    {
        $w = $this->world();
        $hr = $this->fullHrActor($w['school']);
        $staffAdmin = $this->createUser();
        $this->assignSchoolRole($this->createMembership($staffAdmin, $w['school']), 'school_admin');
        $this->assignSchoolRole($this->createMembership($this->createUser(), $w['school']), 'school_admin');
        $cases = [
            'suspend' => [fn (Employee $e, $m) => ['staff-account-op.php', 'suspend', $w['school']->id, $staffAdmin->id, $m->id], 'suspended', 'denied:membership_not_active'],
            'unlink' => [fn (Employee $e, $m) => ['acting-employee-op.php', 'unlink', $w['school']->id, $hr->id, $e->id], 'unlinked', 'denied:not_linked'],
            'archive' => [fn (Employee $e, $m) => ['acting-employee-op.php', 'archive', $w['school']->id, $hr->id, $e->id], 'archived', 'denied:employee_not_active'],
            'employment end' => [
                fn (Employee $e, $m) => ['acting-employee-op.php', 'end-employment', $w['school']->id, $hr->id,
                    app(TenantContext::class)->withSchool($w['school'], fn () => $e->employmentRecords()->firstOrFail()->id), '2026-08-31'],
                'ended',
                'denied:no_eligible_employment',
            ],
        ];
        $create = fn (User $u) => $this->op($w, 'create', $u, $w['offering']->id, $w['sectionA']->id);

        foreach ($cases as $label => [$change, $changed, $denied]) {
            [$user, $employee, $membership] = $this->contentTeacher($w);
            [$holder, $contender] = $this->raceWithHeldHolder($create($user), $this->script(...$change($employee, $membership)));
            $this->assertStringStartsWith('created:', $holder, $label);
            $this->assertSame($changed, $contender, $label);

            [$user2, $employee2, $membership2] = $this->contentTeacher($w);
            $before = app(TenantContext::class)->withSchool($w['school'], fn () => Assignment::query()->count());
            [$holder, $contender] = $this->raceWithHeldHolder($this->script(...$change($employee2, $membership2)), $create($user2));
            $this->assertSame([$changed, $denied], [$holder, $contender], $label);
            $this->assertSame($before, app(TenantContext::class)->withSchool($w['school'], fn () => Assignment::query()->count()), $label);
        }
    }

    #[Test]
    public function audience_ownership_is_locked_in_section_order_whatever_the_client_order(): void
    {
        $w = $this->world();
        [$user, , , $assignments] = $this->contentTeacher($w, ['sectionA', 'sectionB']);
        $bySection = collect($assignments)->keyBy('section_id');
        $sections = $bySection->keys()->sort()->values()->all();
        [$lower, $higher] = [$bySection[$sections[0]], $bySection[$sections[1]]];

        // Holder: the LOWER Section's assignment is being ended (row locked).
        // Contender: a creation naming the Sections in DESCENDING order. If it
        // followed the client order it would already hold the HIGHER
        // assignment while waiting; it must be waiting on the lower one first.
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->end($w, $lower),
            $this->op($w, 'create', $user, $w['offering']->id, "{$sections[1]},{$sections[0]}"),
            function () use ($w, $higher) {
                // So ending the HIGHER assignment right now must not block.
                DB::statement("SET lock_timeout = '5s'");
                try {
                    app(TeachingAssignmentService::class)->end($w['school'], $higher->id, '2026-08-31', 'reassigned', $w['admin']);
                } finally {
                    DB::statement('SET lock_timeout = 0');
                }
            },
        );

        $this->assertSame(['ended', 'rejected:LMS_AUDIENCE_SECTION_NOT_TAUGHT'], [$holder, $contender]);
        $this->assertTrue(app(TenantContext::class)->withSchool($w['school'], fn () => TeachingAssignment::query()->whereKey($higher->id)->value('ended_at') !== null));
        $this->assertSame(0, app(TenantContext::class)->withSchool($w['school'], fn () => Assignment::query()->count()));
    }
}
