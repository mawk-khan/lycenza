<?php

namespace Tests\Feature\Compliance;

use App\Domain\Compliance\Application\AuditLogReviewService;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.9B (ADR 0076 §68), 9B-4: the School audit log narrows by event type
 * or family, subject label and a School-local date range. Filters are
 * validated against the School's own values, only narrow, never read or
 * reveal metadata, and the review stays one audited access event.
 */
class AuditLogFilterTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @return array{school: School, reviewer: User, student: mixed} */
    private function world(string $timezone = 'Asia/Kolkata'): array
    {
        $school = $this->createSchool(['timezone' => $timezone]);
        $reviewer = $this->createUser();
        $this->assignSchoolRole($this->createMembership($reviewer, $school), 'principal');

        return ['school' => $school, 'reviewer' => $reviewer, 'student' => $this->createStudent($school)];
    }

    private function inSchool(School $school, \Closure $fn): mixed
    {
        return app(TenantContext::class)->withSchool($school, $fn);
    }

    private function record(School $school, string $type, ?object $subject = null, array $metadata = []): void
    {
        app(TenantContext::class)->withSchool($school, fn () => app(AuditRecorder::class)->school($school, $type, subject: $subject, metadata: $metadata));
    }

    /** @return array{types: list<string>, log: array<string, mixed>} */
    private function review(array $w, string $query = ''): array
    {
        $this->actingAs($w['reviewer'])->post("/app/schools/{$w['school']->id}/activate");
        $props = $this->get('/app/compliance/audit-log'.($query === '' ? '' : '?'.$query))->assertOk()->viewData('page')['props'];

        // Only the seeded business events (activation and earlier reviews also write rows).
        $seeded = ['fee_concession.requested', 'fee_concession.approved', 'fee_concessions_report.viewed', 'ledger_account.list_viewed'];
        $types = array_values(array_filter(array_column($props['log']['entries'], 'eventType'), fn (string $t) => in_array($t, $seeded, true)));

        return ['types' => $types, 'log' => $props['log'], 'props' => $props];
    }

    /** @return array<string, mixed> a world with three events on three School-local days */
    private function seeded(): array
    {
        $w = $this->world();
        $this->travelTo(CarbonImmutable::parse('2026-07-01T18:29:59Z')); // 23:59:59 IST 07-01
        $this->record($w['school'], 'fee_concession.requested', $w['student'], ['secret' => 'LEAK-CANARY']);
        $this->travelTo(CarbonImmutable::parse('2026-07-01T18:30:00Z')); // 00:00:00 IST 07-02
        $this->record($w['school'], 'fee_concession.approved', $w['student']);
        $this->travelTo(CarbonImmutable::parse('2026-07-03T06:00:00Z'));
        $this->record($w['school'], 'fee_concessions_report.viewed'); // shares a prefix, not the family
        $this->record($w['school'], 'ledger_account.list_viewed');
        $this->travelTo(CarbonImmutable::parse('2026-07-05T06:00:00Z'));

        return $w;
    }

    #[Test]
    public function an_exact_event_type_or_a_family_narrows_the_log(): void
    {
        $w = $this->seeded();

        $this->assertSame(['fee_concession.approved'], $this->review($w, 'event=fee_concession.approved')['types']);
        $this->assertSame(['fee_concession.approved', 'fee_concession.requested'], $this->review($w, 'event=fee_concession')['types'], 'The family is "fee_concession." -- never fee_concessions_report.viewed.');
        $this->assertSame(['ledger_account.list_viewed'], $this->review($w, 'event=ledger_account')['types']);

        $options = $this->review($w)['log']['filterOptions'];
        $this->assertContains('fee_concession.requested', $options['events']);
        $this->assertContains('fee_concession', $options['eventFamilies']);
        $this->assertContains('fee_concessions_report', $options['eventFamilies']);
    }

    #[Test]
    public function a_subject_label_maps_to_its_stored_class_without_revealing_it(): void
    {
        $w = $this->seeded();
        $result = $this->review($w, 'subject=Student');

        $this->assertSame(['fee_concession.approved', 'fee_concession.requested'], $result['types']);
        $this->assertSame(['Student'], array_values(array_unique(array_column($result['log']['entries'], 'subjectType'))));
        $this->assertContains('Student', $result['log']['filterOptions']['subjects']);
        $this->assertStringNotContainsString('App\\\\Models', json_encode($result['props']), 'Labels only: no stored class name reaches the page.');
    }

    #[Test]
    public function the_date_range_is_whole_school_local_days(): void
    {
        $w = $this->seeded();

        $this->assertSame(['fee_concession.requested'], $this->review($w, 'from=2026-07-01&to=2026-07-01')['types'], '23:59:59 school time is still 07-01.');
        $this->assertSame(['fee_concession.approved'], $this->review($w, 'from=2026-07-02&to=2026-07-02')['types'], '00:00 school time is 07-02, though UTC is still 07-01.');
        $this->assertSame(['ledger_account.list_viewed', 'fee_concessions_report.viewed', 'fee_concession.approved'], $this->review($w, 'from=2026-07-02')['types']);
        $this->assertSame(['fee_concession.approved', 'fee_concession.requested'], $this->review($w, 'to=2026-07-02')['types']);
        $this->assertSame([], $this->review($w, 'from=2026-07-04&to=2026-07-04')['types']);
    }

    #[Test]
    public function filters_combine_and_page_with_the_cursor(): void
    {
        $w = $this->world();
        $this->travelTo(CarbonImmutable::parse('2026-07-02T06:00:00Z'));
        for ($i = 0; $i < 55; $i++) {
            $this->record($w['school'], 'fee_concession.requested', $w['student']);
            $this->record($w['school'], 'ledger_account.list_viewed');
        }
        $this->travelTo(CarbonImmutable::parse('2026-07-05T06:00:00Z'));

        $first = $this->review($w, 'event=fee_concession&subject=Student&from=2026-07-02&to=2026-07-02');
        $this->assertCount(50, $first['types']);
        $this->assertSame(['fee_concession.requested'], array_values(array_unique($first['types'])));
        $second = $this->review($w, 'event=fee_concession&subject=Student&from=2026-07-02&to=2026-07-02&cursor='.$first['log']['nextCursor']);
        $this->assertCount(5, $second['types']);
        $this->assertNull($second['log']['nextCursor']);
    }

    #[Test]
    public function malformed_or_unknown_filters_are_refused(): void
    {
        $w = $this->seeded();
        $this->actingAs($w['reviewer'])->post("/app/schools/{$w['school']->id}/activate");

        foreach ([
            'event' => ['event=fee%', 'event=FEE_CONCESSION', 'event='.str_repeat('a', 151), 'event=no_such.event'],
            'subject' => ['subject=App%5CModels%5CStudent', 'subject=NotASubject'],
            'from' => ['from=2026-07-05&to=2026-07-01', 'from=01-07-2026'],
            'to' => ['to=2026-13-01'],
        ] as $field => $queries) {
            foreach ($queries as $query) {
                $this->get('/app/compliance/audit-log?'.$query)->assertSessionHasErrors($field);
            }
        }
    }

    #[Test]
    public function metadata_and_network_details_never_reach_the_page_and_the_review_is_audited_once(): void
    {
        $w = $this->seeded();
        $before = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', AuditLogReviewService::ACCESS_EVENT)->count());
        $result = $this->review($w, 'event=fee_concession.requested');

        $json = json_encode($result['props']);
        foreach (['LEAK-CANARY', 'secret', 'metadata', 'ip_address', 'user_agent'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
        $this->assertSame(['id', 'occurredAt', 'eventType', 'actorUserId', 'subjectType', 'subjectId', 'requestId'], array_keys($result['log']['entries'][0]));

        $events = $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', AuditLogReviewService::ACCESS_EVENT)->orderByDesc('occurred_at')->orderByDesc('id')->get());
        $this->assertCount($before + 1, $events);
        $this->assertSame(['eventType'], $events->first()->metadata['filters'], 'Which filters were used -- never their values.');
    }

    #[Test]
    public function filters_never_widen_school_scope_or_authority(): void
    {
        $w = $this->seeded();
        $other = $this->world();
        $this->record($other['school'], 'fee_concession.requested', $other['student']);

        $this->assertNotContains($other['student']->id, array_column($this->review($w, 'event=fee_concession&subject=Student')['log']['entries'], 'subjectId'));

        $outsider = $this->createUserWithCapabilities($w['school'], ['finance.ledger.view']);
        $this->actingAs($outsider)->post("/app/schools/{$w['school']->id}/activate");
        $this->get('/app/compliance/audit-log?event=fee_concession')->assertForbidden();
        $this->get('/app/compliance/audit-log?event=no_such.event')->assertForbidden();
    }
}
