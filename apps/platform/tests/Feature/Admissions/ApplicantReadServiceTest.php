<?php

namespace Tests\Feature\Admissions;

use App\Domain\Admissions\Application\ApplicantReadService;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 1D.4: ApplicantReadService -- the canonical read layer for
 * Applicant identity and reapplication history. These tests never
 * mutate state.
 */
class ApplicantReadServiceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function readService(): ApplicantReadService
    {
        return app(ApplicantReadService::class);
    }

    private function withCtx(School $school, \Closure $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    // ==================================================================
    // A. Search
    // ==================================================================

    #[Test]
    public function search_matches_first_or_last_name_case_insensitively_and_stays_school_scoped(): void
    {
        $school = $this->createSchool();
        $target = $this->createApplicant($school, ['first_name' => 'Asha', 'last_name' => 'Verma']);
        $this->createApplicant($school, ['first_name' => 'Rohit', 'last_name' => 'Sharma']);

        $otherSchool = $this->createSchool();
        $this->createApplicant($otherSchool, ['first_name' => 'Asha', 'last_name' => 'Verma']);

        $byFirst = $this->withCtx($school, fn () => $this->readService()->search('asha'));
        $byLast = $this->withCtx($school, fn () => $this->readService()->search('VERMA'));

        $this->assertSame([$target->id], collect($byFirst->items())->pluck('id')->all());
        $this->assertSame([$target->id], collect($byLast->items())->pluck('id')->all());
    }

    #[Test]
    public function search_is_a_plain_substring_match_with_no_fuzzy_or_phonetic_tolerance(): void
    {
        $school = $this->createSchool();
        $this->createApplicant($school, ['first_name' => 'Asha', 'last_name' => 'Verma']);

        // A misspelling must NOT match -- this is substring lookup, not
        // Levenshtein/phonetic identity matching.
        $misspelled = $this->withCtx($school, fn () => $this->readService()->search('Ahsa'));

        $this->assertCount(0, $misspelled->items());
    }

    #[Test]
    public function a_blank_or_omitted_search_returns_every_applicant_in_the_school(): void
    {
        $school = $this->createSchool();
        $this->createApplicant($school);
        $this->createApplicant($school);

        $noFilter = $this->withCtx($school, fn () => $this->readService()->search());
        $blank = $this->withCtx($school, fn () => $this->readService()->search(''));

        $this->assertCount(2, $noFilter->items());
        $this->assertCount(2, $blank->items());
    }

    #[Test]
    public function search_results_never_include_date_of_birth_or_unexpected_pii(): void
    {
        $school = $this->createSchool();
        $this->createApplicant($school, ['first_name' => 'Asha', 'date_of_birth' => '2018-04-12']);

        $page = $this->withCtx($school, fn () => $this->readService()->search('asha'));

        $result = $page->items()[0];
        $this->assertNull($result->date_of_birth, 'date_of_birth must not be hydrated in search results.');
        $this->assertFalse($result->relationLoaded('applications'), 'Search results must not eager-load application/Guardian-adjacent relations.');
    }

    // ==================================================================
    // B. Detail
    // ==================================================================

    #[Test]
    public function detail_includes_date_of_birth(): void
    {
        $school = $this->createSchool();
        $applicant = $this->createApplicant($school, ['date_of_birth' => '2018-04-12']);

        $found = $this->withCtx($school, fn () => $this->readService()->detail($applicant->id));

        $this->assertNotNull($found);
        $this->assertSame('2018-04-12', $found->date_of_birth->toDateString());
    }

    #[Test]
    public function detail_produces_the_same_outcome_for_a_foreign_school_applicant_and_a_random_uuid(): void
    {
        $schoolA = $this->createSchool();
        $otherSchool = $this->createSchool();
        $foreignApplicant = $this->createApplicant($otherSchool);

        $foreignResult = $this->withCtx($schoolA, fn () => $this->readService()->detail($foreignApplicant->id));
        $randomResult = $this->withCtx($schoolA, fn () => $this->readService()->detail((string) Str::uuid()));

        $this->assertNull($foreignResult);
        $this->assertNull($randomResult);
    }

    // ==================================================================
    // C. Applicant history / reapplication
    // ==================================================================

    #[Test]
    public function applications_preserves_full_reapplication_history_never_collapsed(): void
    {
        $school = $this->createSchool();
        $applicant = $this->createApplicant($school);
        $year = $this->createAcademicYear($school, ['code' => 'AY-'.Str::random(12)]);
        $campus = $this->createCampus($school);
        $gradeLevel = $this->createGradeLevel($school);

        $rejected = $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel, ['status' => 'rejected']);
        $draft = $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel, ['status' => 'draft']);

        $history = $this->withCtx($school, fn () => $this->readService()->applications($applicant));

        $this->assertCount(2, $history, 'Both the rejected and the new draft application must remain visible.');
        $ids = $history->pluck('id')->all();
        $this->assertContains($rejected->id, $ids);
        $this->assertContains($draft->id, $ids);
        // Newest first.
        $this->assertSame($draft->id, $history->first()->id);
    }

    // ==================================================================
    // D. No write side effects
    // ==================================================================

    #[Test]
    public function search_detail_and_history_never_mutate_state_or_write_audit_events(): void
    {
        $school = $this->createSchool();
        $applicant = $this->createApplicant($school, ['first_name' => 'Asha']);
        $year = $this->createAcademicYear($school, ['code' => 'AY-'.Str::random(12)]);
        $campus = $this->createCampus($school);
        $gradeLevel = $this->createGradeLevel($school);
        $this->createAdmissionApplication($applicant, $year, $campus, $gradeLevel);

        $beforeAuditCount = $this->withCtx($school, fn () => SchoolAuditEvent::query()->count());

        $this->withCtx($school, function () use ($applicant) {
            $this->readService()->search('asha');
            $this->readService()->detail($applicant->id);
            $this->readService()->applications($applicant);
        });

        $fresh = $this->withCtx($school, fn () => Applicant::query()->findOrFail($applicant->id));
        $this->assertSame('Asha', $fresh->first_name);

        $afterAuditCount = $this->withCtx($school, fn () => SchoolAuditEvent::query()->count());
        $this->assertSame($beforeAuditCount, $afterAuditCount, 'Reads must never write audit events.');
    }
}
