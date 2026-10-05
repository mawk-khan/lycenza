<?php

namespace Tests\Feature\Retention;

use App\Domain\Communications\Application\Retention\CommunicationResidualRetentionService;
use App\Domain\Transport\Application\Retention\DriverAssignmentRetentionService;
use App\Domain\Visitor\Application\Retention\VisitorRetentionService;
use App\Models\School;
use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Retention\AutomationExecutionRetention;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionPeriod;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * E21.3E (E21.2G C1/C2/O2/O3/O4/I3, project-adopted, pending legal
 * ratification): the communications and platform residuals.
 *
 * Exact counts are asserted per School through the services (the commands
 * walk every School in the database, committed fixtures of other test
 * classes included); the commands are run for their wiring.
 */
class ResidualRetentionTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures;

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disk = 'local';
        Storage::fake($this->disk);
        config([
            'retention.communications_abandoned_years' => 1, 'retention.driver_assignment_years' => 7, 'retention.visit_years' => 1,
            'retention.automation_years' => 1, 'retention.authority_history_years' => 7, 'retention.hold_school_ids' => [],
        ]);
    }

    private function at(string $moment): void
    {
        $this->travelTo(Carbon::parse($moment, 'UTC'));
    }

    private function in(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    private function rows(School $school, string $table, string $id, string $column = 'id'): int
    {
        return $this->in($school, fn () => DB::table($table)->where($column, $id)->count());
    }

    /** @param  array<string, int>  $expected */
    private function assertCounts(array $expected, array $actual): void
    {
        foreach ($expected as $outcome => $count) {
            $this->assertSame($count, $actual[$outcome], $outcome);
        }
    }

    private function refused(callable $statement, string $needle): void
    {
        try {
            DB::transaction(fn () => $statement());
            $this->fail("expected refusal: {$needle}");
        } catch (QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    // --- Communications ---------------------------------------------------

    /** @param  array<string, mixed>  $attributes */
    private function announcement(School $school, array $attributes): string
    {
        $id = (string) Str::uuid7();
        $this->in($school, fn () => DB::table('communication_announcements')->insert(array_merge([
            'id' => $id, 'school_id' => $school->id, 'created_by_user_id' => $this->createUser()->id, 'title' => 'Notice', 'body' => 'Body',
            'status' => 'draft', 'audience_type' => 'school_wide', 'created_at' => '2001-01-01 00:00:00', 'updated_at' => '2001-01-01 00:00:00',
        ], $attributes)));

        return $id;
    }

    private function approval(School $school, string $announcementId, string $status, ?string $decidedAt, string $requestedAt = '2024-12-01 00:00:00'): void
    {
        $this->in($school, fn () => DB::table('communication_approval_requests')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'announcement_id' => $announcementId, 'requested_by_user_id' => $this->createUser()->id,
            'requested_at' => $requestedAt, 'fingerprint' => str_repeat('a', 64), 'snapshot' => '{}', 'status' => $status,
            'decided_by_user_id' => $decidedAt === null ? null : $this->createUser()->id, 'decided_at' => $decidedAt, 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    private function attachment(School $school, string $announcementId): string
    {
        $path = "schools/{$school->id}/communications/".Str::uuid7().'.pdf';
        Storage::disk($this->disk)->put($path, 'bytes');
        $this->in($school, fn () => DB::table('communication_attachments')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'communication_announcement_id' => $announcementId, 'storage_disk' => $this->disk,
            'storage_path' => $path, 'original_filename' => 'a.pdf', 'safe_display_name' => 'a.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 5,
            'checksum_sha256' => str_repeat('b', 64), 'created_by_user_id' => $this->createUser()->id, 'created_at' => now(), 'updated_at' => now(),
        ]));

        return $path;
    }

    private function thread(School $school, ?string $lastActivityAt): string
    {
        $id = (string) Str::uuid7();
        $this->in($school, fn () => DB::table('communication_threads')->insert([
            'id' => $id, 'school_id' => $school->id, 'thread_type' => 'direct', 'status' => 'open', 'created_by_user_id' => $this->createUser()->id,
            'last_activity_at' => $lastActivityAt, 'created_at' => '2001-01-01 00:00:00', 'updated_at' => '2001-01-01 00:00:00',
        ]));

        return $id;
    }

    private function message(School $school, string $threadId): void
    {
        $this->in($school, fn () => DB::table('communication_messages')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'thread_id' => $threadId, 'sender_user_id' => $this->createUser()->id,
            'body' => 'Hello', 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00',
        ]));
    }

    #[Test]
    public function never_sent_cancelled_and_rejected_announcements_go_one_calendar_year_after_their_end_and_nothing_else_does(): void
    {
        $school = $this->createSchool();
        $cancelled = $this->announcement($school, ['status' => 'cancelled', 'cancelled_at' => '2025-01-10 10:00:00']);
        $path = $this->attachment($school, $cancelled);
        $rejected = $this->announcement($school, ['status' => 'rejected']);
        $this->approval($school, $rejected, 'rejected', '2025-01-10 10:00:00');
        $boundary = $this->announcement($school, ['status' => 'cancelled', 'cancelled_at' => '2025-01-10 10:00:01']);
        $reopened = $this->announcement($school, ['status' => 'draft']);
        $this->approval($school, $reopened, 'rejected', '2020-01-01 00:00:00');
        $published = $this->announcement($school, ['status' => 'published', 'published_at' => '2020-01-01 00:00:00']);
        $undated = $this->announcement($school, ['status' => 'cancelled', 'cancelled_at' => null]);
        $service = app(CommunicationResidualRetentionService::class);

        $this->at('2026-01-10 10:00:01');
        $this->assertCounts(['eligible' => 2, 'deleted' => 0, 'unresolved' => 1, 'dependency_blocked' => 0], $service->pruneNeverSent($school, RetentionPeriod::yearsBeforeNow(1), 500, true, false));
        $this->assertSame(1, $this->rows($school, 'communication_announcements', $cancelled), 'a dry run deletes nothing');

        $this->assertCounts(['eligible' => 2, 'deleted' => 2, 'errors' => 0], $service->pruneNeverSent($school, RetentionPeriod::yearsBeforeNow(1), 500, false, false));
        $this->assertSame(0, $this->rows($school, 'communication_announcements', $cancelled));
        $this->assertSame(0, $this->rows($school, 'communication_attachments', $cancelled, 'communication_announcement_id'));
        Storage::disk($this->disk)->assertMissing($path);
        $this->assertSame(0, $this->rows($school, 'communication_announcements', $rejected));
        $this->assertSame(0, $this->rows($school, 'communication_approval_requests', $rejected, 'announcement_id'), 'its approval history goes with it');
        foreach (['exactly one year: kept' => $boundary, 'edited back to draft: live working state' => $reopened, 'published: D3 owns it' => $published, 'no cancellation time: unresolved' => $undated] as $label => $id) {
            $this->assertSame(1, $this->rows($school, 'communication_announcements', $id), $label);
        }

        $this->at('2026-01-10 10:00:02');
        $this->artisan('platform:communications-prune', ['--only' => 'residual'])->expectsOutputToContain('never-sent announcement(s)')->assertSuccessful();
        $this->assertSame(0, $this->rows($school, 'communication_announcements', $boundary), 'one second later it goes too');
        $this->assertCounts(['eligible' => 0], $service->pruneNeverSent($school, RetentionPeriod::yearsBeforeNow(1), 500, false, false));
    }

    #[Test]
    public function an_empty_thread_goes_one_calendar_year_after_its_last_activity(): void
    {
        $school = $this->createSchool();
        $empty = $this->thread($school, '2025-03-01 08:00:00');
        $this->in($school, fn () => DB::table('communication_thread_participants')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'thread_id' => $empty, 'user_id' => $this->createUser()->id, 'joined_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]));
        $boundary = $this->thread($school, '2025-03-01 08:00:01');
        $withMessage = $this->thread($school, '2020-01-01 00:00:00');
        $this->message($school, $withMessage);
        $undated = $this->thread($school, null);
        $service = app(CommunicationResidualRetentionService::class);

        $this->at('2026-03-01 08:00:01');
        $this->assertCounts(['eligible' => 1, 'deleted' => 1, 'unresolved' => 1, 'dependency_blocked' => 0], $service->pruneEmptyThreads($school, RetentionPeriod::yearsBeforeNow(1), 500, false, false));
        $this->assertSame(0, $this->rows($school, 'communication_threads', $empty));
        $this->assertSame(0, $this->rows($school, 'communication_thread_participants', $empty, 'thread_id'), 'participants go with it');
        $this->assertSame(1, $this->rows($school, 'communication_threads', $boundary));
        $this->assertSame(1, $this->rows($school, 'communication_threads', $withMessage), 'a thread with a message is D3 content');
        $this->assertSame(1, $this->rows($school, 'communication_threads', $undated));
    }

    // --- Operations -------------------------------------------------------

    #[Test]
    public function ended_driver_assignments_go_seven_calendar_years_after_they_ended_and_release_their_driver(): void
    {
        $school = $this->createSchool();
        $route = $this->createTransportRoute($school);
        $vehicle = $this->createTransportVehicle($school);
        $driver = $this->createEmployee($school);
        $old = $this->createTransportRouteAssignment($route, $vehicle, $driver, ['status' => 'ended', 'starts_on' => '2018-01-01 00:00:00', 'ends_on' => '2019-03-01 00:00:00']);
        $boundary = $this->createTransportRouteAssignment($route, $vehicle, $driver, ['status' => 'ended', 'starts_on' => '2019-01-01 00:00:00', 'ends_on' => '2019-03-01 00:00:01']);
        $active = $this->createTransportRouteAssignment($route, $vehicle, $driver, ['status' => 'active', 'starts_on' => '2010-01-01 00:00:00', 'ends_on' => null]);
        $service = app(DriverAssignmentRetentionService::class);

        $this->at('2026-03-01 00:00:01');
        $this->assertCounts(['eligible' => 1, 'deleted' => 0], $service->prune($school, RetentionPeriod::yearsBeforeNow(7), 500, true, false));
        $this->assertCounts(['eligible' => 1, 'deleted' => 1], $service->prune($school, RetentionPeriod::yearsBeforeNow(7), 500, false, false));

        $this->assertSame(0, $this->rows($school, 'transport_route_assignments', $old->id));
        $this->assertSame(1, $this->rows($school, 'transport_route_assignments', $boundary->id), 'exactly seven years: kept');
        $this->assertSame(1, $this->rows($school, 'transport_route_assignments', $active->id), 'an active assignment is never eligible');
        $this->assertSame(1, $this->rows($school, 'employees', $driver->id), 'the driver is never deleted here; only the reference is released');
        $this->assertSame(1, $this->rows($school, 'transport_routes', $route->id), 'routes are configuration');
    }

    #[Test]
    public function a_visit_goes_one_calendar_year_after_checkout_and_the_visitor_with_its_last_visit(): void
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $once = $this->createVisitor($school);
        $onceVisit = $this->createVisitorVisit($once, $campus, ['status' => 'checked_out', 'checked_in_at' => '2025-02-01 09:00:00', 'checked_out_at' => '2025-02-01 10:00:00']);
        $returning = $this->createVisitor($school);
        $oldVisit = $this->createVisitorVisit($returning, $campus, ['status' => 'checked_out', 'checked_in_at' => '2024-01-01 09:00:00', 'checked_out_at' => '2024-01-01 10:00:00']);
        $newVisit = $this->createVisitorVisit($returning, $campus, ['status' => 'checked_out', 'checked_in_at' => '2025-12-01 09:00:00', 'checked_out_at' => '2025-12-01 10:00:00']);
        $stale = $this->createVisitor($school);
        $this->createVisitorVisit($stale, $campus, ['status' => 'checked_in', 'checked_in_at' => '2020-01-01 09:00:00', 'checked_out_at' => null]);
        $boundary = $this->createVisitor($school);
        $this->createVisitorVisit($boundary, $campus, ['status' => 'checked_out', 'checked_in_at' => '2025-02-01 09:00:00', 'checked_out_at' => '2025-02-01 10:00:01']);
        $service = app(VisitorRetentionService::class);

        $this->at('2026-02-01 10:00:01');
        $this->assertCounts(['eligible' => 2, 'deleted' => 2, 'unresolved' => 1, 'dependency_blocked' => 0], $service->prune($school, RetentionPeriod::yearsBeforeNow(1), 500, false, false));

        $this->assertSame(0, $this->rows($school, 'visitor_visits', $onceVisit->id));
        $this->assertSame(0, $this->rows($school, 'visitors', $once->id), 'the visitor goes with its last visit');
        $this->assertSame(0, $this->rows($school, 'visitor_visits', $oldVisit->id));
        $this->assertSame(1, $this->rows($school, 'visitor_visits', $newVisit->id));
        $this->assertSame(1, $this->rows($school, 'visitors', $returning->id), 'a returning visitor keeps its identity with the newer visit');
        $this->assertSame(1, $this->rows($school, 'visitors', $stale->id), 'never checked out: unresolved, kept');
        $this->assertSame(1, $this->rows($school, 'visitors', $boundary->id), 'exactly one year: kept');
    }

    private function execution(School $school, string $ruleId, string $status, ?string $completedAt): string
    {
        $id = (string) Str::uuid7();
        $this->in($school, function () use ($school, $ruleId, $status, $completedAt, $id): void {
            DB::table('automation_executions')->insert([
                'id' => $id, 'school_id' => $school->id, 'rule_instance_id' => $ruleId, 'trigger_key' => 'k'.Str::random(8), 'trigger_event_type' => 'academic.year.activated.v1',
                'subject_type' => 'academic_year', 'subject_id' => (string) Str::uuid7(), 'status' => $status, 'attempts' => 1, 'completed_at' => $completedAt,
                'created_at' => '2001-01-01 00:00:00', 'updated_at' => '2001-01-01 00:00:00',
            ]);
            DB::table('automation_execution_attempts')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'execution_id' => $id, 'attempt_number' => 1, 'outcome' => 'succeeded', 'finished_at' => '2024-01-01 00:00:00', 'created_at' => now(),
            ]);
        });

        return $id;
    }

    #[Test]
    public function completed_automation_executions_go_one_calendar_year_after_completion_with_their_history_only(): void
    {
        $school = $this->createSchool();
        $rule = (string) Str::uuid7();
        $this->in($school, fn () => DB::table('automation_rule_instances')->insert(['id' => $rule, 'school_id' => $school->id, 'rule_type' => 'academic_year_setup_review', 'status' => 'disabled', 'created_at' => now(), 'updated_at' => now()]));
        $done = $this->execution($school, $rule, 'succeeded', '2025-04-01 00:00:00');
        $this->in($school, fn () => DB::table('automation_review_items')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'rule_instance_id' => $rule, 'execution_id' => $done, 'item_type' => 'academic_year_setup_review',
            'subject_type' => 'academic_year', 'subject_id' => (string) Str::uuid7(), 'created_at' => now(),
        ]));
        $abandoned = $this->execution($school, $rule, 'abandoned', '2025-04-01 00:00:00');
        $boundary = $this->execution($school, $rule, 'skipped', '2025-04-01 00:00:01');
        $pending = $this->execution($school, $rule, 'pending', null);
        $running = $this->execution($school, $rule, 'running', null);
        $service = app(AutomationExecutionRetention::class);

        $this->at('2026-04-01 00:00:01');
        $this->assertCounts(['eligible' => 2, 'deleted' => 2, 'dependency_blocked' => 0], $service->prune($school, RetentionPeriod::yearsBeforeNow(1), 500, false, false));

        $this->assertSame(0, $this->rows($school, 'automation_executions', $done));
        $this->assertSame(0, $this->rows($school, 'automation_execution_attempts', $done, 'execution_id'), 'attempts go with it');
        $this->assertSame(0, $this->rows($school, 'automation_review_items', $done, 'execution_id'), 'review items go with it');
        $this->assertSame(0, $this->rows($school, 'automation_executions', $abandoned));
        foreach (['exactly one year' => $boundary, 'pending' => $pending, 'running' => $running] as $label => $id) {
            $this->assertSame(1, $this->rows($school, 'automation_executions', $id), $label);
        }
        $this->assertSame(1, $this->rows($school, 'automation_rule_instances', $rule), 'the rule configuration stays');
    }

    #[Test]
    public function the_operations_command_holds_a_school_and_never_crosses_schools(): void
    {
        $held = $this->createSchool();
        $other = $this->createSchool();
        config(['retention.hold_school_ids' => [$held->id]]);
        $assignments = [];
        foreach ([$held, $other] as $school) {
            $assignments[] = $this->createTransportRouteAssignment($this->createTransportRoute($school), $this->createTransportVehicle($school), $this->createEmployee($school), ['status' => 'ended', 'starts_on' => '2000-01-01 00:00:00', 'ends_on' => '2001-01-01 00:00:00']);
        }

        $this->at('2026-01-01 00:00:00');
        $this->assertCounts(['eligible' => 1, 'held' => 1, 'deleted' => 0], app(DriverAssignmentRetentionService::class)->prune($held, RetentionPeriod::yearsBeforeNow(7), 500, false, true));
        $this->assertSame(0, $this->in($held, fn () => DB::table('transport_route_assignments')->where('id', $assignments[1]->id)->count()), 'RLS: School A never sees School B');
        $this->artisan('platform:operations-retention-prune')->expectsOutputToContain('ended driver assignments')->assertSuccessful();

        $this->assertSame(1, $this->rows($held, 'transport_route_assignments', $assignments[0]->id));
        $this->assertSame(0, $this->rows($other, 'transport_route_assignments', $assignments[1]->id));
    }

    // --- API credentials (D6) --------------------------------------------

    private function credential(School $school, string $issuedAt, string $expiresAt, ?string $revokedAt = null): string
    {
        $client = (string) Str::uuid7();
        $id = (string) Str::uuid7();
        DB::table('api_clients')->insert(['id' => $client, 'school_id' => $school->id, 'name' => 'Partner', 'scopes' => '["students.read"]', 'status' => 'active', 'created_by_user_id' => $this->createUser()->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('api_client_credentials')->insert(['id' => $id, 'api_client_id' => $client, 'school_id' => $school->id, 'key_id' => bin2hex(random_bytes(8)), 'secret_hash' => hash('sha256', Str::random(20)), 'issued_at' => $issuedAt, 'expires_at' => $expiresAt, 'created_at' => now(), 'updated_at' => now()]);
        if ($revokedAt !== null) {
            DB::table('api_client_credentials')->where('id', $id)->update(['revoked_at' => $revokedAt]);
        }

        return $id;
    }

    #[Test]
    public function an_ended_api_credential_goes_seven_calendar_years_after_its_authority_ended(): void
    {
        $school = $this->createSchool();
        $other = $this->createSchool();
        $revoked = $this->credential($school, '2015-01-01 00:00:00', '2015-12-01 00:00:00', '2015-03-01 00:00:00');
        $expired = $this->credential($school, '2016-01-01 00:00:00', '2016-06-01 00:00:00');
        $recent = $this->credential($school, '2020-01-01 00:00:00', '2020-06-01 00:00:00');
        $current = $this->credential($school, now()->subDays(5)->format('Y-m-d H:i:s'), now()->addDays(100)->format('Y-m-d H:i:s'));
        $foreign = $this->credential($other, '2015-01-01 00:00:00', '2015-06-01 00:00:00');
        $expiry = app(RetentionExpiry::class);

        $this->at('2026-06-15 12:00:00');
        $cutoff = RetentionPeriod::yearsBeforeNow(7);
        $this->assertCounts(['eligible' => 2, 'deleted' => 2], $expiry->forSchool(RetentionExpiry::SCHOOL_API_CREDENTIAL, $school, $cutoff, 500, false));
        $this->assertSame(0, DB::table('api_client_credentials')->whereIn('id', [$revoked, $expired])->count());
        $this->assertSame(2, DB::table('api_client_credentials')->whereIn('id', [$recent, $current])->count(), 'a recent end, or a current credential, stays');
        $this->assertSame(1, DB::table('api_client_credentials')->where('id', $foreign)->count(), 'another School is untouched');
        $this->assertSame(4, DB::table('api_clients')->where('school_id', $school->id)->count(), 'API clients (configuration) stay');

        // The runtime role cannot delete a credential nor (E21-RH.4) run the function; as the retention
        // identity the floor refuses a young cutoff, and only the tenant's own rows are reachable.
        $this->in($school, function () use ($school, $recent): void {
            $this->refused(fn () => DB::table('api_client_credentials')->where('id', $recent)->delete(), 'permission denied');
            $this->refused(fn () => DB::select('select retention_expire_api_client_credentials(?, ?, 10, false)', [$school->id, '2018-01-01 00:00:00']), 'permission denied for function');
        });
        DB::usingConnection(RetentionExpiry::PRIVILEGED_CONNECTION, function () use ($school, $other): void {
            $this->in($school, fn () => $this->refused(fn () => DB::select('select retention_expire_api_client_credentials(?, ?, 10, false)', [$school->id, '2025-01-01 00:00:00']), 'retention_floor'));
            $this->in($other, fn () => $this->refused(fn () => DB::select('select retention_expire_api_client_credentials(?, ?, 10, false)', [$school->id, '2018-01-01 00:00:00']), 'retention_tenant'));
        });

        // Held: counted only.
        config(['retention.hold_school_ids' => [$other->id]]);
        $this->assertCounts(['eligible' => 1, 'held' => 1, 'deleted' => 0], $expiry->forSchool(RetentionExpiry::SCHOOL_API_CREDENTIAL, $other, $cutoff, 500, false));
        $this->assertSame(1, DB::table('api_client_credentials')->where('id', $foreign)->count());

        $check = collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code');
        $this->assertSame(CheckResult::PASS, $check['retention_functions_narrow']->status);
    }
}
