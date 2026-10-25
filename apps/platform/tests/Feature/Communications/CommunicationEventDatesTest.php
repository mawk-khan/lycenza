<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\Events\CommunicationEventCalendar;
use App\Domain\Communications\Application\Exceptions\InvalidCommunicationEventException;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Communications\Concerns\CreatesCommunicationEventFixtures;
use Tests\TestCase;

/**
 * ORG.9D-3 (ADR 0076 §75): Event dates in the SCHOOL's timezone -- a School
 * in America/New_York so daylight saving is exercised. All-day Events are
 * School-local dates (never shifted through UTC); timed Events are instants
 * entered as School-local wall-clock times; a nonexistent local time is
 * refused, an ambiguous one is its first occurrence; month and year
 * boundaries; malformed and extreme input is a clean refusal.
 */
class CommunicationEventDatesTest extends TestCase
{
    use CreatesCommunicationEventFixtures, CreatesTenancyFixtures;

    private School $school;

    private User $preparer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-01T12:00:00Z'));
        $this->school = $this->createSchool(['timezone' => 'America/New_York']);
        $this->preparer = $this->createUserWithCapabilities($this->school, ['communications.view', 'communications.announce']);
    }

    private function raw(string $id): object
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => DB::table('communication_events')->where('id', $id)->first());
    }

    private function timed(string $start, string $end): object
    {
        return $this->raw($this->draftEvent($this->school, $this->preparer, ['date_mode' => 'timed', 'starts_at' => $start, 'ends_at' => $end])->id);
    }

    /** @return list<string> titles of the School's Events overlapping a month */
    private function inMonth(string $month): array
    {
        return array_column(app(CommunicationEventCalendar::class)->manage($this->school, ['month' => $month])->items(), 'title');
    }

    #[Test]
    public function all_day_events_are_school_local_dates_single_or_multi_day(): void
    {
        $single = $this->raw($this->draftEvent($this->school, $this->preparer, ['starts_on' => '2026-11-20', 'ends_on' => ''])->id);
        $this->assertSame(['2026-11-20', '2026-11-20'], [$single->starts_on, $single->ends_on], 'No last day = a single day.');
        $this->assertNull($single->starts_at);

        $multi = $this->raw($this->draftEvent($this->school, $this->preparer, ['starts_on' => '2026-12-30', 'ends_on' => '2027-01-02'])->id);
        $this->assertSame(['2026-12-30', '2027-01-02'], [$multi->starts_on, $multi->ends_on]);

        $this->assertThrows(fn () => $this->draftEvent($this->school, $this->preparer, ['starts_on' => '2026-11-20', 'ends_on' => '2026-11-19']), InvalidCommunicationEventException::class);
    }

    #[Test]
    public function timed_events_are_school_local_wall_clock_times_stored_as_instants(): void
    {
        $event = $this->timed('2026-11-20T09:00', '2026-11-20T11:30');
        $this->assertSame('2026-11-20 14:00:00+00', $event->starts_at, 'EST is UTC-5.');
        $this->assertSame('2026-11-20 16:30:00+00', $event->ends_at);

        $summer = $this->timed('2026-10-20T09:00', '2026-10-20T10:00');
        $this->assertSame('2026-10-20 13:00:00+00', $summer->starts_at, 'EDT is UTC-4.');

        $this->assertThrows(fn () => $this->timed('2026-11-20T10:00', '2026-11-20T10:00'), InvalidCommunicationEventException::class);
        $this->assertThrows(fn () => $this->timed('2026-11-20T10:00', '2026-11-20T09:59'), InvalidCommunicationEventException::class);

        $presented = app(CommunicationEventCalendar::class)->manage($this->school, [])->items();
        $this->assertContains('2026-11-20T09:00', array_column($presented, 'startsAt'), 'Shown back in School-local time.');
    }

    #[Test]
    public function daylight_saving_gap_is_refused_and_the_repeated_hour_is_its_first_occurrence(): void
    {
        // Spring forward: 2027-03-14 02:00 -> 03:00 in New York; 02:30 never happens.
        $this->assertThrows(fn () => $this->timed('2027-03-14T02:30', '2027-03-14T04:00'), InvalidCommunicationEventException::class);
        $around = $this->timed('2027-03-14T01:30', '2027-03-14T03:30');
        $this->assertSame(['2027-03-14 06:30:00+00', '2027-03-14 07:30:00+00'], [$around->starts_at, $around->ends_at], 'One real hour.');

        // Fall back: 2026-11-01 01:00-02:00 happens twice; 01:30 is the first (EDT).
        $fallBack = $this->timed('2026-11-01T01:30', '2026-11-01T02:30');
        $this->assertSame(['2026-11-01 05:30:00+00', '2026-11-01 07:30:00+00'], [$fallBack->starts_at, $fallBack->ends_at], 'Two real hours.');
    }

    #[Test]
    public function upcoming_ends_at_the_school_local_end_of_the_last_day_or_the_timed_end(): void
    {
        $approver = $this->createUserWithCapabilities($this->school, ['communications.approve']);
        $allDay = $this->publishedEvent($this->school, $this->preparer, $approver, ['title' => 'All day', 'starts_on' => '2026-11-20', 'ends_on' => '2026-11-20']);
        $timed = $this->publishedEvent($this->school, $this->preparer, $approver, ['title' => 'Timed', 'date_mode' => 'timed', 'starts_at' => '2026-11-20T22:00', 'ends_at' => '2026-11-20T23:00']);
        $calendar = app(CommunicationEventCalendar::class);
        $when = fn (string $w) => array_column($calendar->manage($this->school, ['when' => $w])->items(), 'title');

        $this->travelTo(CarbonImmutable::parse('2026-11-21T03:59:00Z')); // 22:59 Nov 20 in New York
        $this->assertEqualsCanonicalizing(['All day', 'Timed'], $when('upcoming'));
        $this->travelTo(CarbonImmutable::parse('2026-11-21T04:00:01Z')); // just after 23:00: the timed Event has ended
        $this->assertSame(['All day'], $when('upcoming'));
        $this->assertSame(['Timed'], $when('past'));
        $this->travelTo(CarbonImmutable::parse('2026-11-21T04:59:59Z')); // 23:59:59, still Nov 20 locally
        $this->assertSame(['All day'], $when('upcoming'));
        $this->travelTo(CarbonImmutable::parse('2026-11-21T05:00:00Z')); // School-local midnight (UTC is already Nov 21 since 00:00Z)
        $this->assertSame([], $when('upcoming'));
        $this->assertEqualsCanonicalizing(['All day', 'Timed'], $when('past'));
        $this->assertNotNull($allDay->published_at);
        $this->assertNotNull($timed->published_at);
    }

    #[Test]
    public function month_and_year_boundaries_place_an_event_in_every_month_it_touches(): void
    {
        $this->draftEvent($this->school, $this->preparer, ['title' => 'New year', 'starts_on' => '2026-12-31', 'ends_on' => '2027-01-01']);
        $this->draftEvent($this->school, $this->preparer, ['title' => 'Overnight', 'date_mode' => 'timed', 'starts_at' => '2027-01-31T22:00', 'ends_at' => '2027-02-01T02:00']);
        $this->draftEvent($this->school, $this->preparer, ['title' => 'Late Jan', 'date_mode' => 'timed', 'starts_at' => '2027-01-31T20:00', 'ends_at' => '2027-01-31T21:00']);

        $this->assertSame(['New year'], $this->inMonth('2026-12'));
        $this->assertSame(['New year', 'Late Jan', 'Overnight'], $this->inMonth('2027-01'));
        $this->assertSame(['Overnight'], $this->inMonth('2027-02'), '20:00-21:00 New York on Jan 31 is Feb 1 in UTC, but it belongs to January.');
    }

    #[Test]
    public function malformed_and_extreme_dates_are_clean_refusals_never_a_server_error(): void
    {
        foreach ([
            ['starts_on' => '1999-12-31', 'ends_on' => '2000-01-01'],
            ['starts_on' => '2100-12-31', 'ends_on' => '2101-01-01'],
            ['starts_on' => '2026-02-30', 'ends_on' => ''],
            ['starts_on' => 'not-a-date', 'ends_on' => ''],
            ['date_mode' => 'timed', 'starts_at' => '2026-13-01T10:00', 'ends_at' => '2026-13-01T11:00'],
            ['date_mode' => 'timed', 'starts_at' => '2026-11-20T25:00', 'ends_at' => '2026-11-20T26:00'],
            ['date_mode' => 'weekly'],
        ] as $input) {
            $this->assertThrows(fn () => $this->draftEvent($this->school, $this->preparer, $input), InvalidCommunicationEventException::class);
        }

        $this->actingAs($this->preparer)->post("/app/schools/{$this->school->id}/activate");
        $this->post('/app/communications/events', ['title' => 'X', 'body' => 'Y', 'date_mode' => 'all_day', 'starts_on' => '9999-99-99', 'audience_type' => 'school_wide'])
            ->assertSessionHasErrors('starts_on');
    }
}
