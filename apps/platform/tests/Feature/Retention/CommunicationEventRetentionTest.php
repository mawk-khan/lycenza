<?php

namespace Tests\Feature\Retention;

use App\Domain\Communications\Application\Retention\CommunicationResidualRetentionService;
use App\Domain\Communications\Application\Retention\CommunicationRetentionService;
use App\Models\School;
use App\Support\Retention\RetentionHolds;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AlignsRetentionAnchors;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Communications\Concerns\CreatesCommunicationEventFixtures;
use Tests\TestCase;

/**
 * ORG.9D-3 (ADR 0076 §75.12; E21-D3): School calendar Events expire through
 * their database functions, as the retention identity: a published Event
 * (cancelled later or not) 3 years after the end of the ONE Academic Year
 * containing its School-local last day; a never-published cancelled Event
 * 1 year after its cancellation. A published Event in no Academic Year is
 * unresolved and kept; drafts are working state; a held School deletes
 * nothing. COMMITTED fixtures (the retention session must see them).
 */
class CommunicationEventRetentionTest extends TestCase
{
    use AlignsRetentionAnchors, CommitsRetentionFixtures, CreatesCommunicationEventFixtures, CreatesTenancyFixtures;

    private string $anchorsFrom = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['retention.hold_school_ids' => [], 'retention.hold_platform' => false]);
        $this->anchorsFrom = $this->retentionAnchorCheckpoint();
    }

    private function eventCount(School $school, ?string $title = null): int
    {
        return app(TenantContext::class)->withSchool($school, fn () => DB::table('communication_events')->when($title, fn ($q) => $q->where('title', $title))->count());
    }

    #[Test]
    public function published_events_expire_with_their_academic_year_and_never_published_cancelled_ones_after_a_year(): void
    {
        $this->travelTo(CarbonImmutable::parse('2019-06-01T06:00:00Z'));
        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->createAcademicYear($school, ['status' => 'closed', 'starts_on' => '2019-04-01', 'ends_on' => '2020-03-31', 'code' => 'AY19']);
        $preparer = $this->createUserWithCapabilities($school, ['communications.announce']);
        $approver = $this->createUserWithCapabilities($school, ['communications.approve']);

        $this->publishedEvent($school, $preparer, $approver, ['title' => 'Old published', 'starts_on' => '2019-12-10', 'ends_on' => '2019-12-10']);
        $cancelledAfter = $this->publishedEvent($school, $preparer, $approver, ['title' => 'Old cancelled after publishing', 'starts_on' => '2019-12-11', 'ends_on' => '2019-12-11']);
        $this->events()->cancel($cancelledAfter, $preparer);
        $this->publishedEvent($school, $preparer, $approver, ['title' => 'In no year', 'starts_on' => '2021-05-01', 'ends_on' => '2021-05-01']);
        $this->events()->cancel($this->draftEvent($school, $preparer, ['title' => 'Never published', 'starts_on' => '2019-12-12', 'ends_on' => '2019-12-12']), $preparer);
        $this->draftEvent($school, $preparer, ['title' => 'Old draft', 'starts_on' => '2019-12-13', 'ends_on' => '2019-12-13']);
        $this->alignRetentionAnchorsSince($this->anchorsFrom);

        $this->travelBack();
        $content = app(CommunicationRetentionService::class)->pruneContent($school, '2023-04-01', 100, false); // year ended 2020-03-31 < cutoff
        $this->assertSame(2, $content['deleted'], 'Both published Events of the closed year (one later cancelled).');
        $this->assertSame(0, $this->eventCount($school, 'Old published'));
        $this->assertSame(0, $this->eventCount($school, 'Old cancelled after publishing'));
        $this->assertSame(1, $this->eventCount($school, 'In no year'), 'Unresolved (its last day is in no Academic Year): kept.');
        $this->assertSame(0, (int) DB::connection('pgsql_admin')->table('communication_approval_requests')->whereIn('event_id', [$cancelledAfter->id])->count(), 'Its approval history goes with it.');

        $residual = app(CommunicationResidualRetentionService::class)->pruneNeverSent($school, now()->subYear()->subDay(), 100, false, false);
        $this->assertGreaterThanOrEqual(1, $residual['deleted']);
        $this->assertSame(0, $this->eventCount($school, 'Never published'));
        $this->assertSame(1, $this->eventCount($school, 'Old draft'), 'A draft is working state: never expired.');
    }

    #[Test]
    public function a_held_school_deletes_no_event_and_the_runtime_role_cannot_delete_one(): void
    {
        $this->travelTo(CarbonImmutable::parse('2019-06-01T06:00:00Z'));
        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->createAcademicYear($school, ['status' => 'closed', 'starts_on' => '2019-04-01', 'ends_on' => '2020-03-31', 'code' => 'AY19']);
        $preparer = $this->createUserWithCapabilities($school, ['communications.announce']);
        $event = $this->publishedEvent($school, $preparer, $this->createUserWithCapabilities($school, ['communications.approve']), ['starts_on' => '2019-12-10', 'ends_on' => '2019-12-10']);
        $this->alignRetentionAnchorsSince($this->anchorsFrom);
        $this->travelBack();

        $this->assertThrows(fn () => app(TenantContext::class)->withSchool($school, fn () => DB::table('communication_events')->where('id', $event->id)->delete()), QueryException::class);

        app(RetentionHolds::class)->place($school->id, 'litigation', 'ORG9D3-TEST');
        $result = app(CommunicationRetentionService::class)->pruneContent($school, '2023-04-01', 100, false);
        $this->assertSame(0, $result['deleted']);
        $this->assertSame(1, $this->eventCount($school));
        app(RetentionHolds::class)->release($school->id, 'matter_concluded', 'ORG9D3-TEST');
    }
}
