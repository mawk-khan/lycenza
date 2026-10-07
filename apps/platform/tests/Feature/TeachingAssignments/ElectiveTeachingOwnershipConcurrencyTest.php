<?php

namespace Tests\Feature\TeachingAssignments;

use App\Domain\TeachingAssignments\Infrastructure\ElectiveTeachingAssignment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * TCH-E (ADR 0063 section 45): elective teaching ownership between real OS
 * processes against real PostgreSQL, overlap forced and verified
 * (ForcesConcurrentOverlap).
 *
 * - Two overlapping creates of one key: exactly one (the key's advisory lock).
 * - A lock-capable ownership check vs an end: the end waits for the holder
 *   that relied on the ownership; a check that waited for an end sees the
 *   shortened period. No check-then-use window either way.
 */
class ElectiveTeachingOwnershipConcurrencyTest extends TestCase
{
    use CreatesTeachingAssignmentFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

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
            // History rows are undeletable by the runtime role; the admin removes them before the School.
            $admin->table('elective_teaching_assignments')->where('school_id', $schoolId)->delete();
            $this->deleteSchoolAsAdmin($schoolId);
        }
        $admin->table('roles')->where('key', 'like', 'test.capability_grant.%')->where('created_at', '>=', $this->startedAt)->delete();
        $admin->table('users')->where('created_at', '>=', $this->startedAt)->whereNotIn('id', $admin->table('platform_role_assignments')->select('user_id'))->delete();

        parent::tearDown();
    }

    /** @return list<string> */
    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/elective-teaching-ownership-op.php', ...$args];
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $w = $this->teachingWorld();
        $this->schoolIds[] = $w['school']->id;
        $w['elective'] = $this->electiveOffering($w);

        return $w;
    }

    /** @param  array<string, mixed>  $w @return list<string> */
    private function hold(array $w, string $date): array
    {
        return $this->script('hold', $w['school']->id, $w['employee']->id, $w['elective']->id, $date);
    }

    #[Test]
    public function two_concurrent_overlapping_creates_produce_exactly_one_assignment(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('create', $w['school']->id, $w['admin']->id, $w['employee']->id, $w['elective']->id, '2026-06-01', '2026-12-31'),
            $this->script('create', $w['school']->id, $w['admin']->id, $w['employee']->id, $w['elective']->id, '2026-09-01'),
        );

        $this->assertStringStartsWith('created:', $holder);
        $this->assertSame('rejected:TEACHING_ASSIGNMENT_OVERLAP', $contender);
        $this->assertSame(1, app(TenantContext::class)->withSchool($w['school'], fn () => ElectiveTeachingAssignment::query()->count()));
    }

    #[Test]
    public function an_end_waits_for_a_holder_that_relied_on_the_ownership(): void
    {
        $w = $this->world();
        $a = $this->assignElective($w, $w['elective'], '2026-06-01');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->hold($w, '2026-10-15'),
            $this->script('end', $w['school']->id, $w['admin']->id, $a->id, '2026-09-30', 'reassigned'),
        );

        $this->assertSame(['owned', 'ended'], [$holder, $contender], 'the end waited on the FOR SHARE, then committed after the holder');
        $this->assertSame('2026-09-30', app(TenantContext::class)->withSchool($w['school'], fn () => $a->fresh()->ends_on->toDateString()));
    }

    #[Test]
    public function a_check_that_waited_for_an_end_sees_the_shortened_period(): void
    {
        $w = $this->world();
        $a = $this->assignElective($w, $w['elective'], '2026-06-01');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('end', $w['school']->id, $w['admin']->id, $a->id, '2026-09-30', 'reassigned'),
            $this->hold($w, '2026-10-15'),
        );

        $this->assertSame(['ended', 'not-owned'], [$holder, $contender], 'the check waited on the end, then found no coverage after 2026-09-30');
    }
}
