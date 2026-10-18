<?php

namespace Tests\Feature\Visitor;

use App\Domain\Visitor\Infrastructure\VisitorVisit;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Factories\SchoolFactory;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7A (ADR 0076 §11, §33) -- the Visitor canary: `visitor.visits.*` are
 * Campus-adopted. A Campus-scoped Front Office sees and works only its
 * Campuses' Visits; another Campus's Visit is NOT FOUND; a request naming
 * another Campus is refused; whole-School behaviour is unchanged.
 */
class VisitorCampusScopeTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private Campus $inactive;

    /** @var array<string, VisitorVisit> */
    private array $visits = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school, ['name' => 'North Campus']);
        $this->b = $this->createCampus($this->school, ['name' => 'South Campus']);
        $this->inactive = $this->createCampus($this->school, ['name' => 'Old Campus']);
        foreach (['a' => $this->a, 'b' => $this->b, 'inactive' => $this->inactive] as $key => $campus) {
            $this->visits[$key] = $this->createVisitorVisit($this->createVisitor($this->school), $campus, ['status' => 'checked_in', 'checked_out_at' => null]);
        }
        app(TenantContext::class)->withSchool($this->school, fn () => $this->inactive->forceFill(['status' => 'inactive'])->save());
    }

    /** @param  list<?string>  $campusIds  null = whole School */
    private function frontOffice(array $campusIds): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, 'front_office', $campusId);
        }

        return $user;
    }

    private function token(User $user): string
    {
        // The Sanctum guard keeps the first authenticated user for the whole
        // test; forget it so every request authenticates its own token.
        app('auth')->forgetGuards();

        return $user->createToken('test-device')->plainTextToken;
    }

    /** @return list<string> the Visit ids the API lists for $user */
    private function apiVisitIds(User $user): array
    {
        return collect($this->withHeader('Authorization', 'Bearer '.$this->token($user))
            ->getJson("/api/v1/schools/{$this->school->id}/visitor-visits")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    private function ids(string ...$keys): array
    {
        return collect($keys)->map(fn ($k) => $this->visits[$k]->id)->sort()->values()->all();
    }

    #[Test]
    public function a_whole_school_front_office_is_unchanged(): void
    {
        $user = $this->frontOffice([null]);

        $this->assertSame($this->ids('a', 'b', 'inactive'), $this->apiVisitIds($user));
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/visitor/visits')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('visits.data', 3)->where('canManage', true));
        $this->get('/app/visitor/visits/create')->assertInertia(fn (AssertableInertia $p) => $p->has('campuses', 3));
    }

    #[Test]
    public function a_campus_a_front_office_sees_and_works_only_campus_a(): void
    {
        $user = $this->frontOffice([$this->a->id]);
        $api = fn () => $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        // Lists: Campus A only (SQL predicate), web and API.
        $this->assertSame($this->ids('a'), $this->apiVisitIds($user));
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/visitor/visits')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->has('visits.data', 1)->where('visits.data.0.campusName', 'North Campus')->where('canManage', true));
        $this->get('/app/visitor/visits/create')->assertInertia(fn (AssertableInertia $p) => $p->where('campuses', [['id' => $this->a->id, 'name' => 'North Campus']]));

        // Direct access: Campus A yes, Campus B NOT FOUND (never 403: existence is not disclosed).
        $api()->getJson("/api/v1/schools/{$this->school->id}/visitor-visits/{$this->visits['a']->id}")->assertOk();
        $api()->getJson("/api/v1/schools/{$this->school->id}/visitor-visits/{$this->visits['b']->id}")->assertNotFound();
        $api()->postJson("/api/v1/schools/{$this->school->id}/visitor-visits/{$this->visits['b']->id}/end")->assertNotFound();
        $this->post("/app/visitor/visits/{$this->visits['b']->id}/end")->assertNotFound();
        $this->assertSame('checked_in', $this->visitStatus('b'));

        // Create: Campus A yes; Campus B (request-supplied) refused, web and API.
        $visitorForA = $this->createVisitor($this->school);
        $api()->withHeader('Idempotency-Key', 'campus-a-checkin')->postJson("/api/v1/schools/{$this->school->id}/visitor-visits", [
            'visitor_id' => $visitorForA->id, 'campus_id' => $this->a->id, 'purpose' => 'Meeting',
        ])->assertCreated();
        $visitorForB = $this->createVisitor($this->school);
        $api()->withHeader('Idempotency-Key', 'campus-b-checkin')->postJson("/api/v1/schools/{$this->school->id}/visitor-visits", [
            'visitor_id' => $visitorForB->id, 'campus_id' => $this->b->id, 'purpose' => 'Meeting',
        ])->assertNotFound();
        $this->post('/app/visitor/visits', ['visitor_id' => $visitorForB->id, 'campus_id' => $this->b->id, 'purpose' => 'Meeting'])->assertNotFound();
        $this->assertSame(0, app(TenantContext::class)->withSchool($this->school, fn () => VisitorVisit::query()->where('visitor_id', $visitorForB->id)->count()));

        // Allowed operation in Campus A.
        $this->post("/app/visitor/visits/{$this->visits['a']->id}/end")->assertRedirect('/app/visitor/visits');
        $this->assertSame('checked_out', $this->visitStatus('a'));
    }

    #[Test]
    public function two_campus_grants_give_the_union_and_an_inactive_campus_gives_nothing(): void
    {
        $this->assertSame($this->ids('a', 'b'), $this->apiVisitIds($this->frontOffice([$this->a->id, $this->b->id])));

        // A grant made while the Campus was active, then the Campus deactivated.
        $user = $this->frontOffice([$this->a->id]);
        app(TenantContext::class)->withSchool($this->school, fn () => $this->a->forceFill(['status' => 'inactive'])->save());
        $this->withHeader('Authorization', 'Bearer '.$this->token($user))->getJson("/api/v1/schools/{$this->school->id}/visitor-visits")->assertForbidden();
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/visitor/visits')->assertForbidden();

        // Reactivation restores the grant's effect.
        app(TenantContext::class)->withSchool($this->school, fn () => $this->a->forceFill(['status' => 'active'])->save());
        $this->assertSame($this->ids('a'), $this->apiVisitIds($user));
    }

    #[Test]
    public function no_grant_or_a_disabled_module_means_no_authority(): void
    {
        $none = $this->createUser();
        $this->createMembership($none, $this->school);
        $this->withHeader('Authorization', 'Bearer '.$this->token($none))->getJson("/api/v1/schools/{$this->school->id}/visitor-visits")->assertForbidden();

        $user = $this->frontOffice([$this->a->id]);
        SchoolFactory::setOptionalModules($this->school, false, ['visitor']);
        $this->withHeader('Authorization', 'Bearer '.$this->token($user))->getJson("/api/v1/schools/{$this->school->id}/visitor-visits")->assertForbidden();
        SchoolFactory::setOptionalModules($this->school, true, ['visitor']);
        $this->assertSame($this->ids('a'), $this->apiVisitIds($user));
    }

    #[Test]
    public function the_campus_grant_never_satisfies_the_school_wide_directory_or_another_domain(): void
    {
        $user = $this->frontOffice([$this->a->id]);
        $api = $this->withHeader('Authorization', 'Bearer '.$this->token($user));

        // front_office also carries the Visitor DIRECTORY, which stays School-only.
        $api->getJson("/api/v1/schools/{$this->school->id}/visitors")->assertForbidden();
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/visitor/directory')->assertForbidden();

        // The declared shared check-in lookup answers (active Visitors, minimal fields).
        $this->getJson('/app/visitor/visits/search/visitors?q=')->assertOk();
    }

    private function visitStatus(string $key): string
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => VisitorVisit::query()->findOrFail($this->visits[$key]->id)->status);
    }
}
