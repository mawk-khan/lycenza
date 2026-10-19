<?php

namespace Tests\Feature\Admissions;

use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Navigation\NavigationBuilder;
use App\Support\Tenancy\TenantContext;
use Database\Factories\SchoolFactory;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7D-3 D3-E (ADR 0076 §39): PRE-CONVERSION Admissions is Campus-adopted by
 * the application's Campus. A Campus-scoped officer lists, opens and moves
 * through their lifecycle only their Campuses' applications, and sees an
 * Applicant only through such an application. The Applicant directory and
 * page, starting an application, Guardian searches, conversion and the fee
 * head stay whole-School (declared operations).
 */
class AdmissionsCampusScopeTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private Applicant $applicant;

    /** @var array<string, AdmissionApplication> */
    private array $applications = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $year = $this->createAcademicYear($this->school, ['code' => 'A2627', 'name' => '2026-27', 'status' => 'active']);
        $grade = $this->createGradeLevel($this->school);
        // One Applicant, an open application on each Campus.
        $this->applicant = $this->createApplicant($this->school, ['first_name' => 'Asha']);
        foreach (['a' => $this->a, 'b' => $this->b] as $key => $campus) {
            $this->applications[$key] = $this->createAdmissionApplication($this->applicant, $year, $campus, $grade, ['status' => 'submitted']);
        }
    }

    /** @param  list<?string>  $campusIds */
    private function officer(array $campusIds): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, 'admissions_officer', $campusId);
        }

        return $user;
    }

    private function api(User $user): self
    {
        app('auth')->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-device')->plainTextToken);
    }

    private function url(string $path): string
    {
        return "/api/v1/schools/{$this->school->id}{$path}";
    }

    private function signIn(User $user): void
    {
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
    }

    private function applicationStatus(string $key): string
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => AdmissionApplication::query()->findOrFail($this->applications[$key]->id)->status);
    }

    #[Test]
    public function a_whole_school_officer_is_unchanged(): void
    {
        $user = $this->officer([null]);
        $this->api($user)->getJson($this->url('/admission-applications'))->assertOk()->assertJsonCount(2, 'data');
        $this->api($user)->getJson($this->url("/applicants/{$this->applicant->id}/applications"))->assertOk()->assertJsonCount(2, 'data');
        $this->signIn($user);
        $this->get('/app/admissions/'.$this->applications['a']->id)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('canManage', true)->where('canConvert', true)->where('canViewApplicants', true));
        $this->get('/app/admissions/applicants')->assertOk();
    }

    #[Test]
    public function a_campus_a_officer_works_only_campus_a_applications(): void
    {
        $user = $this->officer([$this->a->id]);
        $a = $this->applications['a'];
        $b = $this->applications['b'];

        // List: Campus A only; the Campus filter offers Campus A only and only narrows.
        $ids = $this->api($user)->getJson($this->url('/admission-applications'))->assertOk()->json('data.*.id');
        $this->assertSame([$a->id], $ids);
        $this->assertSame([], $this->api($user)->getJson($this->url('/admission-applications?campus_id='.$this->b->id))->assertOk()->json('data'));
        $this->signIn($user);
        $index = $this->get('/app/admissions')->assertOk();
        $index->assertInertia(fn (AssertableInertia $p) => $p
            ->has('applications.data', 1)->where('campuses', [['id' => $this->a->id, 'name' => 'North']])->where('canViewApplicants', false));
        $this->assertStringNotContainsString('South', (string) $index->getContent(), 'the other application is never disclosed');

        // Detail and lifecycle: Campus B's application is NOT FOUND, web and API; nothing changes.
        $this->api($user)->getJson($this->url("/admission-applications/{$b->id}"))->assertNotFound();
        $this->api($user)->withHeader('Idempotency-Key', 'adm-accept-b')->postJson($this->url("/admission-applications/{$b->id}/accept"))->assertNotFound();
        $this->signIn($user);
        $this->get("/app/admissions/{$b->id}")->assertNotFound();
        $this->post("/app/admissions/{$b->id}/reject")->assertNotFound();
        $this->assertSame('submitted', $this->applicationStatus('b'));

        // Campus A: the page offers no Applicant link, conversion or fee panel; lifecycle works.
        $this->get("/app/admissions/{$a->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('canManage', true)->where('canConvert', false)->where('canViewApplicants', false)->where('feePanel', null)->where('compatibleSections', []));
        $this->post("/app/admissions/{$a->id}/accept")->assertRedirect();
        $this->assertSame('accepted', $this->applicationStatus('a'));
    }

    #[Test]
    public function applicant_identity_guardian_search_conversion_and_fees_stay_whole_school(): void
    {
        $user = $this->officer([$this->a->id]);
        $applicant = $this->applicant->id;

        foreach (['/applicants', "/applicants/{$applicant}", "/applicants/{$applicant}/applications", '/admission-fee-head', '/admission-applications/'.$this->applications['a']->id.'/fee-selection'] as $path) {
            $this->api($user)->getJson($this->url($path))->assertForbidden();
        }
        $this->api($user)->withHeader('Idempotency-Key', 'adm-convert-a')->postJson($this->url('/admission-applications/'.$this->applications['a']->id.'/convert'), [])->assertForbidden();
        $this->signIn($user);
        foreach (['/app/admissions/applicants', "/app/admissions/applicants/{$applicant}", "/app/admissions/applicants/{$applicant}/applications/create", '/app/admissions/guardians/search?q=as', '/app/admissions/fee-head'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->post('/app/admissions/'.$this->applications['a']->id.'/convert', [])->assertForbidden();

        $keys = collect(app(NavigationBuilder::class)->visibleEntries($user, $this->school, false))->pluck('key')->all();
        $this->assertContains('admissions', $keys);
    }

    #[Test]
    public function module_and_campus_state_gate_campus_authority(): void
    {
        $user = $this->officer([$this->a->id]);
        SchoolFactory::setOptionalModules($this->school, false, ['admissions']);
        $this->api($user)->getJson($this->url('/admission-applications'))->assertForbidden();
        SchoolFactory::setOptionalModules($this->school, true, ['admissions']);
        $this->api($user)->getJson($this->url('/admission-applications'))->assertOk();

        app(TenantContext::class)->withSchool($this->school, fn () => $this->a->forceFill(['status' => 'inactive'])->save());
        $this->api($user)->getJson($this->url('/admission-applications'))->assertForbidden();
    }
}
