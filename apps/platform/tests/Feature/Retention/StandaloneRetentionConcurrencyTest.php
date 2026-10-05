<?php

namespace Tests\Feature\Retention;

use App\Models\School;
use App\Support\Retention\RetentionHolds;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * E21-RH.4: a standalone bulk expiry (School audit) now running as the
 * retention identity on its own connection, racing a School-hold placement,
 * in two real OS processes with an observed lock wait (COMMITTED fixtures).
 * The destructive call takes the hold lock SHARED until it commits; a
 * placement takes it EXCLUSIVELY. So a placement in flight makes the
 * waiting expiry refuse (the run reports the rows held), and an expiry
 * already past the check finishes before the placement lands. Neither side
 * self-deadlocks or crosses connections.
 */
class StandaloneRetentionConcurrencyTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    protected function setUp(): void
    {
        parent::setUp();
        config(['retention.audit_years' => 7, 'retention.hold_school_ids' => [], 'retention.hold_platform' => false]);
    }

    /** @return array{school: School, row: string} */
    private function world(): array
    {
        $school = $this->createSchool();
        $row = (string) Str::uuid7();
        app(TenantContext::class)->withSchool($school, fn () => DB::table('school_audit_events')->insert([
            'id' => $row, 'school_id' => $school->id, 'occurred_at' => '2012-01-01 00:00:00', 'event_type' => 'test.event', 'metadata' => '{}', 'created_at' => '2012-01-01 00:00:00',
        ]));

        return compact('school', 'row');
    }

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/hrx-retention-op.php', ...$args];
    }

    private function auditRows(string $id): int
    {
        return DB::connection(RetentionHolds::MAINTENANCE_CONNECTION)->table('school_audit_events')->where('id', $id)->count();
    }

    #[Test]
    public function a_hold_placed_concurrently_makes_the_waiting_expiry_report_held(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('place-school', $w['school']->id),
            $this->script('audit-prune', $w['school']->id),
        );

        $this->assertSame('placed:created', $holder);
        $this->assertSame('audit:deleted:0 held:1', $contender, 'the destructive call waited on the hold lock, then refused');
        $this->assertSame(1, $this->auditRows($w['row']));
    }

    #[Test]
    public function an_expiry_already_past_the_check_finishes_before_the_placement_lands(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('audit-prune', $w['school']->id),
            $this->script('place-school', $w['school']->id),
        );

        $this->assertSame('audit:deleted:1 held:0', $holder);
        $this->assertSame('placed:created', $contender, 'the placement waited for the in-flight expiry');
        $this->assertSame(0, $this->auditRows($w['row']));
    }
}
