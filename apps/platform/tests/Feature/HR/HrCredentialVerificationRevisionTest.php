<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeCertificationService;
use App\Domain\HR\Application\EmployeeProfileWorkspaceService;
use App\Domain\HR\Application\EmployeeQualificationService;
use App\Domain\HR\Application\Exceptions\CredentialRevisionChangedException;
use App\Domain\HR\Application\Exceptions\ProfileRecordArchivedException;
use App\Domain\HR\Application\Profile\ProfileRecordArchiveReason;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeCertification;
use App\Domain\HR\Infrastructure\EmployeeQualification;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * HR.R1-C29 (C14-N5, owner decisions C29-A/B/C, HR with Security, 2026-11-12): a Verify or Reject names the
 * revision its decision-maker saw, a changed record is refused, every decision is kept, and a status recorded
 * before version recording is shown as such, never rewritten.
 *
 * On `5be4f9ad` the stale-page case below verified the edited content: verify() took no revision, so a decision
 * made on what the page showed attested whatever the row held when it committed.
 */
class HrCredentialVerificationRevisionTest extends TestCase
{
    use CreatesTenancyFixtures;

    /**
     * Committed, and purged in tearDown: the legacy cases change the guard through the owner connection, which
     * would wait for ever on an open runtime transaction holding the same table.
     *
     * @var array<int, string>
     */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $schools = [];

    private School $school;

