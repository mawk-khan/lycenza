<?php

namespace Tests\Feature\Identity;

use App\Models\School;
use App\Models\User;
use App\Support\Retention\Erasure\ErasureCaseService;
use App\Support\Retention\Erasure\ErasureCategory;
use App\Support\Retention\Erasure\UserActivePurposes;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * E21.4: a migration that adds a foreign key to `users` nobody classified
 * makes User minimization fail closed. COMMITTED fixtures: the probe table
 * is created by the migration role, whose DDL needs a lock on `users` that
 * an open test transaction would hold.
 */
class UserReferenceProbeTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?User $user = null;

    private ?School $school = null;

    protected function tearDown(): void
    {
        $admin = DB::connection('pgsql_admin');
        $admin->statement('DROP TABLE IF EXISTS e21_4_probe_user_refs');
        if ($this->user !== null) {
            $admin->table('erasure_cases')->where('subject_id', $this->user->id)->delete();
            $admin->table('platform_audit_events')->where('subject_id', $this->user->id)->delete();
            $admin->table('school_memberships')->where('user_id', $this->user->id)->delete();
            $admin->table('users')->where('id', $this->user->id)->delete();
        }
        $this->deleteSchoolAsAdmin($this->school);

        parent::tearDown();
    }

    #[Test]
    public function an_unclassified_reference_makes_minimization_fail_closed(): void
    {
        DB::connection('pgsql_admin')->statement('CREATE TABLE e21_4_probe_user_refs (id uuid PRIMARY KEY, user_id uuid REFERENCES users (id))');
        $this->school = $this->createSchool();
        $this->user = $this->createUser();
        $this->createMembership($this->user, $this->school, 'suspended');
        $service = app(ErasureCaseService::class);
        $case = $service->open(null, 'user', $this->user->id, 'written');
        $service->decide($case->id, 'approve', 'request_valid');

        $this->assertSame(['e21_4_probe_user_refs.user_id'], app(UserActivePurposes::class)->unclassifiedReferences());
        $identity = array_values(array_filter($service->execute($case->id, false), fn (ErasureCategory $c) => $c->category === 'user_identity'));
        $this->assertSame([ErasureCategory::POLICY_UNRESOLVED, 'unclassified_user_reference'], [$identity[0]->outcome, $identity[0]->reason]);
        $this->assertNull($this->user->fresh()->minimized_at);
    }
}
