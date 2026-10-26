<?php

namespace Tests\Feature\AcademicStructure;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 5.1 (MC-3, ADR 0077): the database's own calendar guard, through the
 * RUNTIME role with raw SQL -- no application rule involved. A draft year's
 * dates may change while it stays draft; an active or closed year's never;
 * and a year never returns to draft, so "mark it draft, then re-date it"
 * cannot bypass the freeze in one statement or two.
 */
class DraftCalendarSchemaTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
    }

    private function year(string $status, string $code): AcademicYear
    {
        $start = ['draft' => '2027-04-01', 'active' => '2026-04-01', 'closed' => '2025-04-01'][$status];

        return $this->createAcademicYear($this->school, ['code' => $code, 'status' => $status, 'starts_on' => $start, 'ends_on' => date('Y-m-d', strtotime("{$start} +1 year -1 day"))]);
    }

    private function sql(string $statement, array $bindings): int
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => DB::connection('pgsql')->update($statement, $bindings));
    }

    private function refused(string $statement, array $bindings, string $guard): void
    {
        try {
            app(TenantContext::class)->withSchool($this->school, fn () => DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->update($statement, $bindings)));
            $this->fail("expected {$guard} to refuse: {$statement}");
        } catch (QueryException $e) {
            $this->assertStringContainsString('academic_years', $e->getMessage());
            $this->assertStringContainsString($guard, $e->getMessage());
        }
    }

    #[Test]
    public function a_draft_years_dates_may_change_while_it_stays_draft(): void
    {
        $draft = $this->year('draft', 'D');

        $this->assertSame(1, $this->sql('update academic_years set starts_on = ?, ends_on = ? where id = ?', ['2027-05-01', '2028-04-30', $draft->id]));
        $this->assertSame(['2027-05-01', '2028-04-30', 'draft'], app(TenantContext::class)->withSchool($this->school, fn () => [
            AcademicYear::query()->findOrFail($draft->id)->starts_on->toDateString(), AcademicYear::query()->findOrFail($draft->id)->ends_on->toDateString(), AcademicYear::query()->findOrFail($draft->id)->status,
        ]));
    }

    #[Test]
    public function an_active_or_closed_years_dates_never_change(): void
    {
        foreach (['active' => 'A', 'closed' => 'C'] as $status => $code) {
            $year = $this->year($status, $code);
            $this->refused('update academic_years set ends_on = ends_on - 1 where id = ?', [$year->id], 'fixed once the year leaves draft');
            $this->refused('update academic_years set starts_on = starts_on + 1 where id = ?', [$year->id], 'fixed once the year leaves draft');
        }
    }

    #[Test]
    public function activating_and_re_dating_in_one_statement_is_refused(): void
    {
        $draft = $this->year('draft', 'D');

        $this->refused("update academic_years set status = 'active', ends_on = ends_on - 1 where id = ?", [$draft->id], 'fixed once the year leaves draft');
        $this->assertSame(1, $this->sql("update academic_years set status = 'active' where id = ?", [$draft->id]), 'plain activation is unaffected');
    }

    #[Test]
    public function a_year_never_returns_to_draft_in_one_statement_or_two(): void
    {
        foreach (['active' => 'A', 'closed' => 'C'] as $status => $code) {
            $year = $this->year($status, $code);
            $this->refused("update academic_years set status = 'draft' where id = ?", [$year->id], 'academic_years_no_return_to_draft');
            // Both guards refuse this one; the date freeze fires first (trigger order).
            $this->refused("update academic_years set status = 'draft', ends_on = ends_on - 1 where id = ?", [$year->id], 'fixed once the year leaves draft');
        }
    }

    #[Test]
    public function the_ordinary_lifecycle_is_unchanged(): void
    {
        $draft = $this->year('draft', 'D');
        $this->assertSame(1, $this->sql("update academic_years set status = 'active' where id = ?", [$draft->id]));
        $this->assertSame(1, $this->sql("update academic_years set status = 'closed' where id = ?", [$draft->id]));
        $this->assertSame(1, $this->sql("update academic_years set name = 'Renamed' where id = ?", [$draft->id]), 'a rename of a closed year still works');
    }
}
