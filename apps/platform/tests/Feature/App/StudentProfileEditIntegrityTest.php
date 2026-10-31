<?php

namespace Tests\Feature\App;

use App\Domain\Students\Application\Exceptions\StudentProfileChangedException;
use App\Domain\Students\Application\StudentProfileVersion;
use App\Domain\Students\Application\StudentService;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS.EXP-1 (SIS.EXP-0 findings L-2, L-3): a Student identity form opened
 * before someone else's change is refused instead of overwriting it, and
 * `student.updated` names only the fields whose stored value really changed
 * (an unchanged save writes and audits nothing).
 */
class StudentProfileEditIntegrityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $admin;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $this->student = $this->createStudent($this->school, ['student_number' => 'S-1', 'first_name' => 'Asha', 'middle_name' => null, 'last_name' => 'Rao', 'date_of_birth' => '2015-04-01']);
        $this->actingAs($this->admin)->post("/app/schools/{$this->school->id}/activate");
    }

    private function fresh(): Student
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => Student::query()->findOrFail($this->student->id));
    }

    /** @return list<list<string>> changedFields of every student.updated audit, oldest first */
    private function audits(): array
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => SchoolAuditEvent::query()
            ->where('event_type', 'student.updated')->where('subject_id', $this->student->id)
            ->orderBy('occurred_at')->orderBy('id')->get()->map(fn (SchoolAuditEvent $e) => $e->metadata['changedFields'])->all());
    }

    /** @param array<string, string|null> $overrides */
    private function form(string $version, array $overrides = []): array
    {
        return [
            'student_number' => 'S-1', 'first_name' => 'Asha', 'middle_name' => '', 'last_name' => 'Rao', 'date_of_birth' => '2015-04-01',
            'expected_version' => $version, ...$overrides,
        ];
    }

    #[Test]
    public function the_edit_page_carries_the_version_of_what_it_shows(): void
    {
        $this->get("/app/students/{$this->student->id}/edit")->assertOk()
            ->assertInertia(fn ($page) => $page->where('version', StudentProfileVersion::of($this->fresh())));
    }

    #[Test]
    public function a_stale_form_is_refused_and_never_overwrites_the_newer_change(): void
    {
        $opened = StudentProfileVersion::of($this->fresh());
        // Someone else saves first.
        app(StudentService::class)->update($this->fresh(), ['last_name' => 'Rao-Iyer'], $this->admin);

        $this->from("/app/students/{$this->student->id}/edit")
            ->put("/app/students/{$this->student->id}", $this->form($opened, ['first_name' => 'Asha K']))
            ->assertRedirect("/app/students/{$this->student->id}/edit")
            ->assertSessionHasErrors('refusal');

        $this->assertSame(['Asha', 'Rao-Iyer'], [$this->fresh()->first_name, $this->fresh()->last_name], 'nothing of the stale form was written');
        $this->assertSame([['last_name']], $this->audits(), 'the refused save is not audited');

        // The same edit from a reloaded form succeeds.
        $this->put("/app/students/{$this->student->id}", $this->form(StudentProfileVersion::of($this->fresh()), ['first_name' => 'Asha K', 'last_name' => 'Rao-Iyer']))
            ->assertRedirect("/app/students/{$this->student->id}")->assertSessionHasNoErrors();
        $this->assertSame('Asha K', $this->fresh()->first_name);
    }

    #[Test]
    public function the_campus_name_edit_refuses_a_stale_form_too(): void
    {
        $opened = StudentProfileVersion::of($this->fresh());
        app(StudentService::class)->update($this->fresh(), ['first_name' => 'Asha M'], $this->admin);

        $this->from("/app/students/{$this->student->id}/names")
            ->put("/app/students/{$this->student->id}/names", ['first_name' => 'Asha N', 'last_name' => 'Rao', 'expected_version' => $opened])
            ->assertSessionHasErrors('refusal');
        $this->assertSame('Asha M', $this->fresh()->first_name);
        $this->put("/app/students/{$this->student->id}/names", ['first_name' => 'Asha N', 'last_name' => 'Rao'])->assertSessionHasErrors('expected_version');
    }

    #[Test]
    public function the_audit_names_only_fields_whose_stored_value_changed(): void
    {
        $this->put("/app/students/{$this->student->id}", $this->form(StudentProfileVersion::of($this->fresh()), ['last_name' => 'Rao-Iyer']))->assertSessionHasNoErrors();
        $this->assertSame([['last_name']], $this->audits());

        // Unchanged after normalization (trimmed text, empty = null, the same date): nothing written, nothing audited.
        $before = $this->fresh()->updated_at;
        $this->put("/app/students/{$this->student->id}", $this->form(StudentProfileVersion::of($this->fresh()), ['first_name' => '  Asha  ', 'last_name' => 'Rao-Iyer', 'middle_name' => '']))
            ->assertRedirect("/app/students/{$this->student->id}")->assertSessionHasNoErrors();
        $this->assertSame([['last_name']], $this->audits(), 'no misleading second entry');
        $this->assertEquals($before, $this->fresh()->updated_at);

        // The API PATCH gets the same precise audit. SIS 11.H1: it now requires
        // the version it read (a missing one is a 422, a stale one a 409).
        $api = $this->withHeader('Authorization', 'Bearer '.$this->admin->createToken('t')->plainTextToken);
        $api->patchJson("/api/v1/schools/{$this->school->id}/students/{$this->student->id}", ['first_name' => 'Asha', 'date_of_birth' => '2015-04-02'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['version']]]);
        $version = app(TenantContext::class)->withSchool($this->school, fn () => StudentProfileVersion::of($this->student->fresh()));
        $api->patchJson("/api/v1/schools/{$this->school->id}/students/{$this->student->id}", ['first_name' => 'Asha', 'date_of_birth' => '2015-04-02', 'version' => $version])
            ->assertOk();
        $this->assertSame([['last_name'], ['date_of_birth']], $this->audits());
    }

    #[Test]
    public function the_service_refuses_a_stale_version_and_re_reads_authority_under_the_lock(): void
    {
        $this->expectException(StudentProfileChangedException::class);
        try {
            $other = $this->createUserWithCapabilities($this->school, ['students.view']);
            app(StudentService::class)->update($this->fresh(), ['first_name' => 'X'], $other);
            $this->fail('an actor without students.manage now must be refused');
        } catch (AuthorizationException) {
            $this->assertSame('Asha', $this->fresh()->first_name);
        }

        app(StudentService::class)->update($this->fresh(), ['first_name' => 'Y'], $this->admin, str_repeat('0', 64));
    }

    #[Test]
    public function the_version_token_is_keyed_so_the_hidden_date_of_birth_cannot_be_recovered_from_it(): void
    {
        // A Campus name editor sees the id, number and names, never the date of
        // birth. With an unkeyed digest they could try every candidate date.
        $student = $this->fresh();
        $token = StudentProfileVersion::of($student);
        $visible = [$student->id, $student->student_number, $student->first_name, $student->middle_name ?? '', $student->last_name ?? ''];
        foreach (['sha256', 'sha1', 'md5'] as $algorithm) {
            $this->assertNotSame(hash($algorithm, implode("\x1f", [...$visible, '2015-04-01'])), $token, "not a plain {$algorithm} of visible fields plus the date");
        }

        // It still changes when the date of birth changes (the stale check sees it).
        app(StudentService::class)->update($student, ['date_of_birth' => '2015-04-02'], $this->admin);
        $this->assertNotSame($token, StudentProfileVersion::of($this->fresh()));
    }
}
