<?php

namespace Tests\Feature\Library;

use App\Domain\Library\Application\LibraryLoanService;
use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Domain\Students\Infrastructure\Student;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.9C-1 (ADR 0076 §69), PV1-07 read completion (LIB-031 loans issued /
 * returned in a period, LIB-035 by Campus, LIB-037 per Student): narrowing
 * filters on the circulation list, inside the reader's stored-loan-Campus
 * scope (ORG.7D-5), read-only, still never a fine list.
 */
class LibraryCirculationFiltersTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    /** @var array<string, LibraryLoan> */
    private array $loans = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-07-01T06:00:00Z'));
        $this->school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $title = $this->createLibraryTitle($this->school, ['title' => 'Atlas']);
        $copy = fn (Campus $campus, string $code) => $this->createLibraryCopy($title, ['campus_id' => $campus->id, 'code' => $code]);
        $asha = $this->createStudent($this->school, ['first_name' => 'Asha', 'last_name' => 'Reader', 'student_number' => 'LIB-001', 'status' => 'active']);
        $bilal = $this->createStudent($this->school, ['first_name' => 'Bilal', 'last_name' => 'Reader', 'student_number' => 'LIB-002', 'status' => 'active']);

        $this->loans['northReturned'] = $this->lend($copy($this->a, 'N1'), $asha, '2026-07-01T06:00:00Z');
        $this->loans['northActive'] = $this->lend($copy($this->a, 'N2'), $bilal, '2026-07-05T18:29:59Z'); // 23:59:59 IST on 07-05
        $this->loans['southActive'] = $this->lend($copy($this->b, 'S1'), $asha, '2026-07-05T18:30:00Z'); // 00:00 IST on 07-06
        $this->travelTo(CarbonImmutable::parse('2026-07-08T06:00:00Z'));
        $this->inSchool(fn () => app(LibraryLoanService::class)->checkIn(LibraryLoan::query()->findOrFail($this->loans['northReturned']->id)));
        $this->travelTo(CarbonImmutable::parse('2026-07-10T06:00:00Z'));
    }

    private function lend(LibraryCopy $copy, Student $student, string $at): LibraryLoan
    {
        $this->travelTo(CarbonImmutable::parse($at));

        return $this->inSchool(fn () => app(LibraryLoanService::class)->checkout($copy, $student, Carbon::now()->addDays(14)));
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    /** @param  list<?string>  $campusIds  null = whole School */
    private function librarian(array $campusIds): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, 'librarian', $campusId);
        }

        return $user;
    }

    /** @return list<string> loan ids, sorted */
    private function ids(User $user, string $query): array
    {
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $props = $this->get('/app/library/circulation?'.$query)->assertOk()->viewData('page')['props'];

        return collect($props['loans']['data'])->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
    }

    /** @param  list<string>  $keys */
    private function expect(array $keys): array
    {
        return collect($keys)->map(fn (string $k) => (string) $this->loans[$k]->id)->sort()->values()->all();
    }

    #[Test]
    public function issued_and_returned_ranges_are_whole_school_local_days(): void
    {
        $hr = $this->librarian([null]);

        $this->assertSame($this->expect(['northActive']), $this->ids($hr, 'status=all&from=2026-07-05&to=2026-07-05'), '23:59:59 school time is still 07-05.');
        $this->assertSame($this->expect(['southActive']), $this->ids($hr, 'status=all&from=2026-07-06&to=2026-07-06'), '00:00 school time is 07-06 though UTC is 07-05.');
        $this->assertSame($this->expect(['northReturned']), $this->ids($hr, 'status=returned&from=2026-07-08&to=2026-07-08'), 'The returned tab ranges over the return date.');
        $this->assertSame([], $this->ids($hr, 'status=returned&from=2026-07-01&to=2026-07-07'));
        $this->assertSame($this->expect(['northActive', 'northReturned', 'southActive']), $this->ids($hr, 'status=all'));
    }

    #[Test]
    public function a_student_name_or_number_narrows_to_that_students_loans(): void
    {
        $hr = $this->librarian([null]);

        $this->assertSame($this->expect(['northReturned', 'southActive']), $this->ids($hr, 'status=all&student=Asha'));
        $this->assertSame($this->expect(['northActive']), $this->ids($hr, 'status=all&student=LIB-002'));
        $this->assertSame([], $this->ids($hr, 'status=all&student=%25'), 'A wildcard is a literal character.');
    }

    #[Test]
    public function a_campus_filter_only_narrows_the_stored_loan_campus_scope(): void
    {
        $this->assertSame($this->expect(['southActive']), $this->ids($this->librarian([null]), 'status=all&campus_id='.$this->b->id));

        $north = $this->librarian([$this->a->id]);
        $this->assertSame($this->expect(['northActive', 'northReturned']), $this->ids($north, 'status=all'));
        $this->assertSame([], $this->ids($north, 'status=all&campus_id='.$this->b->id), 'Never widens to South.');
        $this->assertSame([], $this->ids($north, 'status=all&student=Asha&from=2026-07-06'), 'The South loan of Asha stays invisible from North.');
        $this->actingAs($north)->post("/app/schools/{$this->school->id}/activate");
        $this->assertSame([(string) $this->a->id], collect($this->get('/app/library/circulation')->viewData('page')['props']['campuses'])->pluck('id')->map(fn ($id) => (string) $id)->all());
    }

    #[Test]
    public function filters_are_validated_unauthorized_readers_refused_and_nothing_is_written(): void
    {
        $hr = $this->librarian([null]);
        $before = $this->inSchool(fn () => [LibraryLoan::query()->count(), LibraryLoan::query()->where('status', 'active')->count()]);
        $this->actingAs($hr)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/library/circulation?from=2026-07-09&to=2026-07-01')->assertSessionHasErrors('to');
        $this->get('/app/library/circulation?status=overdue')->assertSessionHasErrors('status');
        $this->get('/app/library/circulation?campus_id=nope')->assertSessionHasErrors('campus_id');
        $this->ids($hr, 'status=all&student=Asha');
        $this->assertSame($before, $this->inSchool(fn () => [LibraryLoan::query()->count(), LibraryLoan::query()->where('status', 'active')->count()]));

        $outsider = $this->createUserWithCapabilities($this->school, ['library.catalogue.view']);
        $this->actingAs($outsider)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/library/circulation?status=all')->assertForbidden();

        $other = $this->createSchool();
        $otherLibrarian = $this->createUser();
        $this->assignSchoolRole($this->createMembership($otherLibrarian, $other), 'librarian');
        $this->actingAs($otherLibrarian)->post("/app/schools/{$other->id}/activate");
        $this->assertSame([], $this->get('/app/library/circulation?status=all')->viewData('page')['props']['loans']['data']);
    }
}
