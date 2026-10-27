<?php

namespace Tests\Feature\Guardians;

use App\Domain\Guardians\Application\GuardianContactService;
use App\Domain\Guardians\Application\GuardianNameSearch;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\GuardianContact;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Models\School;
use App\Models\User;
use App\Support\Http\WebDomainRefusalResponder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 2.2: Guardian identity search, contact safety and route hardening.
 *
 * - Search: every word in the first, middle or last name (the shared
 *   NameSearch rule), any order, case- and space-insensitive, wildcards
 *   literal; names only -- a mobile number or email never finds a Guardian
 *   (ADR 0041); never another School's Guardian; guardians.view required.
 * - Only an ACTIVE contact may become primary; a reactivated contact
 *   returns non-primary; one active primary per Guardian and type.
 * - Every Guardian / contact / relationship record route answers a
 *   malformed id with 404, never a database error.
 * - The mobile example the forms show is valid input; an invalid value's
 *   error never repeats it.
 */
class GuardianSearchAndSafetyTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function in(School $school, callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($school, $fn);
    }

    private function web(School $school, array $capabilities = ['guardians.view', 'guardians.manage']): User
    {
        $user = $this->createUserWithCapabilities($school, $capabilities);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    /** @return list<string> Guardian ids the web directory finds for $name */
    private function search(string $name): array
    {
        return array_column($this->get('/app/guardians?'.http_build_query(['name' => $name]))->assertOk()->viewData('page')['props']['guardians']['data'], 'id');
    }

    // --- Search -----------------------------------------------------------------------------

    #[Test]
    public function a_full_name_finds_the_guardian_in_any_order_case_and_spacing(): void
    {
        $school = $this->createSchool();
        $aisha = $this->createGuardian($school, ['first_name' => 'Aisha', 'middle_name' => 'Noor', 'last_name' => 'Khan']);
        $aishaB = $this->createGuardian($school, ['first_name' => 'Aisha', 'middle_name' => null, 'last_name' => 'Rao']);
        $imran = $this->createGuardian($school, ['first_name' => 'Imran', 'middle_name' => null, 'last_name' => 'Khan']);
        $this->createGuardian($this->createSchool(), ['first_name' => 'Aisha', 'last_name' => 'Khan']);
        $this->web($school);

        foreach (['Aisha Khan', 'khan aisha', '  AISHA   khan ', 'Aisha Noor Khan', 'noor'] as $term) {
            $this->assertSame([$aisha->id], $this->search($term), $term);
        }
        $this->assertEqualsCanonicalizing([$aisha->id, $aishaB->id], $this->search('aisha'));
        $this->assertEqualsCanonicalizing([$aisha->id, $imran->id], $this->search('Khan'), 'never another School\'s namesake');
        $this->assertSame([], $this->search('Aisha Gupta'), 'every word must match');
        $this->assertSame([], $this->search('%'), 'wildcards are literal');
        $this->assertSame([], $this->search('A_sha'));
    }

    #[Test]
    public function a_contact_value_never_finds_a_guardian(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school, ['first_name' => 'Meera', 'last_name' => 'Nair']);
        $this->createGuardianContact($guardian, ContactType::Mobile, '+919876543210');
        $this->createGuardianContact($guardian, ContactType::Email, 'meera.nair@example.com');
        $this->web($school);

        foreach (['+919876543210', '9876543210', '98765', 'meera.nair@example.com', 'example.com', '@example'] as $value) {
            $this->assertSame([], $this->search($value), "the identity search is names only: {$value}");
        }
        $this->assertSame([$guardian->id], $this->search('meera'));

        // Every Guardian name search goes through the one rule, over name columns only.
        $this->assertSame(['guardians.first_name', 'guardians.middle_name', 'guardians.last_name'], GuardianNameSearch::COLUMNS);
        $callers = [];
        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            if (str_contains($file->getContents(), 'GuardianNameSearch::apply(')) {
                $callers[] = $file->getRelativePathname();
            }
        }
        sort($callers);
        $this->assertSame([
            'Domain/Guardians/Http/Controllers/GuardianController.php',
            'Http/Controllers/App/AdmissionApplicationController.php',
            'Http/Controllers/App/GuardianController.php',
            'Http/Controllers/App/StudentGuardianRelationshipController.php',
        ], $callers);
        $source = (string) file_get_contents(app_path('Domain/Guardians/Application/GuardianNameSearch.php'));
        foreach (['encrypted_value', 'lookup_hash', 'guardian_contacts'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, preg_replace('/^\s*\*.*$/m', '', $source), "no contact data in the identity search ({$forbidden})");
        }
    }

    #[Test]
    public function the_pickers_and_the_api_use_the_same_rule_and_the_same_authority(): void
    {
        $school = $this->createSchool();
        $aisha = $this->createGuardian($school, ['first_name' => 'Aisha', 'middle_name' => 'Noor', 'last_name' => 'Khan']);
        $student = $this->createStudent($school);

        $this->web($school, ['students.view', 'students.manage', 'guardians.view', 'guardians.manage']);
        $this->assertSame([$aisha->id], array_column($this->getJson("/app/students/{$student->id}/guardians/search?q=khan%20aisha")->assertOk()->json('data'), 'id'));
        $this->web($school, ['admissions.view', 'admissions.manage']);
        $this->assertSame([$aisha->id], array_column($this->getJson('/app/admissions/guardians/search?q=khan%20aisha')->assertOk()->json('data'), 'id'));

        app('auth')->forgetGuards(); // the bearer token, not the web session, authenticates the API
        $token = $this->createUserWithCapabilities($school, ['guardians.view'])->createToken('t')->plainTextToken;
        $this->assertSame([$aisha->id], array_column($this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/schools/{$school->id}/guardians?name=Aisha%20Khan")->assertOk()->json('data'), 'id'));

        // No guardians.view: no directory at all (a teacher holds none of these).
        $this->flushHeaders();
        $this->web($school, ['students.view']);
        $this->get('/app/guardians?name=Aisha')->assertForbidden();
    }

    // --- Contact safety ---------------------------------------------------------------------

    #[Test]
    public function only_an_active_contact_can_become_primary_and_reactivation_returns_it_non_primary(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);
        $siblingA = $this->createStudent($school);
        $siblingB = $this->createStudent($school);
        $this->createStudentGuardianRelationship($siblingA, $guardian);
        $this->createStudentGuardianRelationship($siblingB, $guardian);
        $primary = $this->createGuardianContact($guardian, ContactType::Mobile, '+919800000001', ['is_primary' => true]);
        $spare = $this->createGuardianContact($guardian, ContactType::Mobile, '+919800000002');
        $service = app(GuardianContactService::class);
        $service->deactivate($this->in($school, fn () => $spare->fresh()));
        $this->web($school);
        $state = fn () => $this->in($school, fn () => GuardianContact::query()->where('guardian_id', $guardian->id)->orderBy('id')->get(['id', 'is_active', 'is_primary'])->map(fn ($c) => [(string) $c->id, $c->is_active, $c->is_primary])->all());
        $before = $state();

        // A forged Make primary on the inactive contact: a refusal, nothing written.
        $this->post("/app/contacts/{$spare->id}/primary")
            ->assertSessionHasErrors([WebDomainRefusalResponder::ERROR_KEY => 'An inactive contact cannot be made primary. Reactivate it first, then make it primary.']);
        $this->assertSame($before, $state());
        $token = $this->createUserWithCapabilities($school, ['guardians.view', 'guardians.manage'])->createToken('t')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}")->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/schools/{$school->id}/guardian-contacts/{$spare->id}/primary")
            ->assertStatus(422)->assertJsonPath('error.code', 'GUARDIAN_CONTACT_INACTIVE');
        $this->assertSame($before, $state());

        // Reactivate (non-primary), then explicitly Make primary: the old primary is demoted.
        $this->flushHeaders();
        $this->web($school);
        $this->post("/app/contacts/{$spare->id}/reactivate")->assertSessionHasNoErrors();
        $this->assertSame([true, false], [$this->in($school, fn () => $spare->fresh()->is_active), $this->in($school, fn () => $spare->fresh()->is_primary)]);
        $this->post("/app/contacts/{$spare->id}/primary")->assertSessionHasNoErrors();
        $this->assertSame([[(string) $primary->id, true, false], [(string) $spare->id, true, true]], $state());

        // One authoritative Guardian: both Student links intact, both see the same contacts.
        $this->assertSame(2, $this->in($school, fn () => StudentGuardianRelationship::query()->where('guardian_id', $guardian->id)->count()));
    }

    #[Test]
    public function a_foreign_or_unauthorized_make_primary_never_changes_anything(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);
        $contact = $this->createGuardianContact($guardian, ContactType::Email, 'one@example.com');
        $other = $this->createSchool();

        $this->web($other);
        $this->post("/app/contacts/{$contact->id}/primary")->assertNotFound();
        $this->flushHeaders();
        $this->web($school, ['guardians.view']);
        $this->post("/app/contacts/{$contact->id}/primary")->assertForbidden();
        $this->assertFalse($this->in($school, fn () => $contact->fresh()->is_primary));
    }

    // --- Route hardening --------------------------------------------------------------------

    #[Test]
    public function every_guardian_contact_and_relationship_route_answers_a_malformed_id_with_404(): void
    {
        $school = $this->createSchool();
        $this->web($school, ['guardians.view', 'guardians.manage', 'students.view', 'students.manage', 'school.members.manage', 'communications.manage']);
        $bad = 'not-a-uuid';

        foreach ([
            ['get', "/app/guardians/{$bad}"], ['get', "/app/guardians/{$bad}/edit"], ['put', "/app/guardians/{$bad}"],
            ['post', "/app/guardians/{$bad}/status"], ['post', "/app/guardians/{$bad}/contacts"],
            ['get', "/app/guardians/{$bad}/account-link/search"], ['post', "/app/guardians/{$bad}/account-link"], ['delete', "/app/guardians/{$bad}/account-link"],
            ['post', "/app/guardians/{$bad}/portal-offboard"],
            ['post', "/app/guardians/{$bad}/account-invitation"], ['post', "/app/guardians/{$bad}/account-invitation/resend"], ['delete', "/app/guardians/{$bad}/account-invitation"],
            ['put', "/app/guardians/{$bad}/communication-preference"], ['post', "/app/guardians/{$bad}/communication-consent"],
            ['post', "/app/contacts/{$bad}/primary"], ['post', "/app/contacts/{$bad}/deactivate"], ['get', "/app/contacts/{$bad}/edit"],
            ['put', "/app/contacts/{$bad}"], ['post', "/app/contacts/{$bad}/reactivate"],
            ['put', "/app/relationships/{$bad}"], ['post', "/app/relationships/{$bad}/primary"], ['delete', "/app/relationships/{$bad}"],
        ] as [$method, $uri]) {
            $this->{$method}($uri)->assertNotFound();
        }

        $token = $this->createUserWithCapabilities($school, ['guardians.view', 'guardians.manage', 'students.view', 'students.manage'])->createToken('t')->plainTextToken;
        foreach ([
            ['getJson', "guardians/{$bad}"], ['patchJson', "guardians/{$bad}"], ['postJson', "guardians/{$bad}/status"], ['postJson', "guardians/{$bad}/contacts"],
            ['postJson', "guardian-contacts/{$bad}/primary"], ['postJson', "guardian-contacts/{$bad}/deactivate"],
            ['patchJson', "student-guardian-relationships/{$bad}"], ['postJson', "student-guardian-relationships/{$bad}/primary"], ['deleteJson', "student-guardian-relationships/{$bad}"],
        ] as [$method, $path]) {
            $this->withHeader('Authorization', "Bearer {$token}")->withHeader('Idempotency-Key', (string) Str::uuid())
                ->{$method}("/api/v1/schools/{$school->id}/{$path}")->assertNotFound();
        }
    }

    // --- Format ------------------------------------------------------------------------------

    #[Test]
    public function the_shown_mobile_example_is_valid_and_an_invalid_value_is_never_echoed(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);
        $this->web($school);

        $this->post("/app/guardians/{$guardian->id}/contacts", ['type' => 'mobile', 'value' => '+919876543210'])->assertSessionHasNoErrors();
        $this->assertSame('+919876543210', $this->in($school, fn () => GuardianContact::query()->where('guardian_id', $guardian->id)->sole()->encrypted_value), 'stored as entered, strict E.164');

        $this->post("/app/guardians/{$guardian->id}/contacts", ['type' => 'mobile', 'value' => '+91 9812345678'])
            ->assertSessionHasErrors(['value' => GuardianContactService::INVALID_MOBILE]);
        $this->post("/app/guardians/{$guardian->id}/contacts", ['type' => 'email', 'value' => 'not-an-email'])
            ->assertSessionHasErrors(['value' => GuardianContactService::INVALID_EMAIL]);
        $this->assertStringNotContainsString('9812345678', GuardianContactService::INVALID_MOBILE);
        $this->assertSame(1, $this->in($school, fn () => GuardianContact::query()->where('guardian_id', $guardian->id)->count()));

        foreach (['resources/js/Pages/App/Guardians/Show.vue', 'resources/js/Pages/App/Guardians/ContactEdit.vue', 'resources/js/Pages/App/Students/AddGuardian.vue'] as $page) {
            $this->assertStringNotContainsString('+91 9876543210', (string) file_get_contents(base_path($page)), "{$page} shows an example the validator refuses");
        }
    }
}
