<?php

namespace Tests\Feature\Retention;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\Admissions\Application\Retention\TerminalApplicationRetentionService;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Domain\Admissions\Infrastructure\ApplicantDocument;
use App\Domain\Documents\Infrastructure\Document;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Retention\RetentionPeriod;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ProvidesSensitiveActionMfa;
use Tests\TestCase;

/**
 * SIS 11.5 (owner decision 2026-10-31): pre-admission documents follow the
 * existing Admissions retention -- a rejected or withdrawn application's
 * documents go one year after its terminal_at, with the application, even
 * while the Applicant stays for another live application; bytes after commit.
 * Committed fixtures: the retention identity runs on its own session.
 */
class ApplicantDocumentRetentionTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures, ProvidesSensitiveActionMfa;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private School $school;

    private User $admin;

    private Campus $campus;

    private Campus $otherCampus;

    private AcademicYear $year;

    private GradeLevel $grade;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('documents.disk'));
        config(['retention.admissions_terminal_years' => 1, 'retention.hold_school_ids' => []]);
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $this->campus = $this->createCampus($this->school, ['code' => 'MN']);
        $this->otherCampus = $this->createCampus($this->school, ['code' => 'NO']);
        $this->year = $this->createAcademicYear($this->school);
        $this->grade = $this->createGradeLevel($this->school);
    }

    private function in(callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $callback);
    }

    private function application(Campus $campus, ?Applicant $applicant = null): AdmissionApplication
    {
        $applicant ??= $this->createApplicant($this->school);

        return $this->createAdmissionApplication($applicant, $this->year, $campus, $this->grade, ['status' => 'submitted']);
    }

    private function upload(AdmissionApplication $application, string $title): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sis115r');
        file_put_contents($path, self::PDF);
        $this->actingAs($this->admin)->post("/app/schools/{$this->school->id}/activate");
        $this->post("/app/admissions/{$application->id}/documents", [
            'file' => new UploadedFile($path, 'record.pdf', null, null, true), 'category' => 'birth_certificate', 'title' => $title,
            'form_key' => (string) new UuidV7, 'mfa_code' => $this->freshMfaCode($this->admin),
        ])->assertSessionHasNoErrors();
    }

    /** @return array<string, int> this School's unit only (the command walks every School) */
    private function prune(): array
    {
        $result = app(TerminalApplicationRetentionService::class)->prune($this->school, RetentionPeriod::yearsBeforeNow(1), 100, false, false);
        ksort($result);
        $expected = ['eligible' => 0, 'deleted' => 0, 'held' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0];

        return array_merge($expected, $result);
    }

    #[Test]
    public function a_rejected_applications_documents_expire_with_it_and_its_applicant(): void
    {
        $application = $this->application($this->campus);
        $this->upload($application, 'Birth certificate');
        $record = $this->in(fn () => ApplicantDocument::query()->sole());
        $path = $this->in(fn () => Document::query()->findOrFail($record->document_id))->storage_path;
        Storage::disk((string) config('documents.disk'))->assertExists($path);
        $this->post("/app/admissions/{$application->id}/reject")->assertSessionHasNoErrors();

        $this->travelTo(Carbon::now()->addYear()->addMinute());
        $this->assertSame(['eligible' => 1, 'deleted' => 1, 'held' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0], $this->prune());

        $this->assertSame(0, $this->in(fn () => ApplicantDocument::query()->count()));
        $this->assertSame(0, $this->in(fn () => Document::query()->whereKey($record->document_id)->count()));
        Storage::disk((string) config('documents.disk'))->assertMissing($path);
    }

    #[Test]
    public function an_expired_applications_documents_go_with_it_while_its_applicant_stays(): void
    {
        $rejected = $this->application($this->campus);
        $applicant = $this->in(fn () => Applicant::query()->findOrFail($rejected->applicant_id));
        $live = $this->application($this->otherCampus, $applicant);
        $this->upload($rejected, 'Old');
        $this->upload($live, 'Current');
        $old = $this->in(fn () => ApplicantDocument::query()->where('admission_application_id', $rejected->id)->sole());
        $this->post("/app/admissions/{$rejected->id}/reject")->assertSessionHasNoErrors();

        $this->travelTo(Carbon::now()->addYear()->addMinute());
        $this->assertSame(['eligible' => 1, 'deleted' => 1, 'held' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0], $this->prune());

        $this->assertSame(1, $this->in(fn () => Applicant::query()->whereKey($applicant->id)->count()), 'the applicant has a live application');
        $this->assertSame(['Current'], $this->in(fn () => ApplicantDocument::query()->pluck('title')->all()));
        $this->assertSame(0, $this->in(fn () => Document::query()->whereKey($old->document_id)->count()), 'the expired application\'s Document went with it');
        $this->assertSame(1, $this->in(fn () => Document::query()->where('applicant_id', $applicant->id)->count()));
    }
}