    private User $hr;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->hr = $this->fullHrActor($this->school);
        $this->employee = $this->createEmployee($this->school);
    }

    protected function tearDown(): void
    {
        $this->purgeSchoolsAsAdmin([$this->school->id, ...$this->schools]);
        parent::tearDown();
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  T  $model
     * @return T
     */
    private function fresh($model)
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => $model->fresh());
    }

    private function qualification(): EmployeeQualification
    {
        return app(EmployeeQualificationService::class)->add($this->employee, ['qualification_type' => 'bachelors', 'qualification_name' => 'BEd', 'institution' => 'A College'], $this->hr);
    }

    private function certification(): EmployeeCertification
    {
        return app(EmployeeCertificationService::class)->add($this->employee, ['name' => 'First aid', 'issuer' => 'A Board'], $this->hr);
    }

    /** @return array{status: string, revision: int, bound: ?int, verified_at: ?string} */
    private function state(string $table, string $id): array
    {
        $row = DB::connection('pgsql_admin')->table($table)->where('id', $id)->first();

        return ['status' => $row->verification_status, 'revision' => (int) $row->revision, 'bound' => $row->verification_revision === null ? null : (int) $row->verification_revision, 'verified_at' => $row->verified_at];
    }

    /** @return list<array{0: int, 1: string, 2: string}> revision, decision, decider */
    private function decisions(string $column, string $id): array
    {
        return DB::connection('pgsql_admin')->table('employee_credential_verifications')->where($column, $id)->orderBy('sequence')
            ->get(['revision', 'decision', 'decided_by_user_id'])->map(fn ($d) => [(int) $d->revision, (string) $d->decision, (string) $d->decided_by_user_id])->all();
    }

    private function api(): self
    {
        app('auth')->forgetGuards();
        $this->flushHeaders();

        return $this->withHeaders(['Authorization' => 'Bearer '.$this->hr->createToken('c29')->plainTextToken, 'Idempotency-Key' => (string) Str::uuid7()]);
    }

    #[Test]
    public function a_decision_records_the_revision_it_saw_and_sets_the_status_from_it(): void
    {
        $service = app(EmployeeQualificationService::class);
        $row = $this->qualification();
        $this->assertSame(1, $row->revision, 'a credential is born at revision 1');

        $verified = $service->verify($this->employee, $row, $this->hr, 1);

        $this->assertSame(['verified', 1, 1], [$verified->verification_status, $verified->revision, $verified->verification_revision]);
        $this->assertNotNull($verified->verified_at);
        $this->assertSame([[1, 'verified', $this->hr->id]], $this->decisions('qualification_id', $row->id));
        $audit = DB::connection('pgsql_admin')->table('school_audit_events')->where('school_id', $this->school->id)->where('event_type', 'hr.qualification.verified')->sole();
        $metadata = json_decode($audit->metadata, true);
        $this->assertSame(1, $metadata['revision']);
        $this->assertEqualsCanonicalizing(['employeeId', 'qualificationId', 'revision', 'decisionId'], array_keys($metadata), 'ids and the revision only, never a field value');
    }

    /** The C14-N5 reproduction: the page showed revision 1, an API edit landed, and the stale Verify attested revision 2. */
    #[Test]
    public function a_verification_from_a_page_opened_before_an_edit_is_refused(): void
    {
        foreach ([
            ['qualification', $this->qualification(), 'employee_qualifications', 'qualification_id', ['qualification_name' => 'MEd'], EmployeeQualificationService::class],
            ['certification', $this->certification(), 'employee_certifications', 'certification_id', ['issuer' => 'Another Board'], EmployeeCertificationService::class],
        ] as [$kind, $row, $table, $key, $edit, $serviceClass]) {
            $seen = $row->revision;
            $this->api()->patchJson("/api/v1/schools/{$this->school->id}/employees/{$this->employee->id}/{$kind}s/{$row->id}", $edit)->assertOk()->assertJsonPath('data.revision', 2);

            foreach (['verify', 'reject'] as $verb) {
                try {
                    app($serviceClass)->{$verb}($this->employee, $row, $this->hr, $seen);
                    $this->fail("{$kind} {$verb}: a decision on a revision nobody showed was recorded");
                } catch (CredentialRevisionChangedException) {
                }
            }

            $this->assertSame(['status' => 'unverified', 'revision' => 2, 'bound' => null, 'verified_at' => null], $this->state($table, $row->id));
            $this->assertSame([], $this->decisions($key, $row->id), 'nothing was decided');
        }
    }

    #[Test]
    public function a_forged_revision_is_refused_like_a_stale_one(): void
    {
        $row = $this->qualification();
        foreach ([0, 2, 999] as $forged) {
            try {
                app(EmployeeQualificationService::class)->verify($this->employee, $row, $this->hr, $forged);
                $this->fail("revision {$forged} was accepted");
            } catch (CredentialRevisionChangedException) {
            }
        }
        $this->assertSame([], $this->decisions('qualification_id', $row->id));
    }

    #[Test]
    public function an_edit_keeps_the_earlier_decision_and_a_new_verification_binds_the_new_revision(): void
    {
        $service = app(EmployeeCertificationService::class);
        $row = $this->certification();
        $service->verify($this->employee, $row, $this->hr, 1);

        $edited = $service->update($this->employee, $this->fresh($row), ['credential_number' => 'C-2'], $this->hr);
        $this->assertSame(['unverified', 2, null], [$edited->verification_status, $edited->revision, $edited->verification_revision]);
        $this->assertSame([[1, 'verified', $this->hr->id]], $this->decisions('certification_id', $row->id), 'the verification of revision 1 is still recorded');

        $second = $this->fullHrActor($this->school);
        $service->verify($this->employee, $edited, $second, 2);
        $this->assertSame(['status' => 'verified', 'revision' => 2, 'bound' => 2], array_slice($this->state('employee_certifications', $row->id), 0, 3));
        $this->assertSame([[1, 'verified', $this->hr->id], [2, 'verified', $second->id]], $this->decisions('certification_id', $row->id));

        $entry = app(EmployeeProfileWorkspaceService::class)->build($this->school, $this->employee->id, $this->hr)->toArray()['certifications'][0];
        $this->assertSame(2, $entry['revision']);
        $this->assertSame(2, $entry['verification_revision']);
        $this->assertSame([[2, 'verified'], [1, 'verified']], array_map(fn (array $d) => [$d['revision'], $d['decision']], $entry['verification_history']), 'newest first');
        $this->assertSame(['decision', 'revision', 'decided_at'], array_keys($entry['verification_history'][0]), 'never who decided');
    }

    #[Test]
    public function the_same_decision_twice_on_one_revision_records_nothing_new(): void
    {
        $service = app(EmployeeQualificationService::class);
        $row = $this->qualification();
        $service->verify($this->employee, $row, $this->hr, 1);
        $again = $service->verify($this->employee, $this->fresh($row), $this->hr, 1);

        $this->assertSame('verified', $again->verification_status);
        $this->assertCount(1, $this->decisions('qualification_id', $row->id));
        $this->assertSame(1, DB::connection('pgsql_admin')->table('school_audit_events')->where('school_id', $this->school->id)->where('event_type', 'hr.qualification.verified')->count());

        // Changing one's mind on the same revision is a new decision, and both stay recorded.
        $rejected = $service->reject($this->employee, $this->fresh($row), $this->hr, 1);
        $this->assertSame(['rejected', null, 1], [$rejected->verification_status, $rejected->verified_at, $rejected->verification_revision]);
        $this->assertSame([[1, 'verified', $this->hr->id], [1, 'rejected', $this->hr->id]], $this->decisions('qualification_id', $row->id));
    }

    /** Delta review LOW-A: a day sent with a time of day is the same day; it neither resets nor fails. */
    #[Test]
    public function the_same_day_with_a_time_of_day_is_no_edit(): void
    {
        $service = app(EmployeeCertificationService::class);
        $row = app(EmployeeCertificationService::class)->add($this->employee, ['name' => 'First aid', 'issuer' => 'A Board', 'issued_on' => '2020-01-01'], $this->hr);
        $service->verify($this->employee, $row, $this->hr, 1);

        $same = $service->update($this->employee, $this->fresh($row), ['issued_on' => '2020-01-01 10:00:00'], $this->hr);
        $this->assertSame(['verified', 1, 1], [$same->verification_status, $same->revision, $same->verification_revision]);

        $edited = $service->update($this->employee, $this->fresh($row), ['issued_on' => '2020-01-02 00:00:00'], $this->hr);
        $this->assertSame(['unverified', 2, null, '2020-01-02'], [$edited->verification_status, $edited->revision, $edited->verification_revision, $edited->issued_on->toDateString()]);
    }

    #[Test]
    public function an_archived_credential_takes_no_decision(): void
    {
        $service = app(EmployeeQualificationService::class);
        $row = $this->qualification();
        $service->verify($this->employee, $row, $this->hr, 1);
        $service->remove($this->employee, $this->fresh($row), $this->hr, ProfileRecordArchiveReason::NO_LONGER_CURRENT);

        $this->expectException(ProfileRecordArchivedException::class);
        $service->reject($this->employee, $this->fresh($row), $this->hr, 1);
    }

    #[Test]
    public function a_status_recorded_before_version_recording_is_shown_unbound_and_never_rewritten(): void
    {
        $row = $this->qualification();
        // A legacy row as the migration left one: verified, with no revision named. Only the owner can make one,
        // with the guard off inside its own transaction; nothing at runtime can write this state.
        DB::connection('pgsql_admin')->transaction(function () use ($row): void {
            $admin = DB::connection('pgsql_admin');
            $admin->statement('ALTER TABLE employee_qualifications DISABLE TRIGGER trg_employee_qualifications_revision_guard');
            $admin->table('employee_qualifications')->where('id', $row->id)->update(['verification_status' => 'verified', 'verified_at' => '2026-10-01 09:00:00']);
            $admin->statement('ALTER TABLE employee_qualifications ENABLE TRIGGER trg_employee_qualifications_revision_guard');
        });

        $entry = app(EmployeeProfileWorkspaceService::class)->build($this->school, $this->employee->id, $this->hr)->toArray()['qualifications'][0];
        $this->assertSame(['verified', 1, null, []], [$entry['verification_status'], $entry['revision'], $entry['verification_revision'], $entry['verification_history']], 'verified, but naming no revision and no recorded decision');
        $this->api()->getJson("/api/v1/schools/{$this->school->id}/employees/{$this->employee->id}")->assertOk();

        // A new verification records the first version-bound decision; nothing is invented for the old one.
        app(EmployeeQualificationService::class)->verify($this->employee, $this->fresh($row), $this->hr, 1);
        $this->assertSame([[1, 'verified', $this->hr->id]], $this->decisions('qualification_id', $row->id));
        $this->assertSame(1, $this->state('employee_qualifications', $row->id)['bound']);
    }

    #[Test]
    public function an_edit_of_a_legacy_status_resets_it_like_any_other(): void
    {
        $row = $this->certification();
        DB::connection('pgsql_admin')->transaction(function () use ($row): void {
            $admin = DB::connection('pgsql_admin');
            $admin->statement('ALTER TABLE employee_certifications DISABLE TRIGGER trg_employee_certifications_revision_guard');
            $admin->table('employee_certifications')->where('id', $row->id)->update(['verification_status' => 'rejected']);
            $admin->statement('ALTER TABLE employee_certifications ENABLE TRIGGER trg_employee_certifications_revision_guard');
        });

        $edited = app(EmployeeCertificationService::class)->update($this->employee, $this->fresh($row), ['name' => 'CPR'], $this->hr);

        $this->assertSame(['unverified', 2, null], [$edited->verification_status, $edited->revision, $edited->verification_revision]);
        $this->assertSame([], $this->decisions('certification_id', $row->id), 'no decision is fabricated for the legacy status');
    }

    #[Test]
    public function the_api_requires_the_revision_and_answers_a_stale_one_with_409(): void
    {
        $row = $this->qualification();
        $base = "/api/v1/schools/{$this->school->id}/employees/{$this->employee->id}/qualifications/{$row->id}";

        $this->api()->postJson("{$base}/verify", [])->assertStatus(422)->assertSee('revision');
        $this->api()->postJson("{$base}/verify", ['revision' => 'one'])->assertStatus(422);
        $this->api()->postJson("{$base}/verify", ['revision' => 7])->assertStatus(409)->assertJsonPath('error.code', 'HR_CREDENTIAL_CHANGED')
            ->assertJsonMissingPath('error.exception');
        $this->api()->postJson("{$base}/verify", ['revision' => 1])->assertOk()
            ->assertJsonPath('data.verificationStatus', 'verified')->assertJsonPath('data.revision', 1)->assertJsonPath('data.verificationRevision', 1);
        $this->api()->postJson("{$base}/reject", ['revision' => 1])->assertOk()->assertJsonPath('data.verificationStatus', 'rejected');
        $this->assertSame([[1, 'verified', $this->hr->id], [1, 'rejected', $this->hr->id]], $this->decisions('qualification_id', $row->id));
    }

    #[Test]
    public function the_api_refuses_another_school_and_a_viewer_before_the_revision(): void
    {
        $other = $this->createSchool();
        $this->schools[] = $other->id;
        $otherEmployee = $this->createEmployee($other);
        $foreign = app(EmployeeCertificationService::class)->add($otherEmployee, ['name' => 'Foreign', 'issuer' => 'B Board'], $this->fullHrActor($other));

        // Another School's credential under this School's route: the private 404, never a decision.
        $this->api()->postJson("/api/v1/schools/{$this->school->id}/employees/{$otherEmployee->id}/certifications/{$foreign->id}/verify", ['revision' => 1])->assertNotFound();
        $this->assertSame([], $this->decisions('certification_id', $foreign->id));

        $viewer = $this->createUserWithCapabilities($this->school, ['hr.employees.view', 'hr.employees.qualifications.view']);
        $row = $this->certification();
        app('auth')->forgetGuards();
        $this->flushHeaders();
        $this->withHeaders(['Authorization' => 'Bearer '.$viewer->createToken('v')->plainTextToken, 'Idempotency-Key' => (string) Str::uuid7()])
            ->postJson("/api/v1/schools/{$this->school->id}/employees/{$this->employee->id}/certifications/{$row->id}/verify", [])->assertForbidden();
        $this->assertSame([], $this->decisions('certification_id', $row->id));
    }

    #[Test]
    public function the_web_page_sends_the_revision_and_shows_a_changed_record_as_a_refusal(): void
    {
        $row = $this->qualification();
        $this->actingAs($this->hr);
        $this->withSession(['active_school_id' => $this->school->id]);
        $url = "/app/hr/employees/{$this->employee->id}/qualifications/{$row->id}/verify";

        $this->post($url, [])->assertRedirect()->assertSessionHasErrors(['qualification' => 'This page is out of date. Reload it and try again.']);
        app(EmployeeQualificationService::class)->update($this->employee, $row, ['institution' => 'Another College'], $this->hr);
        $this->post($url, ['revision' => 1])->assertRedirect()->assertSessionHasErrors(['qualification' => 'This record was changed after you opened it. Reload the page and check it again before you decide.']);
        $this->assertSame([], $this->decisions('qualification_id', $row->id));

        $this->post($url, ['revision' => 2])->assertRedirect("/app/hr/employees/{$this->employee->id}")->assertSessionHasNoErrors();
        $this->assertSame([[2, 'verified', $this->hr->id]], $this->decisions('qualification_id', $row->id));
        $this->get("/app/hr/employees/{$this->employee->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('workspace.qualifications.0.revision', 2)->where('workspace.qualifications.0.verification_revision', 2)
                ->where('workspace.qualifications.0.verification_history.0.decision', 'verified'));
    }
}
