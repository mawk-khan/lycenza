<?php

namespace Tests\Feature\Retention;

use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * E21.3E: the residual expiries racing the writes that matter, in two real
 * OS processes with an observed lock wait (COMMITTED fixtures).
 * - Empty-thread expiry vs a new message: a message insert takes FOR KEY
 *   SHARE on its thread; the purge locks the thread FOR UPDATE and
 *   rechecks. Message first keeps the thread; expiry first makes the late
 *   message fail on its foreign key.
 * - Never-sent expiry vs editing a rejected announcement (which returns it
 *   to draft): the purge locks the row and rechecks the status.
 * - Visitor expiry vs a new visit: the purge locks the visitor; the visit
 *   insert takes FOR KEY SHARE. A new visit keeps the visitor.
 * Ended driver assignments, checkouts, completed automation executions and
 * ended API credentials are never reopened (pinned by
 * ResidualRetentionArchitectureGuardTest and, for credentials, the
 * database), so they have no lifecycle race.
 */
class ResidualRetentionConcurrencyTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $schoolIds = [];

    protected function tearDown(): void
    {
        foreach ($this->schoolIds as $schoolId) {
            $this->deleteSchoolAsAdmin($schoolId);
        }

        parent::tearDown();
    }

    private function school(): School
    {
        $school = $this->createSchool();
        $this->schoolIds[] = $school->id;

        return $school;
    }

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/residual-retention-op.php', ...$args];
    }

    private function emptyThread(School $school): string
    {
        $id = (string) Str::uuid7();
        app(TenantContext::class)->withSchool($school, fn () => DB::table('communication_threads')->insert([
            'id' => $id, 'school_id' => $school->id, 'thread_type' => 'direct', 'status' => 'open', 'created_by_user_id' => $this->createUser()->id,
            'last_activity_at' => '2015-01-01 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]));

        return $id;
    }

    private function adminRows(string $table, string $column, string $id): int
    {
        return DB::connection('pgsql_admin')->table($table)->where($column, $id)->count();
    }

    #[Test]
    public function a_message_committed_first_keeps_the_thread(): void
    {
        $school = $this->school();
        $thread = $this->emptyThread($school);

        $this->backdateEndRecording();
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('message', $school->id, $thread, $this->createUser()->id),
            $this->script('thread-prune', $school->id, '2020-01-01 00:00:00'),
        );

        $this->assertSame('messaged', $holder);
        $this->assertSame('deleted:0', $contender);
        $this->assertSame(1, $this->adminRows('communication_threads', 'id', $thread));
    }

    #[Test]
    public function a_thread_expiry_committed_first_refuses_the_late_message(): void
    {
        $school = $this->school();
        $thread = $this->emptyThread($school);

        $this->backdateEndRecording();
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('thread-prune', $school->id, '2020-01-01 00:00:00'),
            $this->script('message', $school->id, $thread, $this->createUser()->id),
        );

        $this->assertSame('deleted:1', $holder);
        $this->assertStringStartsWith('rejected:', $contender);
        $this->assertSame(0, $this->adminRows('communication_messages', 'thread_id', $thread));
    }

    #[Test]
    public function an_edit_committed_first_keeps_the_rejected_announcement(): void
    {
        $school = $this->school();
        $id = (string) Str::uuid7();
        app(TenantContext::class)->withSchool($school, function () use ($school, $id): void {
            DB::table('communication_announcements')->insert(['id' => $id, 'school_id' => $school->id, 'created_by_user_id' => $this->createUser()->id, 'title' => 'N', 'body' => 'B', 'status' => 'rejected', 'audience_type' => 'school_wide', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('communication_approval_requests')->insert(['id' => (string) Str::uuid7(), 'school_id' => $school->id, 'announcement_id' => $id, 'requested_by_user_id' => $this->createUser()->id, 'requested_at' => '2015-01-01 00:00:00', 'fingerprint' => str_repeat('a', 64), 'snapshot' => '{}', 'status' => 'rejected', 'decided_by_user_id' => $this->createUser()->id, 'decided_at' => '2015-01-02 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
        });

        $this->backdateEndRecording();
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('edit', $school->id, $id),
            $this->script('never-sent-prune', $school->id, '2020-01-01 00:00:00'),
        );

        $this->assertSame('edited', $holder);
        $this->assertSame('deleted:0', $contender);
        $this->assertSame('draft', DB::connection('pgsql_admin')->table('communication_announcements')->where('id', $id)->value('status'));
    }

    #[Test]
    public function a_new_visit_committed_first_keeps_the_visitor(): void
    {
        $school = $this->school();
        $campus = $this->createCampus($school);
        $visitor = $this->createVisitor($school);
        $this->createVisitorVisit($visitor, $campus, ['status' => 'checked_out', 'checked_in_at' => '2015-01-01 09:00:00', 'checked_out_at' => '2015-01-01 10:00:00']);

        $this->backdateEndRecording();
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('visit', $school->id, $visitor->id, $campus->id),
            $this->script('visitor-prune', $school->id, '2020-01-01 00:00:00'),
        );

        $this->assertSame('visited', $holder);
        $this->assertSame('deleted:1', $contender, 'the expired visit goes');
        $this->assertSame(1, $this->adminRows('visitors', 'id', $visitor->id), 'the visitor stays with its new visit');
        $this->assertSame(1, $this->adminRows('visitor_visits', 'visitor_id', $visitor->id));
    }

    #[Test]
    public function a_revoked_api_credential_can_never_be_reactivated(): void
    {
        $school = $this->school();
        $client = (string) Str::uuid7();
        $id = (string) Str::uuid7();
        DB::table('api_clients')->insert(['id' => $client, 'school_id' => $school->id, 'name' => 'P', 'scopes' => '["students.read"]', 'status' => 'active', 'created_by_user_id' => $this->createUser()->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('api_client_credentials')->insert(['id' => $id, 'api_client_id' => $client, 'school_id' => $school->id, 'key_id' => bin2hex(random_bytes(8)), 'secret_hash' => hash('sha256', 'x'), 'issued_at' => now(), 'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('api_client_credentials')->where('id', $id)->update(['revoked_at' => now()]);

        foreach ([['revoked_at' => null], ['expires_at' => now()->addDays(60)]] as $reopen) {
            try {
                DB::table('api_client_credentials')->where('id', $id)->update($reopen);
                $this->fail('a revocation is final and an expiry is never extended');
            } catch (QueryException $e) {
                $this->assertStringContainsString('api_client_credentials', $e->getMessage());
            }
        }
    }
}
