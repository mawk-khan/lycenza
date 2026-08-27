<?php

namespace Tests\Feature\Admissions;

use App\Domain\Admissions\Application\ApplicantService;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1D.2: the only sanctioned write path for Applicant identity.
 * See docs/admissions/PHASE-1D-2-APPLICATION-LIFECYCLE.md.
 */
class ApplicantServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function service(): ApplicantService
    {
        return app(ApplicantService::class);
    }

    #[Test]
    public function create_writes_an_applicant_scoped_to_the_given_school_with_the_correct_fields(): void
    {
        $school = $this->createSchool();

        $applicant = $this->service()->create($school, [
            'first_name' => 'Asha',
            'middle_name' => 'K',
            'last_name' => 'Verma',
            'date_of_birth' => '2018-04-12',
        ]);

        $this->assertSame($school->id, $applicant->school_id);
        $this->assertSame('Asha', $applicant->first_name);
        $this->assertSame('K', $applicant->middle_name);
        $this->assertSame('Verma', $applicant->last_name);
        $this->assertSame('2018-04-12', $applicant->date_of_birth->toDateString());
    }

    #[Test]
    public function create_defaults_middle_and_last_name_to_null_when_omitted(): void
    {
        $school = $this->createSchool();

        $applicant = $this->service()->create($school, [
            'first_name' => 'Rohit',
            'date_of_birth' => '2017-01-01',
        ]);

        $this->assertNull($applicant->middle_name);
        $this->assertNull($applicant->last_name);
    }

    #[Test]
    public function create_writes_a_success_audit_event(): void
    {
        $school = $this->createSchool();

        $applicant = $this->service()->create($school, [
            'first_name' => 'Asha',
            'last_name' => 'Verma',
            'date_of_birth' => '2018-04-12',
        ]);

        $event = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'admission_applicant.created')->firstOrFail(),
        );

        $this->assertSame($applicant->id, $event->subject_id);
        $this->assertSame($school->id, $event->school_id);
    }

    #[Test]
    public function the_success_audit_event_never_carries_applicant_name_or_date_of_birth(): void
    {
        $school = $this->createSchool();

        $this->service()->create($school, [
            'first_name' => 'Asha',
            'last_name' => 'Verma',
            'date_of_birth' => '2018-04-12',
        ]);

        $event = app(TenantContext::class)->withSchool(
            $school,
            fn () => SchoolAuditEvent::query()->where('event_type', 'admission_applicant.created')->firstOrFail(),
        );

        $metadata = $event->metadata ?? [];
        $haystack = strtolower(json_encode($metadata));

        $this->assertStringNotContainsString('asha', $haystack);
        $this->assertStringNotContainsString('verma', $haystack);
        $this->assertStringNotContainsString('2018-04-12', $haystack);
    }
}
