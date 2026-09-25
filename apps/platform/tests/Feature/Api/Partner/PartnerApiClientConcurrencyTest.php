<?php

namespace Tests\Feature\Api\Partner;

use App\Models\ApiClient;
use App\Models\ApiClientCredential;
use App\Models\School;
use App\Models\User;
use App\Support\Api\PartnerScopeRegistry;
use App\Support\ApiClients\ApiClientService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * Phase 0O.3 (ADR 0049 section 5): partner credential races between real
 * OS processes, overlap forced and verified (ForcesConcurrentOverlap),
 * never slept. Every mutation locks the client row, so rotations and
 * revocations serialize; at most two credentials are ever usable. A
 * request authenticated before a revocation commits may finish; one that
 * starts after the commit fails.
 */
class PartnerApiClientConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    private ?User $admin = null;

    private string $script = __DIR__.'/../../../Support/api-client-op.php';

    protected function tearDown(): void
    {
        $this->deleteSchoolAsAdmin($this->school);
        if ($this->admin !== null) {
            DB::connection('pgsql_admin')->table('users')->where('id', $this->admin->id)->delete();
        }

        parent::tearDown();
    }

    /** @return array{0: ApiClient, 1: string} */
    private function world(): array
    {
        [$this->admin, $this->school] = $this->createSchoolAdmin('school_admin');
        $this->createCampus($this->school);

        return app(ApiClientService::class)->issue($this->school, $this->admin, 'Race', [PartnerScopeRegistry::PROBE]);
    }

    private function op(string $operation, ApiClient $client): array
    {
        return ['php', $this->script, $operation, $this->school->id, $this->admin->id, $client->id];
    }

    private function usable(ApiClient $client): int
    {
        return ApiClientCredential::query()->where('api_client_id', $client->id)->get()->filter->isUsable()->count();
    }

    #[Test]
    public function two_concurrent_rotations_serialize_and_leave_two_usable(): void
    {
        [$client] = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('rotate', $client), $this->op('rotate', $client));

        $this->assertSame('rotated', $holder);
        $this->assertSame('rotated', $contender);
        $this->assertSame(3, ApiClientCredential::query()->where('api_client_id', $client->id)->count());
        $this->assertSame(1, ApiClientCredential::query()->where('api_client_id', $client->id)->whereNull('superseded_at')->whereNull('revoked_at')->count());
        $this->assertSame(2, $this->usable($client));
    }

    #[Test]
    public function a_rotation_waiting_on_a_revocation_is_refused(): void
    {
        [$client] = $this->world();

        [$holder, $contender] = $this->raceWithHeldHolder($this->op('revoke', $client), $this->op('rotate', $client));

        $this->assertSame('revoked', $holder);
        $this->assertSame('invalid:client', $contender);
        $this->assertSame(1, ApiClientCredential::query()->where('api_client_id', $client->id)->count(), 'No credential was minted for a revoked client.');
        $this->assertSame(0, $this->usable($client));
    }

    #[Test]
    public function a_request_before_the_revocation_commits_succeeds_and_after_it_fails(): void
    {
        [$client, $credential] = $this->world();
        $dir = sys_get_temp_dir().'/race_'.bin2hex(random_bytes(8));
        mkdir($dir);

        $holder = new Process($this->op('revoke', $client), null, ['CONCURRENCY_HOLD_DIR' => $dir]);
        $holder->setTimeout(180);
        $holder->start();

        try {
            $deadline = microtime(true) + 90;
            while (! file_exists($dir.'/acted')) {
                $this->assertTrue($holder->isRunning() && microtime(true) < $deadline, 'The revocation never reached its held write: '.$holder->getErrorOutput());
                usleep(2_000);
            }

            // Revocation written but NOT committed: this request authenticates.
            $this->withToken($credential)->getJson('/api/v1/partner/probe')->assertOk();
        } finally {
            touch($dir.'/release');
            $holder->wait();
            @unlink($dir.'/acted');
            @unlink($dir.'/release');
            @rmdir($dir);
        }

        $this->assertSame('revoked', trim($holder->getOutput()));
        $this->app['auth']->forgetGuards();
        $this->withToken($credential)->getJson('/api/v1/partner/probe')->assertUnauthorized();
    }
}
