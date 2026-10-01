<?php

namespace Tests\Feature\Communications;

use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * E21.2C (E21-D3): a thread purge racing a new message in that thread, in two
 * real OS processes with an observed lock wait. The purge locks the thread
 * FOR UPDATE and recomputes eligibility after the lock; a message insert
 * takes FOR KEY SHARE on its thread. So either the message commits first and
 * the thread is kept, or the purge commits first and the late message is
 * refused. Never a half-deleted thread.
 */
class CommunicationsRetentionConcurrencyTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

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
            Storage::disk('local')->deleteDirectory("schools/{$schoolId}");
            $this->deleteSchoolAsAdmin($schoolId);
        }

        $admin->table('users')->where('created_at', '>=', $this->startedAt)->whereNotIn('id', $admin->table('platform_role_assignments')->select('user_id'))->delete();

        parent::tearDown();
    }

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/communication-retention-op.php', ...$args];
    }

    /** @return array{0: School, 1: string, 2: string} school, thread id, sender id */
    private function oldThread(): array
    {
        $school = $this->createSchool();
        $this->schoolIds[] = $school->id;
        $this->createAcademicYear($school, ['starts_on' => '2021-04-01', 'ends_on' => '2022-03-31', 'status' => 'closed', 'code' => 'AYRACE']);
        $sender = $this->createUser();
        $thread = $this->createThread($school, $sender);
        $this->createMessage($thread, $sender, ['created_at' => '2021-06-01 06:00:00']);

        return [$school, $thread->id, $sender->id];
    }

    private function messages(School $school, string $threadId): int
    {
        return app(TenantContext::class)->withSchool($school, fn () => DB::table('communication_messages')->where('thread_id', $threadId)->count());
    }

    #[Test]
    public function a_message_committed_first_keeps_the_thread(): void
    {
        [$school, $threadId, $senderId] = $this->oldThread();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('post-message', $school->id, $threadId, $senderId),
            $this->script('purge-content', $school->id, '2025-01-01'),
        );

        $this->assertSame('posted', $holder);
        // The purge waited, recomputed after the lock (today's reply is in no
        // Academic Year here, so the thread is unresolved) and deleted nothing.
        $this->assertSame('deleted:0', $contender);
        $this->assertSame(2, $this->messages($school, $threadId));
    }

    #[Test]
    public function a_purge_committed_first_refuses_the_late_message(): void
    {
        [$school, $threadId, $senderId] = $this->oldThread();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('purge-content', $school->id, '2025-01-01'),
            $this->script('post-message', $school->id, $threadId, $senderId),
        );

        $this->assertSame('deleted:1', $holder);
        $this->assertStringStartsWith('rejected:', $contender);
        $this->assertSame(0, $this->messages($school, $threadId));
        $this->assertFalse(DB::connection('pgsql_admin')->table('communication_threads')->where('id', $threadId)->exists());
    }
}
