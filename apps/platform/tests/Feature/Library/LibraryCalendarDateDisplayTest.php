<?php

namespace Tests\Feature\Library;

use App\Domain\Library\Infrastructure\LibraryLoan;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * UAT-C6 (RA-LIB-DATES, ADR 0075 section 19): a Library loan's checked-out,
 * due and returned dates are School-local CALENDAR dates (UAT-C1). UAT-C4
 * passed them through the instant formatter, which read "2026-10-16" as
 * midnight UTC: "16 Oct 2026, 5:30 am" in Asia/Kolkata and "15 Oct 2026"
 * west of UTC. A calendar date is the same day in every timezone:
 *
 * - the server sends YYYY-MM-DD in the School's calendar (both timezones);
 * - the pages show it through `formatCalendarDate`, which never builds a
 *   Date or applies a timezone;
 * - instants keep their School-time conversion (`formatDateTime`).
 */
class LibraryCalendarDateDisplayTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @return array<string, array{string, string, string}> timezone, "now" (UTC), the School's today */
    public static function timezones(): array
    {
        return [
            'east of UTC (Asia/Kolkata)' => ['Asia/Kolkata', '2026-10-16T13:00:00Z', '2026-10-16'],
            'west of UTC (America/New_York)' => ['America/New_York', '2026-10-16T13:00:00Z', '2026-10-16'],
        ];
    }

    #[Test]
    #[DataProvider('timezones')]
    public function every_library_page_sends_the_loan_dates_as_the_schools_calendar_dates(string $timezone, string $now, string $today): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $school->forceFill(['timezone' => $timezone])->save();
        $title = $this->createLibraryTitle($school);
        $copy = $this->createLibraryCopy($title);
        $returnedCopy = $this->createLibraryCopy($title);
        $student = $this->createStudent($school, ['status' => 'active']);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");
        $this->travelTo(CarbonImmutable::parse($now));

        $this->post('/app/library/circulation', ['library_copy_id' => $copy->id, 'student_id' => $student->id, 'due_at' => '2026-10-20'])->assertSessionHasNoErrors();
        $this->post('/app/library/circulation', ['library_copy_id' => $returnedCopy->id, 'student_id' => $student->id, 'due_at' => $today])->assertSessionHasNoErrors();
        $returned = $this->loan($school, $returnedCopy->id);
        $this->post("/app/library/circulation/{$returned->id}/check-in")->assertSessionHasNoErrors();
        $loan = $this->loan($school, $copy->id);

        $active = $this->get('/app/library/circulation')->viewData('page')['props']['loans']['data'][0];
        $this->assertSame('2026-10-20', $active['dueAt']);
        $this->assertSame($today, $active['checkedOutAt']);

        $back = $this->get('/app/library/circulation?status=returned')->viewData('page')['props']['loans']['data'][0];
        $this->assertSame($today, $back['dueAt']);
        $this->assertSame($today, $back['returnedAt']);

        $show = $this->get("/app/library/circulation/{$loan->id}")->viewData('page')['props']['loan'];
        $this->assertSame(['2026-10-20', $today], [$show['dueAt'], $show['checkedOutAt']]);

        $copies = $this->get("/app/library/titles/{$title->id}")->viewData('page')['props']['copies'];
        $this->assertSame('2026-10-20', collect($copies)->firstWhere('id', $copy->id)['activeLoan']['dueAt']);

        // Unchanged semantics: due at the end of the School's day, not overdue.
        $this->assertFalse($loan->isOverdue());
    }

    #[Test]
    public function the_library_pages_show_loan_dates_only_through_the_calendar_formatter(): void
    {
        $pages = [
            'Pages/App/Library/Circulation/Index.vue' => ['loan.dueAt', 'loan.returnedAt'],
            'Pages/App/Library/Circulation/Show.vue' => ['props.loan.checkedOutAt', 'props.loan.dueAt', 'props.loan.returnedAt'],
            'Pages/App/Library/Catalogue/Show.vue' => ['copy.activeLoan.dueAt'],
        ];

        foreach ($pages as $page => $fields) {
            $source = (string) file_get_contents(resource_path('js/'.$page));
            foreach ($fields as $field) {
                $this->assertStringContainsString("formatCalendarDate({$field})", $source, "{$page}: {$field} is a calendar date");
                $this->assertDoesNotMatchRegularExpression('/format(DateTime|Date)\(\s*'.preg_quote($field, '/').'\s*[,)]/', $source, "{$page}: {$field} must never go through an instant formatter");
            }
        }
    }

    #[Test]
    public function the_calendar_formatter_never_shifts_a_date_and_instants_still_convert_to_school_time(): void
    {
        $source = (string) file_get_contents(resource_path('js/datetime.ts'));

        $this->assertSame(1, preg_match('/export function formatCalendarDate\(.*?\n}\n/s', $source, $calendar));
        foreach (['new Date', 'Date.parse', 'Intl.', 'timeZone', 'schoolTimezone'] as $shift) {
            $this->assertStringNotContainsString($shift, $calendar[0], "formatCalendarDate must not use {$shift}");
        }

        $this->assertSame(1, preg_match('/function format\(.*?\n}\n/s', $source, $instant));
        $this->assertStringContainsString('timeZone: timeZone ?? schoolTimezone()', $instant[0], 'instants keep their School-time conversion');
    }

    private function loan(School $school, string $copyId): LibraryLoan
    {
        return app(TenantContext::class)->withSchool($school, fn () => LibraryLoan::query()->where('library_copy_id', $copyId)->firstOrFail());
    }
}
