<?php

namespace Tests\Feature\StudentGuardianIdentity;

use App\Domain\Communications\Application\Channels\GuardianEmailAddressResolver;
use App\Domain\Guardians\Application\Exceptions\GuardianContactConflictException;
use App\Domain\Guardians\Application\GuardianContactService;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\GuardianContact;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Privacy\ContactLookupHasher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 2.1 (MC-4): an existing Guardian contact can be corrected (value and
 * label) and a deactivated one explicitly reactivated -- the same row, every
 * current rule re-run, ciphertext/digest/key version written together, audited
 * by field name only. ADR 0041 holds: no new lookup or search, one row per
 * value per Guardian and type, households may share a value.
 */
class GuardianContactCorrectionTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Guardian $guardian;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->guardian = $this->createGuardian($this->school, ['first_name' => 'Priya', 'last_name' => 'Sharma']);
        $this->admin = $this->createUserWithCapabilities($this->school, ['guardians.view', 'guardians.manage']);
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    private function fresh(GuardianContact $contact): GuardianContact
    {
        return $this->inSchool(fn () => GuardianContact::query()->findOrFail($contact->id));
    }

    private function service(): GuardianContactService
    {
        return app(GuardianContactService::class);
    }

    private function correct(GuardianContact $contact, string $value, ?string $label = null): GuardianContact
    {
        return $this->service()->correct($this->fresh($contact), $value, $label, GuardianContactService::version($this->fresh($contact)), $this->admin);
    }

    /** The Communications destination for this Guardian, resolved inside the School. */
    private function resolvedEmail(): ?string
    {
        return $this->inSchool(fn () => app(GuardianEmailAddressResolver::class)->resolve($this->guardian->fresh()));
    }

    private function deactivate(GuardianContact $contact): void
    {
        $this->service()->deactivate($this->fresh($contact), $this->admin);
    }

    /** @return list<string> ids of Guardians the exact-match lookup returns */
    private function candidates(ContactType $type, string $value): array
    {
        return $this->service()->findCandidatesBySchool($this->school, $type, $value)->pluck('id')->all();
    }

    private function refused(string $code, callable $operation): GuardianContactConflictException
    {
        try {
            $operation();
            $this->fail("expected {$code}");
        } catch (GuardianContactConflictException $e) {
            $this->assertSame($code, $e->errorCode());

            return $e;
        }
    }

    // --- Correction ---------------------------------------------------------------

    #[Test]
    public function an_active_email_is_corrected_with_a_coherent_digest_and_the_old_value_stops_matching(): void
    {
        $contact = $this->createGuardianContact($this->guardian, ContactType::Email, 'priya.sharma@exmaple.com', ['is_primary' => true, 'label' => 'Home']);
        $this->inSchool(fn () => GuardianContact::query()->whereKey($contact->id)->update(['verified_at' => now()]));

        $corrected = $this->correct($contact, ' Priya.Sharma@Example.com ', 'Home');

        $hasher = app(ContactLookupHasher::class);
        $this->assertSame('priya.sharma@example.com', $corrected->encrypted_value);
        $this->assertSame($hasher->hash($this->school->id, 'email', 'priya.sharma@example.com'), $corrected->lookup_hash);
        $this->assertSame($hasher->keyVersion(), $corrected->lookup_key_version);
        $this->assertSame([true, true, null, 'Home'], [$corrected->is_active, $corrected->is_primary, $corrected->verified_at, $corrected->label]);
        $this->assertSame([], $this->candidates(ContactType::Email, 'priya.sharma@exmaple.com'), 'the old value no longer matches');
        $this->assertSame([$this->guardian->id], $this->candidates(ContactType::Email, 'priya.sharma@example.com'));
        $this->assertSame(1, $this->inSchool(fn () => GuardianContact::query()->count()), 'corrected in place, no new row');

        $event = $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', 'guardian_contact.corrected')->sole());
        $this->assertEquals(['guardianId' => $this->guardian->id, 'type' => 'email', 'changed' => ['value'], 'isActive' => true], $event->metadata);
        $raw = DB::connection('pgsql_admin')->table('school_audit_events')->where('id', $event->id)->value('metadata');
        foreach (['priya', 'exmaple', $corrected->lookup_hash] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $raw);
        }
        $stored = DB::connection('pgsql_admin')->table('guardian_contacts')->where('id', $contact->id)->value('encrypted_value');
        $this->assertStringNotContainsString('priya', (string) $stored, 'the value is stored encrypted');
    }

    #[Test]
    public function a_mobile_is_normalized_and_an_invalid_value_changes_nothing(): void
    {
        $contact = $this->createGuardianContact($this->guardian, ContactType::Mobile, '+919876543210');

        $this->assertSame('+919876543211', $this->correct($contact, '+919876543211')->encrypted_value);

        $this->assertThrows(fn () => $this->correct($contact, '98765'), InvalidArgumentException::class);
        $this->assertSame('+919876543211', $this->fresh($contact)->encrypted_value);
    }

    #[Test]
    public function a_label_only_correction_keeps_the_value_and_its_verification(): void
    {
        $contact = $this->createGuardianContact($this->guardian, ContactType::Email, 'parent@example.com', ['label' => 'Wrok']);
        $this->inSchool(fn () => GuardianContact::query()->whereKey($contact->id)->update(['verified_at' => now()]));

        $corrected = $this->correct($contact, 'parent@example.com', 'Work');

        $this->assertSame(['Work', 'parent@example.com'], [$corrected->label, $corrected->encrypted_value]);
        $this->assertNotNull($corrected->verified_at);
    }

    #[Test]
    public function one_row_per_value_per_guardian_households_may_share(): void
    {
        $home = $this->createGuardianContact($this->guardian, ContactType::Email, 'home@example.com');
        $old = $this->createGuardianContact($this->guardian, ContactType::Email, 'old@example.com');
        $typo = $this->createGuardianContact($this->guardian, ContactType::Email, 'typo@example.com');
        $this->deactivate($old);

        $this->assertSame('This Guardian already has this email address.', $this->refused('GUARDIAN_CONTACT_DUPLICATE', fn () => $this->correct($typo, 'home@example.com'))->getMessage());
        $this->assertSame('This Guardian already has this email address as an inactive contact. Reactivate that contact instead.', $this->refused('GUARDIAN_CONTACT_DUPLICATE', fn () => $this->correct($typo, 'OLD@example.com'))->getMessage());
        $this->assertSame('typo@example.com', $this->fresh($typo)->encrypted_value);
        $this->assertSame('home@example.com', $this->fresh($home)->encrypted_value);

        // Another Guardian of the household may hold the same address.
        $partner = $this->createGuardian($this->school);
        $theirs = $this->createGuardianContact($partner, ContactType::Email, 'theirs@example.com');
        $this->assertSame('home@example.com', $this->correct($theirs, 'home@example.com')->encrypted_value);
        $this->assertEqualsCanonicalizing([$this->guardian->id, $partner->id], $this->candidates(ContactType::Email, 'home@example.com'));
    }

    #[Test]
    public function a_stale_form_is_refused_including_after_a_deactivation(): void
    {
        $contact = $this->createGuardianContact($this->guardian, ContactType::Email, 'parent@example.com');
        $opened = GuardianContactService::version($this->fresh($contact));
        $this->deactivate($contact); // within the same second: the version still differs

        $this->refused('GUARDIAN_CONTACT_CHANGED', fn () => $this->service()->correct($this->fresh($contact), 'new@example.com', null, $opened, $this->admin));
        $this->assertSame(['parent@example.com', false], [$this->fresh($contact)->encrypted_value, $this->fresh($contact)->is_active]);
    }

    #[Test]
    public function an_inactive_contact_can_be_corrected_and_stays_inactive_and_unused(): void
    {
        $contact = $this->createGuardianContact($this->guardian, ContactType::Email, 'wrnog@example.com', ['is_primary' => true]);
        $this->deactivate($contact);

        $corrected = $this->correct($contact, 'right@example.com');

        $this->assertSame([false, false, 'right@example.com'], [$corrected->is_active, $corrected->is_primary, $corrected->encrypted_value]);
        $this->assertNull($this->resolvedEmail(), 'an inactive contact is never a destination');
    }

    // --- Reactivation --------------------------------------------------------------

    #[Test]
    public function reactivation_brings_back_the_same_row_and_it_is_used_again(): void
    {
        $contact = $this->createGuardianContact($this->guardian, ContactType::Email, 'parent@example.com', ['is_primary' => true]);
        $this->deactivate($contact);
        $this->assertNull($this->resolvedEmail());

        $reactivated = $this->service()->reactivate($this->fresh($contact), $this->admin);

        $this->assertSame([$contact->id, true, false], [$reactivated->id, $reactivated->is_active, $reactivated->is_primary]);
        $this->assertSame(1, $this->inSchool(fn () => GuardianContact::query()->count()), 'no new row');
        $this->assertSame('parent@example.com', $this->resolvedEmail());
        $event = $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', 'guardian_contact.reactivated')->sole());
        $this->assertEquals(['guardianId' => $this->guardian->id, 'type' => 'email'], $event->metadata);
    }

    #[Test]
    public function a_second_reactivation_is_refused(): void
    {
        $contact = $this->createGuardianContact($this->guardian, ContactType::Email, 'parent@example.com');
        $this->deactivate($contact);
        $this->service()->reactivate($this->fresh($contact), $this->admin);

        $this->refused('GUARDIAN_CONTACT_ALREADY_ACTIVE', fn () => $this->service()->reactivate($this->fresh($contact), $this->admin));
        $this->assertSame(1, $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', 'guardian_contact.reactivated')->count()));
    }

    #[Test]
    public function reactivation_re_hashes_with_the_current_key_version(): void
    {
        $contact = $this->createGuardianContact($this->guardian, ContactType::Email, 'parent@example.com');
        $this->deactivate($contact);
        $this->inSchool(fn () => GuardianContact::query()->whereKey($contact->id)->update(['lookup_hash' => str_repeat('0', 64), 'lookup_key_version' => 0]));
        $this->assertSame([], $this->candidates(ContactType::Email, 'parent@example.com'));

        $reactivated = $this->service()->reactivate($this->fresh($contact), $this->admin);

        $this->assertSame(app(ContactLookupHasher::class)->keyVersion(), $reactivated->lookup_key_version);
        $this->assertSame([$this->guardian->id], $this->candidates(ContactType::Email, 'parent@example.com'));
    }

    #[Test]
    public function a_value_no_longer_valid_must_be_corrected_before_reactivation(): void
    {
        $contact = $this->createGuardianContact($this->guardian, ContactType::Mobile, '+919876543210');
        $this->deactivate($contact);
        $this->inSchool(fn () => GuardianContact::query()->findOrFail($contact->id)->forceFill(['encrypted_value' => '12345'])->save());

        $this->refused('GUARDIAN_CONTACT_NO_LONGER_VALID', fn () => $this->service()->reactivate($this->fresh($contact), $this->admin));
        $this->assertFalse($this->fresh($contact)->is_active);

        $this->correct($contact, '+919876543219');
        $this->assertTrue($this->service()->reactivate($this->fresh($contact), $this->admin)->is_active);
    }

    #[Test]
    public function a_shared_guardian_is_corrected_once_for_every_linked_student(): void
    {
        $first = $this->createStudent($this->school);
        $second = $this->createStudent($this->school);
        $this->createStudentGuardianRelationship($first, $this->guardian, ['relationship_type' => 'mother']);
        $this->createStudentGuardianRelationship($second, $this->guardian, ['relationship_type' => 'mother']);
        $contact = $this->createGuardianContact($this->guardian, ContactType::Email, 'typo@example.com', ['is_primary' => true]);

        $this->correct($contact, 'priya@example.com');

        $this->assertSame(1, $this->inSchool(fn () => GuardianContact::query()->count()));
        $manager = $this->createUserWithCapabilities($this->school, ['students.view', 'guardians.view']);
        $this->actingAs($manager)->post("/app/schools/{$this->school->id}/activate");
        foreach ([$first, $second] as $student) {
            $this->get("/app/students/{$student->id}")->assertInertia(fn ($page) => $page->where('relationships.0.contact.value', 'priya@example.com'));
        }
    }

    #[Test]
    public function another_schools_contact_is_never_reached(): void
    {
        $other = $this->createSchool();
        $foreign = $this->createGuardianContact($this->createGuardian($other), ContactType::Email, 'parent@example.com');

        $this->assertNull($this->inSchool(fn () => GuardianContact::query()->find($foreign->id)), 'RLS and SchoolScope hide it');
        $this->assertSame([], $this->candidates(ContactType::Email, 'parent@example.com'), 'the same value in another School never matches here');
    }

    // --- Browser surface -----------------------------------------------------------------

    private function signIn(User $user): void
    {
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
    }

    #[Test]
    public function the_correction_page_corrects_and_shows_errors_where_they_belong(): void
    {
        $contact = $this->createGuardianContact($this->guardian, ContactType::Email, 'typo@exmaple.com');
        $this->createGuardianContact($this->guardian, ContactType::Email, 'taken@example.com');
        $this->signIn($this->admin);
        $page = "/app/contacts/{$contact->id}/edit";
        $version = fn () => GuardianContactService::version($this->fresh($contact));

        $this->get($page)->assertInertia(fn ($p) => $p->component('App/Guardians/ContactEdit')
            ->where('contact.value', 'typo@exmaple.com')->where('contact.isActive', true)->has('contact.version')
            ->missing('contact.lookupHash')->missing('contact.encryptedValue'));

        $this->from($page)->put("/app/contacts/{$contact->id}", ['value' => 'not-an-email', 'expected_version' => $version()])
            ->assertRedirect($page)->assertSessionHasErrors('value');
        $this->from($page)->put("/app/contacts/{$contact->id}", ['value' => 'taken@example.com', 'expected_version' => $version()])
            ->assertRedirect($page)->assertSessionHasErrors(['refusal' => 'This Guardian already has this email address.']);
        $this->from($page)->put("/app/contacts/{$contact->id}", ['value' => 'priya@example.com', 'expected_version' => $version(), 'school_id' => (string) Str::uuid(), 'is_active' => false, 'type' => 'mobile'])
            ->assertRedirect("/app/guardians/{$this->guardian->id}")->assertSessionHasNoErrors();

        $row = $this->fresh($contact);
        $this->assertSame(['priya@example.com', true, ContactType::Email, $this->school->id], [$row->encrypted_value, $row->is_active, $row->type, $row->school_id]);
    }

    #[Test]
    public function the_guardian_page_lists_inactive_contacts_and_reactivates_one(): void
    {
        $contact = $this->createGuardianContact($this->guardian, ContactType::Email, 'parent@example.com');
        $this->deactivate($contact);
        $this->signIn($this->admin);

        $this->get("/app/guardians/{$this->guardian->id}")->assertInertia(fn ($p) => $p->where('contacts.0.isActive', false)->where('contacts.0.value', 'parent@example.com'));

        $this->from("/app/guardians/{$this->guardian->id}")->post("/app/contacts/{$contact->id}/reactivate")
            ->assertRedirect("/app/guardians/{$this->guardian->id}")->assertSessionHasNoErrors();
        $this->assertTrue($this->fresh($contact)->is_active);

        $this->from("/app/guardians/{$this->guardian->id}")->post("/app/contacts/{$contact->id}/reactivate")
            ->assertSessionHasErrors(['refusal' => 'This contact is already active.']);
    }

    #[Test]
    public function viewers_other_schools_and_malformed_ids_get_nothing(): void
    {
        $contact = $this->createGuardianContact($this->guardian, ContactType::Email, 'parent@example.com');
        $this->deactivate($contact);
        $viewer = $this->createUserWithCapabilities($this->school, ['guardians.view', 'students.view']);
        $this->signIn($viewer);
        $this->get("/app/contacts/{$contact->id}/edit")->assertForbidden();
        $this->put("/app/contacts/{$contact->id}", ['value' => 'x@example.com', 'expected_version' => 'x'])->assertForbidden();
        $this->post("/app/contacts/{$contact->id}/reactivate")->assertForbidden();

        $foreign = $this->createGuardianContact($this->createGuardian($this->createSchool()), ContactType::Email, 'secret@example.com');
        $this->signIn($this->admin);
        $this->get("/app/contacts/{$foreign->id}/edit")->assertNotFound()->assertDontSee('secret@example.com');
        $this->put("/app/contacts/{$foreign->id}", ['value' => 'x@example.com', 'expected_version' => 'x'])->assertNotFound();
        $this->post("/app/contacts/{$foreign->id}/reactivate")->assertNotFound();
        $this->get('/app/contacts/not-a-uuid/edit')->assertNotFound();
        $this->post('/app/contacts/not-a-uuid/reactivate')->assertNotFound();
        $this->assertFalse($this->fresh($contact)->is_active);
    }

    #[Test]
    public function no_new_contact_lookup_or_search_surface_exists(): void
    {
        $contactRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'app.contacts.'))
            ->map(fn ($r) => $r->getName())->sort()->values()->all();

        $this->assertSame(['app.contacts.deactivate', 'app.contacts.edit', 'app.contacts.primary', 'app.contacts.reactivate', 'app.contacts.update'], $contactRoutes);
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $this->assertDoesNotMatchRegularExpression('#contacts?/(search|lookup|find)#', $route->uri(), 'ADR 0041: no contact search surface');
        }
    }
}
