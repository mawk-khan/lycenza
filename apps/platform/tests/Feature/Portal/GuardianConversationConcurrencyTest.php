<?php

namespace Tests\Feature\Portal;

use App\Domain\Communications\Application\CommunicationMessageService;
use App\Domain\Communications\Application\CommunicationThreadService;
use App\Domain\Communications\Infrastructure\CommunicationThread;
use App\Domain\Communications\Infrastructure\CommunicationThreadParticipant;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * POR.4 (ADR 0070 §27.6): Guardian replies racing a duplicate, an
 * off-boarding, a participant removal and a thread closure -- two real OS
 * processes, with the contender OBSERVED waiting on the holder's lock. Each
 * pair serializes: either the reply commits first (and the change then
 * applies), or the change commits first and the reply is refused. Never an
 * unauthorized durable reply after the change became authoritative, and
 * never two messages for one key.
 */
class GuardianConversationConcurrencyTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesGuardianPortalFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

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
            $this->deleteSchoolAsAdmin($schoolId);
        }

        $admin->table('roles')->where('key', 'like', 'test.capability_grant.%')->where('created_at', '>=', $this->startedAt)->delete();
        $admin->table('users')->where('created_at', '>=', $this->startedAt)->whereNotIn('id', $admin->table('platform_role_assignments')->select('user_id'))->delete();

        parent::tearDown();
    }

    /** @return array{school: School, g: array<string, mixed>, thread: CommunicationThread, admin: User} */
    private function world(): array
    {
        $school = $this->createSchool();
        $this->schoolIds[] = $school->id;
        $g = $this->portalGuardian($school);
        $staff = $this->createUser();
        $this->assignSchoolRole($this->createMembership($staff, $school), 'school_admin');
        $thread = app(CommunicationThreadService::class)->createThread($school, $staff, 'direct', 'Race', guardianIds: [$g['guardian']->id]);
        app(CommunicationMessageService::class)->send($thread, $staff, 'Opening message');

        return ['school' => $school, 'g' => $g, 'thread' => $thread, 'admin' => $this->portalAdmin($school)];
    }

    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/guardian-reply-op.php', ...$args];
    }

    private function reply(array $w, string $key, string $body = 'Racing reply'): array
    {
        return $this->op('reply', $w['school']->id, $w['g']['user']->id, $w['thread']->id, $key, $body);
    }

    private function messages(array $w): Collection
    {
        return app(TenantContext::class)->withSchool($w['school'], fn () => DB::table('communication_messages')->where('thread_id', $w['thread']->id)->get());
    }

    #[Test]
    public function two_simultaneous_submissions_of_one_form_create_one_message(): void
    {
        $w = $this->world();
        $key = (string) Str::uuid();

        [$holder, $contender] = $this->raceWithHeldHolder($this->reply($w, $key), $this->reply($w, $key));

        $this->assertMatchesRegularExpression('/^replied:[0-9a-f-]{36}:new$/', $holder);
        $this->assertSame(str_replace(':new', ':replayed', $holder), $contender, 'The waiting duplicate answers the first message.');
        $this->assertCount(1, $this->messages($w)->where('idempotency_key', $key));
    }

    #[Test]
    public function an_off_boarding_committed_first_refuses_the_waiting_reply(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('offboard', $w['school']->id, $w['admin']->id, $w['g']['guardian']->id),
            $this->reply($w, (string) Str::uuid()),
        );

        $this->assertSame('offboarded', $holder);
        $this->assertSame('rejected:GuardianPortalAccessDeniedException', $contender);
        $this->assertCount(1, $this->messages($w));
    }

    #[Test]
    public function a_reply_committed_first_makes_the_off_boarding_wait_then_both_apply(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->reply($w, (string) Str::uuid()),
            $this->op('offboard', $w['school']->id, $w['admin']->id, $w['g']['guardian']->id),
        );

        $this->assertStringStartsWith('replied:', $holder);
        $this->assertSame('offboarded', $contender);
        $this->assertCount(2, $this->messages($w));
    }

    #[Test]
    public function a_participant_removal_committed_first_refuses_the_waiting_reply(): void
    {
        $w = $this->world();
        $participant = app(TenantContext::class)->withSchool($w['school'], fn () => CommunicationThreadParticipant::query()
            ->where('thread_id', $w['thread']->id)->where('user_id', $w['g']['user']->id)->sole());

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('remove-participant', $w['school']->id, $participant->id),
            $this->reply($w, (string) Str::uuid()),
        );

        $this->assertSame('removed', $holder);
        $this->assertSame('rejected:ModelNotFoundException', $contender);
        $this->assertCount(1, $this->messages($w));
    }

    #[Test]
    public function a_closure_committed_first_refuses_the_waiting_reply(): void
    {
        $w = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('close-thread', $w['school']->id, $w['thread']->id),
            $this->reply($w, (string) Str::uuid()),
        );

        $this->assertSame('closed', $holder);
        $this->assertSame('rejected:GuardianReplyException', $contender);
        $this->assertCount(1, $this->messages($w));
    }
}
