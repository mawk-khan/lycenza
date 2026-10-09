<?php

namespace Tests\Feature\App;

use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Domain\Library\Infrastructure\LibraryTitle;
use App\Models\MembershipRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 10A -- the administrative Library Inertia UI
 * (App\Http\Controllers\App\{LibraryCatalogueController,
 * LibraryCirculationController}). Backend authorization/tenant-safety/
 * domain invariants are already proven by the JSON API test suite
 * (LibraryApiTest, LibraryCatalogueLifecycleTest) -- these tests cover
 * the Inertia-specific integration: page rendering, capability-aware
 * props, and that capability enforcement (not a hidden button) is what
 * actually blocks an unauthorized member, mirroring
 * StudentAdminUiTest's exact convention.
 */
class LibraryAdminUiTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function activate(User $user, School $school): void
    {
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
    }

    // --- Catalogue --------------------------------------------------------

    #[Test]
    public function a_member_with_catalogue_view_sees_the_index(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'principal');
        $this->activate($user, $school);
        $this->createLibraryTitle($school, ['title' => 'Charlotte\'s Web']);

        $this->get('/app/library/titles')->assertInertia(fn ($page) => $page
            ->component('App/Library/Catalogue/Index')
            ->where('canManage', true)
            ->has('titles.data', 1)
        );
    }

    #[Test]
    public function a_member_without_catalogue_view_is_forbidden(): void
    {
        $user = $this->createUser();
        $school = $this->createSchool();
        $this->createMembership($user, $school);
        $this->activate($user, $school);

        $this->get('/app/library/titles')->assertForbidden();
    }

    #[Test]
    public function a_school_admin_can_register_a_title_via_the_ui(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);

        $response = $this->post('/app/library/titles', ['title' => 'Matilda', 'author' => 'Roald Dahl']);

        $response->assertRedirect();
        $created = app(TenantContext::class)->withSchool($school, fn () => LibraryTitle::query()->where('title', 'Matilda')->first());
        $this->assertNotNull($created);
    }

    private function grantOnly(School $school, User $user, string $roleKey, string $capability): void
    {
        $membership = $this->createMembership($user, $school);
        $role = LocalCatalogueFixtures::asOwner(fn () => Role::query()->create(['key' => $roleKey, 'name' => 'Test', 'scope' => 'school', 'is_system' => false]));
        LocalCatalogueFixtures::asOwner(fn () => $role->capabilities()->sync([$capability]));
        app(TenantContext::class)->withSchool($school, fn () => LocalCatalogueFixtures::asOwner(fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $school->id,
            'school_membership_id' => $membership->id,
            'role_id' => $role->id,
        ])));
    }

    #[Test]
    public function a_view_only_member_cannot_register_a_title(): void
    {
        $school = $this->createSchool();
        $viewer = $this->createUser();
        $this->grantOnly($school, $viewer, 'test_library_viewer_ui', 'library.catalogue.view');
        $this->activate($viewer, $school);

        $this->post('/app/library/titles', ['title' => 'Denied'])->assertForbidden();
        $exists = app(TenantContext::class)->withSchool($school, fn () => LibraryTitle::query()->where('title', 'Denied')->exists());
        $this->assertFalse($exists);
    }

    #[Test]
    public function a_school_admin_can_view_a_title_with_its_copies(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $title = $this->createLibraryTitle($school);
        $this->createLibraryCopy($title, ['code' => 'COPY-1']);

        $this->get("/app/library/titles/{$title->id}")->assertInertia(fn ($page) => $page
            ->component('App/Library/Catalogue/Show')
            ->has('copies', 1)
            ->where('copies.0.code', 'COPY-1')
            ->where('copies.0.available', true)
        );
    }

    #[Test]
    public function a_school_admin_can_register_a_copy_via_the_ui(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $title = $this->createLibraryTitle($school);

        $response = $this->post("/app/library/titles/{$title->id}/copies", ['code' => 'UI-COPY-1']);

        $response->assertRedirect();
        $created = app(TenantContext::class)->withSchool($school, fn () => LibraryCopy::query()->where('code', 'UI-COPY-1')->where('library_title_id', $title->id)->first());
        $this->assertNotNull($created);
    }

    // --- Circulation --------------------------------------------------------

    #[Test]
    public function a_member_with_circulation_view_sees_the_active_loans_index(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);
        $student = $this->createStudent($school, ['status' => 'active']);
        $this->createLibraryLoan($copy, $student);

        $this->get('/app/library/circulation')->assertInertia(fn ($page) => $page
            ->component('App/Library/Circulation/Index')
            ->where('canManage', true)
            ->has('loans.data', 1)
        );
    }

    #[Test]
    public function a_school_admin_can_check_out_and_check_in_via_the_ui(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);
        $student = $this->createStudent($school, ['status' => 'active']);

        $checkout = $this->post('/app/library/circulation', [
            'library_copy_id' => $copy->id,
            'student_id' => $student->id,
            'due_at' => now()->addDays(14)->toIso8601String(),
        ]);
        $checkout->assertRedirect('/app/library/circulation');
        $loan = app(TenantContext::class)->withSchool($school, fn () => LibraryLoan::query()->where('library_copy_id', $copy->id)->first());
        $this->assertNotNull($loan);
        $this->assertSame('active', $loan->status);

        $checkIn = $this->post("/app/library/circulation/{$loan->id}/check-in");
        $checkIn->assertRedirect('/app/library/circulation');
        $returned = app(TenantContext::class)->withSchool($school, fn () => $loan->fresh());
        $this->assertSame('returned', $returned->status);
    }

    #[Test]
    public function checking_out_an_already_loaned_copy_via_the_ui_shows_a_validation_error(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);
        $studentOne = $this->createStudent($school, ['status' => 'active']);
        $studentTwo = $this->createStudent($school, ['status' => 'active']);

        $this->post('/app/library/circulation', [
            'library_copy_id' => $copy->id, 'student_id' => $studentOne->id, 'due_at' => now()->addDays(14)->toIso8601String(),
        ])->assertRedirect();

        $second = $this->post('/app/library/circulation', [
            'library_copy_id' => $copy->id, 'student_id' => $studentTwo->id, 'due_at' => now()->addDays(14)->toIso8601String(),
        ]);
        $second->assertSessionHasErrors('library_copy_id');
    }

    #[Test]
    public function a_view_only_circulation_member_cannot_check_out(): void
    {
        $school = $this->createSchool();
        $viewer = $this->createUser();
        $this->grantOnly($school, $viewer, 'test_circulation_viewer_ui', 'library.circulation.view');
        $this->activate($viewer, $school);
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);
        $student = $this->createStudent($school, ['status' => 'active']);

        $this->post('/app/library/circulation', [
            'library_copy_id' => $copy->id, 'student_id' => $student->id, 'due_at' => now()->addDays(14)->toIso8601String(),
        ])->assertForbidden();
    }

    /**
     * Security-review regression (docs/modules/LIBRARY.md "Security
     * review" P1): the checkout form's live search endpoints must
     * enforce library.circulation.manage exactly like the checkout
     * action itself -- an earlier revision omitted this, which would
     * have let any authenticated School member (regardless of Library
     * capability) enumerate Student name/number.
     */
    #[Test]
    public function a_member_without_any_library_capability_cannot_use_the_checkout_search_endpoints(): void
    {
        $school = $this->createSchool();
        $user = $this->createUser();
        $this->createMembership($user, $school);
        $this->activate($user, $school);
        $title = $this->createLibraryTitle($school);
        $this->createLibraryCopy($title);
        $this->createStudent($school, ['status' => 'active', 'first_name' => 'Findable']);

        $this->get('/app/library/circulation/search/copies?q=a')->assertForbidden();
        $this->get('/app/library/circulation/search/students?q=Findable')->assertForbidden();
    }

    #[Test]
    public function a_circulation_manage_member_can_use_the_checkout_search_endpoints(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->activate($user, $school);
        $title = $this->createLibraryTitle($school);
        $this->createLibraryCopy($title, ['code' => 'SEARCHABLE-1']);
        $this->createStudent($school, ['status' => 'active', 'first_name' => 'Findable']);

        $this->get('/app/library/circulation/search/copies?q=SEARCHABLE')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->get('/app/library/circulation/search/students?q=Findable')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
