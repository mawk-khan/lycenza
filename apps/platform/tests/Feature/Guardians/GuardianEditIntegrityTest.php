<?php

namespace Tests\Feature\Guardians;

use App\Domain\Guardians\Application\Exceptions\GuardianProfileChangedException;
use App\Domain\Guardians\Application\GuardianProfileVersion;
use App\Domain\Guardians\Application\GuardianService;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS.HARDEN-1 (SIS.EXP-R1 finding R-1): the staff Guardian name edit gets
 * the Student profile's integrity contract -- a keyed stale-form token, the
 * Guardian row locked and authority re-read under the lock, a stale form
 * refused with nothing written, and `changedFields` naming only the fields
 * whose canonical stored value changed.
 */
class GuardianEditIntegrityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $admin;

    private Guardian $guardian;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->admin, $this->school] = $this->createSchoolAdmin('school_admin');
        $this->guardian = $this->createGuardian($this->school, ['first_name' => 'Lakshmi', 'middle_name' => null, 'last_name' => 'Menon']);
    }

    private function asEditor(User $user): void
    {
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
    }

    private function editForm(User $user): string
    {
        $this->asEditor($user);

        return $this->get("/app/guardians/{$this->guardian->id}/edit")->assertOk()->viewData('page')['props']['version'];
    }

    private function fresh(): Guardian
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => Guardian::query()->findOrFail($this->guardian->id));
    }

    /** @return list<list<string>> the changedFields of every guardian.updated audit, oldest first */
    private function updateAudits(): array
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'guardian.updated')->where('subject_id', $this->guardian->id)->orderBy('occurred_at')->orderBy('id')->get()
            ->map(fn (SchoolAuditEvent $e) => $e->metadata['changedFields'])->all());
    }

    private function save(string $version, array $names): TestResponse
    {
        return $this->put("/app/guardians/{$this->guardian->id}", [...$names, 'expected_version' => $version]);
    }

    #[Test]
    public function a_stale_second_tab_is_refused_and_the_first_change_survives(): void
    {
        $other = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($other, $this->school), $this->createFixtureRole(['guardians.view', 'guardians.manage']));

        $tabA = $this->editForm($this->admin);
        $tabB = $this->editForm($other);
        $this->assertSame($tabA, $tabB, 'the same state gives the same token');

        $this->asEditor($this->admin);
        $this->save($tabA, ['first_name' => 'Lakshmi', 'last_name' => 'Menon-Rao'])->assertRedirect("/app/guardians/{$this->guardian->id}")->assertSessionHasNoErrors();

        $this->asEditor($other);
        $this->from("/app/guardians/{$this->guardian->id}/edit")
            ->save($tabB, ['first_name' => 'Laxmi', 'last_name' => 'Menon'])
            ->assertRedirect("/app/guardians/{$this->guardian->id}/edit")
            ->assertSessionHasErrors(['refusal' => (new GuardianProfileChangedException)->getMessage()]);

        $this->assertSame(['Lakshmi', 'Menon-Rao'], [$this->fresh()->first_name, $this->fresh()->last_name], 'A\'s change survives');
        $this->assertSame([['last_name']], $this->updateAudits(), 'only A\'s save is audited; the refused save writes nothing');
    }

    #[Test]
    public function the_token_is_keyed_and_required(): void
    {
        $version = GuardianProfileVersion::of($this->guardian);
        $this->assertSame(64, strlen($version));
        foreach (['Lakshmi', 'Menon', $this->guardian->id] as $value) {
            $this->assertStringNotContainsString($value, $version);
        }
        $unkeyed = hash('sha256', json_encode([$this->guardian->id, 'Lakshmi', null, 'Menon']));
        $this->assertNotSame($unkeyed, $version, 'never a plain digest anyone could recompute from the visible names');
        $joined = $this->guardian->replicate()->forceFill(['first_name' => "Lakshmi\x1fX", 'middle_name' => null]);
        $split = $this->guardian->replicate()->forceFill(['first_name' => 'Lakshmi', 'middle_name' => 'X']);
        $joined->id = $split->id = $this->guardian->id;
        $this->assertNotSame(GuardianProfileVersion::of($joined), GuardianProfileVersion::of($split), 'no separator collision');
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->assertNotSame($version, GuardianProfileVersion::of($this->guardian), 'keyed by the application key');

        $this->asEditor($this->admin);
        $this->put("/app/guardians/{$this->guardian->id}", ['first_name' => 'X'])->assertSessionHasErrors('expected_version');
        $this->save(str_repeat('0', 64), ['first_name' => 'X'])->assertSessionHasErrors('refusal');
        $this->assertSame('Lakshmi', $this->fresh()->first_name);
    }

    #[Test]
    public function the_audit_names_only_fields_whose_canonical_value_changed(): void
    {
        $service = app(GuardianService::class);

        $service->update($this->guardian, ['first_name' => 'Lakshmi', 'middle_name' => null, 'last_name' => 'Iyer'], $this->admin, GuardianProfileVersion::of($this->guardian));
        $this->assertSame([['last_name']], $this->updateAudits(), 'only last_name');

        // Unchanged, and equivalent after canonicalisation: whitespace, '' for a null middle name.
        $version = GuardianProfileVersion::of($this->fresh());
        $service->update($this->fresh(), ['first_name' => '  Lakshmi ', 'middle_name' => '', 'last_name' => "Iyer\u{00A0}"], $this->admin, $version);
        $service->update($this->fresh(), ['first_name' => 'Lakshmi', 'middle_name' => '   ', 'last_name' => 'Iyer'], $this->admin);
        $this->assertSame([['last_name']], $this->updateAudits(), 'no false change audit');
        $this->assertSame($version, GuardianProfileVersion::of($this->fresh()), 'an unchanged save never advances the version');
        $this->assertNull($this->fresh()->middle_name);

        // A field outside the identity whitelist is ignored, never written.
        $service->update($this->fresh(), ['first_name' => 'Lakshmi', 'status' => 'inactive', 'school_id' => (string) $this->createSchool()->id], $this->admin);
        $this->assertSame(['active', $this->school->id], [$this->fresh()->status, $this->fresh()->school_id]);
    }

    #[Test]
    public function authority_is_re_read_under_the_lock(): void
    {
        $editor = $this->createUser();
        $membership = $this->createMembership($editor, $this->school);
        LocalCatalogueFixtures::grantRole($membership, $this->createFixtureRole(['guardians.view', 'guardians.manage']));
        $version = GuardianProfileVersion::of($this->guardian);
        $membership->forceFill(['status' => 'suspended'])->save();

        $this->expectException(AuthorizationException::class);
        try {
            app(GuardianService::class)->update($this->guardian, ['first_name' => 'Changed'], $editor, $version);
        } finally {
            $this->assertSame('Lakshmi', $this->fresh()->first_name);
        }
    }

    #[Test]
    public function another_schools_guardian_stays_not_found_and_the_api_keeps_its_contract(): void
    {
        $elsewhere = $this->createGuardian($this->createSchool(), ['first_name' => 'Foreign']);
        $this->asEditor($this->admin);
        $this->get("/app/guardians/{$elsewhere->id}/edit")->assertNotFound();
        $this->put("/app/guardians/{$elsewhere->id}", ['first_name' => 'Hacked', 'expected_version' => GuardianProfileVersion::of($elsewhere)])->assertNotFound();

        // The API PATCH gets the lock and the precise audit, with no token (unchanged contract, like Students).
        $token = $this->admin->createToken('test-device')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/schools/{$this->school->id}/guardians/{$this->guardian->id}", ['first_name' => 'Lakshmi', 'last_name' => 'Nair'])
            ->assertOk()->assertJsonPath('data.lastName', 'Nair');
        $this->assertSame([['last_name']], $this->updateAudits());
    }

    #[Test]
    public function people_without_whole_school_guardians_manage_cannot_edit(): void
    {
        $viewer = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($viewer, $this->school), $this->createFixtureRole(['guardians.view']));
        $this->asEditor($viewer);
        $this->get("/app/guardians/{$this->guardian->id}/edit")->assertForbidden();
        $this->put("/app/guardians/{$this->guardian->id}", ['first_name' => 'X', 'expected_version' => GuardianProfileVersion::of($this->guardian)])->assertForbidden();

        $this->actingAs($this->createPlatformRoot());
        $this->assertNotSame(200, $this->get("/app/guardians/{$this->guardian->id}/edit")->status());
        $this->assertSame('Lakshmi', $this->fresh()->first_name);
    }
}
