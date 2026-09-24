<?php

namespace Tests\Feature\Platform\Elevation;

use App\Domain\Platform\Application\Elevation\ElevationAudit;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * Phase 0N.3 (ADR 0044 sections 4 and 6): two real OS processes start an
 * elevation for the same actor at the same time. Both pass the
 * application pre-check (neither sees the other's uncommitted row); the
 * `school_elevations_one_active_per_actor` partial unique index is what
 * leaves exactly one active elevation, and the loser's refusal is audited.
 * Overlap is forced and verified (ForcesConcurrentOverlap), never slept.
 */
class SchoolElevationConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ElevationTestHelpers, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?User $actor = null;

    /** @var list<School> */
    private array $schools = [];

    protected function tearDown(): void
    {
        // Elevation rows are undeletable for the runtime role (evidence);
        // clean up this test's committed rows as the admin role.
        $admin = DB::connection('pgsql_admin');
        if ($this->actor !== null) {
            $admin->table('school_elevations')->where('actor_user_id', $this->actor->id)->delete();
            $admin->table('platform_audit_events')->where('actor_user_id', $this->actor->id)->delete();
            $admin->table('users')->where('id', $this->actor->id)->delete();
        }
        foreach ($this->schools as $school) {
            $admin->table('schools')->where('id', $school->id)->delete();
        }

        parent::tearDown();
    }

    #[Test]
    public function two_concurrent_starts_leave_exactly_one_active_elevation(): void
    {
        $this->actor = $this->platformAdmin();
        [$codeA, $codeB] = $this->issueRecoveryCodes($this->actor, 2);
        $this->schools = [$this->createSchool(), $this->createSchool()];

        $script = __DIR__.'/../../../Support/start-school-elevation.php';
        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $script, $this->actor->id, $this->schools[0]->id, $codeA],
            ['php', $script, $this->actor->id, $this->schools[1]->id, $codeB],
        );

        $this->assertSame('started', $holder);
        $this->assertSame('rejected:already_elevated', $contender);

        $active = DB::table('school_elevations')->where('actor_user_id', $this->actor->id)->where('status', 'active')->get();
        $this->assertCount(1, $active);
        $this->assertSame($this->schools[0]->id, $active[0]->school_id);
        $this->assertSame(1, DB::table('school_elevations')->where('actor_user_id', $this->actor->id)->count());

        $events = DB::table('platform_audit_events')->where('actor_user_id', $this->actor->id)->pluck('event_type')->sort()->values()->all();
        $this->assertSame([ElevationAudit::DENIED, ElevationAudit::STARTED], $events);
        $denied = DB::table('platform_audit_events')->where('actor_user_id', $this->actor->id)->where('event_type', ElevationAudit::DENIED)->value('metadata');
        $this->assertSame('already_elevated', json_decode($denied, true)['outcome_code']);
    }
}
