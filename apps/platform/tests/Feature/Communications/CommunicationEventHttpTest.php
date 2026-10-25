<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Infrastructure\CommunicationApprovalRequest;
use App\Domain\Communications\Infrastructure\CommunicationEvent;
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
 * ORG.9D-3 (ADR 0076 §75): the Events HTTP surface. Management needs
 * communications.announce (changes: creator or communications.manage); the
 * staff calendar communications.view; decisions communications.approve in
 * the existing approvals queue. Another School's Event is the unknown 404.
 * Every value reaches the page as escaped text.
 */
class CommunicationEventHttpTest extends TestCase
{
    use CreatesCommunicationEventFixtures, CreatesTenancyFixtures;

    private School $school;

    private User $preparer;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-01T06:00:00Z'));
        $this->school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->preparer = $this->createUserWithCapabilities($this->school, ['communications.view', 'communications.announce']);
        $this->approver = $this->createUserWithCapabilities($this->school, ['communications.view', 'communications.approve']);
    }

    private function as(User $user): self
    {
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");

        return $this;
    }

    /** @return array<string, string> */
    private function form(array $overrides = []): array
    {
        return ['title' => 'Science fair', 'body' => 'Projects in the hall.', 'location' => 'Hall', 'date_mode' => 'timed',
            'starts_at' => '2026-11-05T10:00', 'ends_at' => '2026-11-05T13:00', 'audience_type' => 'school_wide', ...$overrides];
    }

    #[Test]
    public function the_full_flow_over_http_with_each_authority_checked(): void
    {
        $viewer = $this->createUserWithCapabilities($this->school, ['communications.view']);
        $this->as($viewer)->get('/app/communications/events')->assertForbidden();
        $this->as($viewer)->post('/app/communications/events', $this->form())->assertForbidden();
        $this->as($this->createUserWithCapabilities($this->school, []))->get('/app/communications/calendar')->assertForbidden();

        $this->as($this->preparer)->post('/app/communications/events', $this->form())->assertRedirect();
        $event = app(TenantContext::class)->withSchool($this->school, fn () => CommunicationEvent::query()->sole());
        $this->assertSame('2026-11-05 04:30:00+00', app(TenantContext::class)->withSchool($this->school, fn () => DB::table('communication_events')->value('starts_at')), '10:00 Kolkata.');

        // Another preparer without manage cannot change it; a manager can.
        $other = $this->createUserWithCapabilities($this->school, ['communications.view', 'communications.announce']);
        $this->as($other)->post("/app/communications/events/{$event->id}/submit-approval")->assertForbidden();
        $this->as($other)->get("/app/communications/events/{$event->id}/edit")->assertForbidden();
        $this->as($this->preparer)->post("/app/communications/events/{$event->id}/publish")->assertSessionHasErrors('event');
        $this->as($this->preparer)->post("/app/communications/events/{$event->id}/submit-approval")->assertRedirect();

        $request = app(TenantContext::class)->withSchool($this->school, fn () => CommunicationApprovalRequest::query()->where('event_id', $event->id)->sole());
        $this->as($this->approver)->get('/app/communications/approvals')->assertOk()->assertInertia(fn ($page) => $page
            ->where('requests.0.kind', 'event')->where('requests.0.title', 'Science fair'));
        $this->as($this->approver)->get("/app/communications/approvals/{$request->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('request.kind', 'event')->where('request.eventTerms.startsAt', '2026-11-05 10:00')->where('request.eventTerms.location', 'Hall'));
        $this->as($this->preparer)->post("/app/communications/approvals/{$request->id}/approve")->assertForbidden();
        $this->as($this->approver)->post("/app/communications/approvals/{$request->id}/approve")->assertRedirect();

        $this->as($this->preparer)->post("/app/communications/events/{$event->id}/publish")->assertRedirect();
        $this->as($viewer)->get('/app/communications/calendar')->assertOk()->assertInertia(fn ($page) => $page
            ->has('events', 1)->where('events.0.title', 'Science fair')->where('events.0.startsAt', '2026-11-05T10:00'));

        $manager = $this->createUserWithCapabilities($this->school, ['communications.view', 'communications.announce', 'communications.manage']);
        $this->as($manager)->post("/app/communications/events/{$event->id}/cancel")->assertRedirect();
        $this->as($viewer)->get('/app/communications/calendar')->assertOk()->assertInertia(fn ($page) => $page->where('events.0.cancelled', true));
    }

    #[Test]
    public function another_schools_event_is_the_unknown_404_and_values_are_escaped_text(): void
    {
        $elsewhere = $this->createSchool();
        $foreign = $this->draftEvent($elsewhere, $this->createUserWithCapabilities($elsewhere, ['communications.announce']));
        $this->as($this->preparer)->get("/app/communications/events/{$foreign->id}")->assertNotFound();
        $this->as($this->preparer)->post("/app/communications/events/{$foreign->id}/cancel")->assertNotFound();

        $this->publishedEvent($this->school, $this->preparer, $this->approver, [
            'title' => '<script>alert(1)</script>', 'location' => '<img src=x onerror=alert(2)>', 'body' => '<b>bold</b>',
        ]);
        $html = $this->as($this->preparer)->get('/app/communications/calendar')->assertOk()->getContent();
        // Props travel as JSON data inside <script type="application/json">, which is
        // never parsed as HTML; the only breakout, a raw "</script>", is escaped.
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertSame(2, substr_count($html, '</script>'), 'Only the two script elements of the page itself close; nothing injected one.');
        $props = $this->get('/app/communications/calendar')->viewData('page')['props'];
        $this->assertSame('<script>alert(1)</script>', $props['events'][0]['title'], 'Kept as text; Vue renders it with {{ }}.');
        $this->assertArrayNotHasKey('recipients', $props['events'][0]);
        $this->assertSame(['id', 'title', 'body', 'location', 'dateMode', 'startsOn', 'endsOn', 'startsAt', 'endsAt', 'audienceType', 'gradeLevelId', 'sectionId', 'audienceLabel', 'status', 'cancelled', 'ended', 'createdByName'], array_keys($props['events'][0]));
    }
}
