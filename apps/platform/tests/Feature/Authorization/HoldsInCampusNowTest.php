<?php

namespace Tests\Feature\Authorization;

use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.9C-2 (ADR 0076 §70): CapabilityResolver::holdsInCampusNow() -- the
 * fresh, uncached authority read for a direct-assured action on ONE
 * Campus's record. A whole-School grant always counts; a Campus grant
 * counts only for a Campus-adopted capability, on exactly that Campus,
 * while it is active. A School-wide record (no Campus) is exactly
 * holdsInSchoolNow(): a Campus grant never reaches it.
 */
class HoldsInCampusNowTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school);
        $this->b = $this->createCampus($this->school);
    }

    private function holder(string $role, ?string $campusId): User
    {
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $this->school), $role, $campusId);

        return $user;
    }

    private function holds(User $user, string $capability, ?string $campusId): bool
    {
        return DB::transaction(fn () => app(CapabilityResolver::class)->holdsInCampusNow($user, $capability, $this->school, $campusId));
    }

    #[Test]
    public function a_campus_grant_counts_only_on_its_own_active_campus_for_an_adopted_capability(): void
    {
        $whole = $this->holder('stores_officer', null);
        $campusA = $this->holder('stores_officer', $this->a->id);

        $this->assertTrue($this->holds($whole, 'inventory.stock.manage', $this->a->id));
        $this->assertTrue($this->holds($whole, 'inventory.stock.manage', null));
        $this->assertTrue($this->holds($campusA, 'inventory.stock.manage', $this->a->id));
        $this->assertFalse($this->holds($campusA, 'inventory.stock.manage', $this->b->id), 'never another Campus');
        $this->assertFalse($this->holds($campusA, 'inventory.stock.manage', null), 'never a School-wide record');
        $this->assertFalse($this->holds($campusA, 'inventory.directory.manage', $this->a->id), 'a capability that is not Campus-adopted needs a whole-School grant');

        app(TenantContext::class)->withSchool($this->school, fn () => Campus::query()->whereKey($this->a->id)->update(['status' => 'inactive']));
        $this->assertFalse($this->holds($campusA, 'inventory.stock.manage', $this->a->id), 'an inactive Campus confers nothing');
        $this->assertTrue($this->holds($whole, 'inventory.stock.manage', $this->a->id), 'whole-School keeps everything');
    }

    #[Test]
    public function it_reads_revocation_and_disablement_fresh(): void
    {
        $campusA = $this->holder('stores_officer', $this->a->id);
        $this->assertTrue($this->holds($campusA, 'inventory.stock.manage', $this->a->id));

        app(TenantContext::class)->withSchool($this->school, fn () => DB::table('membership_role_assignments')
            ->whereIn('school_membership_id', DB::table('school_memberships')->where('user_id', $campusA->id)->pluck('id'))
            ->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']));
        $this->assertFalse($this->holds($campusA, 'inventory.stock.manage', $this->a->id));

        $disabled = $this->holder('stores_officer', $this->a->id);
        $disabled->forceFill(['is_disabled' => true])->save();
        $this->assertFalse($this->holds($disabled, 'inventory.stock.manage', $this->a->id));
    }
}
