<?php

namespace Tests\Feature\Platform\Modules;

use App\Domain\Platform\Application\Modules\SchoolModuleEntitlements;
use App\Models\School;
use App\Models\User;
use Database\Factories\SchoolFactory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Platform\Schools\SchoolLifecycleTestHelpers;
use Tests\TestCase;

/**
 * ORG.5 (ADR 0076 §31): entitlement changes for one School serialize on a
 * per-School advisory lock and re-check their dependencies inside it. Two
 * real OS processes against real PostgreSQL; the contender is positively
 * observed blocked before the holder commits (ForcesConcurrentOverlap).
 */
class SchoolModuleEntitlementConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap, SchoolLifecycleTestHelpers;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $userIds = [];

    /** @var list<string> */
    private array $schoolIds = [];

    protected function tearDown(): void
    {
        $admin = DB::connection('pgsql_admin');
        $admin->table('platform_audit_events')->whereIn('actor_user_id', $this->userIds)->orWhereIn('subject_id', $this->schoolIds)->delete();
        $this->purgeSchoolsAsAdmin($this->schoolIds);
        $admin->table('platform_role_assignments')->whereIn('user_id', $this->userIds)->delete();
        $admin->table('user_mfa_recovery_codes')->whereIn('user_id', $this->userIds)->delete();
        $admin->table('user_mfa_factors')->whereIn('user_id', $this->userIds)->delete();
        $admin->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    /** @return array{0: User, 1: string} a root and one single-use recovery code */
    private function root(): array
    {
        $root = $this->platformAdmin();
        $this->userIds[] = $root->id;

        return [$root, $this->issueRecoveryCodes($root, 1)[0]];
    }

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../../Support/school-module-op.php', ...$args];
    }

    #[Test]
    public function enabling_a_dependant_while_its_dependency_is_being_disabled_is_refused(): void
    {
        $school = $this->createSchool();
        $this->schoolIds[] = $school->id;
        SchoolFactory::setOptionalModules($school, false, ['library', 'canteen']);
        [$rootA, $codeA] = $this->root();
        [$rootB, $codeB] = $this->root();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('disable', $rootA->id, $school->id, 'fees', $codeA),
            $this->script('enable', $rootB->id, $school->id, 'library', $codeB),
        );

        $this->assertSame('disabled', $holder);
        $this->assertSame('rejected:dependency_disabled', $contender);
        $states = app(SchoolModuleEntitlements::class)->optionalStates(School::query()->findOrFail($school->id));
        $this->assertFalse($states['fees']);
        $this->assertFalse($states['library']);
    }

    #[Test]
    public function two_simultaneous_disables_change_the_module_once(): void
    {
        $school = $this->createSchool();
        $this->schoolIds[] = $school->id;
        [$rootA, $codeA] = $this->root();
        [$rootB, $codeB] = $this->root();

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('disable', $rootA->id, $school->id, 'visitor', $codeA),
            $this->script('disable', $rootB->id, $school->id, 'visitor', $codeB),
        );

        $this->assertSame('disabled', $holder);
        $this->assertSame('rejected:state_changed', $contender);
        $this->assertSame(1, DB::table('platform_audit_events')->where('subject_id', $school->id)->where('event_type', 'platform.school_entitlement.disabled')->count());
    }
}
