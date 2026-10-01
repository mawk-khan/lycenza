<?php

namespace Tests\Feature\Email;

use App\Models\EmailEvent;
use App\Models\EmailSuppression;
use App\Support\Email\Suppression\EmailSuppressionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * E21.2B (E21-D2): released suppressions expire one calendar year after
 * `released_at` through the narrow retention function. An active suppression
 * never expires. Fixed past clocks.
 */
class ReleasedSuppressionRetentionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['retention.released_suppression_years' => 1, 'retention.hold_platform' => false, 'retention.batch_size' => 500]);
    }

    /** A suppression for $address created at $createdAt, released at $releasedAt (null = stays active). */
    private function suppression(string $address, string $createdAt, ?string $releasedAt, ?string $eventId = null): string
    {
        $this->travelTo(Carbon::parse($createdAt, 'UTC'));
        $suppression = app(EmailSuppressionService::class)->suppress($address, 'all', 'hard_bounce', $eventId);

        if ($releasedAt !== null) {
            $this->travelTo(Carbon::parse($releasedAt, 'UTC'));
            app(EmailSuppressionService::class)->release($address, null, 'operator_confirmed');
        }

        return $suppression->id;
    }

    private function exists(string $id): bool
    {
        return DB::table('email_suppressions')->where('id', $id)->exists();
    }

    #[Test]
    public function nothing_is_deleted_while_unconfigured(): void
    {
        $id = $this->suppression('a@example.test', '2020-01-01 00:00:00', '2020-02-01 00:00:00');
        config(['retention.released_suppression_years' => null]);
        $this->travelTo(Carbon::parse('2024-06-15 12:00:00', 'UTC'));

        $this->artisan('platform:email-suppressions-prune')->expectsOutputToContain('not configured')->assertSuccessful();
        $this->assertTrue($this->exists($id));
    }

    #[Test]
    public function one_calendar_year_after_release_and_an_active_suppression_never_ages_out(): void
    {
        $active = $this->suppression('active@example.test', '2015-01-01 00:00:00', null);
        $exactly = $this->suppression('exact@example.test', '2022-12-01 00:00:00', '2023-01-10 12:00:00');
        $older = $this->suppression('older@example.test', '2022-12-01 00:00:00', '2023-01-10 11:59:59');
        $young = $this->suppression('young@example.test', '2022-12-01 00:00:00', '2023-06-01 00:00:00');

        $this->travelTo(Carbon::parse('2024-01-10 12:00:00', 'UTC'));
        $this->artisan('platform:email-suppressions-prune')->expectsOutputToContain('Deleted 1 released suppression(s)')->assertSuccessful();

        $this->assertTrue($this->exists($active), 'an active suppression is never retention-eligible');
        $this->assertTrue($this->exists($exactly));
        $this->assertFalse($this->exists($older));
        $this->assertTrue($this->exists($young));
        $this->assertTrue(EmailSuppression::query()->whereKey($active)->whereNull('released_at')->exists());
    }

    #[Test]
    public function a_leap_day_release_expires_on_the_first_of_march_never_earlier(): void
    {
        $id = $this->suppression('leap@example.test', '2024-02-01 00:00:00', '2024-02-29 12:00:00');

        // 2025-02-28 12:00 minus one year = 2024-02-28 12:00: still retained.
        $this->travelTo(Carbon::parse('2025-02-28 12:00:00', 'UTC'));
        $this->artisan('platform:email-suppressions-prune')->assertSuccessful();
        $this->assertTrue($this->exists($id));

        $this->travelTo(Carbon::parse('2025-03-01 12:00:00', 'UTC'));
        $this->artisan('platform:email-suppressions-prune')->assertSuccessful();
        $this->assertFalse($this->exists($id));
    }

    #[Test]
    public function the_provider_event_it_pinned_is_then_freed_for_the_email_prune(): void
    {
        $event = EmailEvent::query()->create([
            'provider' => 'fake', 'event_key' => Str::random(20), 'type' => 'bounce_permanent', 'bounce_class' => 'mailbox_unknown',
            'received_at' => '2022-06-01 00:00:00', 'result' => 'applied', 'processed_at' => '2022-06-01 00:00:00',
        ]);
        $suppression = $this->suppression('pinned@example.test', '2022-06-01 00:00:00', '2022-08-01 00:00:00', $event->id);
        $this->travelTo(Carbon::parse('2024-06-15 12:00:00', 'UTC'));
        config(['email.retention_days' => 180]);

        // While the released suppression is kept, the email prune keeps its event (E21.2A L3).
        $this->artisan('platform:email-prune')->assertSuccessful();
        $this->assertTrue(EmailEvent::query()->whereKey($event->id)->exists());

        $this->artisan('platform:email-suppressions-prune')->assertSuccessful();
        $this->assertFalse($this->exists($suppression));

        $this->artisan('platform:email-prune')->assertSuccessful();
        $this->assertFalse(EmailEvent::query()->whereKey($event->id)->exists());
    }

    #[Test]
    public function the_platform_hold_and_dry_run_delete_nothing(): void
    {
        $id = $this->suppression('held@example.test', '2015-01-01 00:00:00', '2015-02-01 00:00:00');
        $this->travelTo(Carbon::parse('2024-06-15 12:00:00', 'UTC'));

        $this->artisan('platform:email-suppressions-prune', ['--dry-run' => true])->expectsOutputToContain('Dry run: would delete 1')->assertSuccessful();
        config(['retention.hold_platform' => true]);
        $this->artisan('platform:email-suppressions-prune')->expectsOutputToContain('held: 1')->assertSuccessful();

        $this->assertTrue($this->exists($id));
    }
}
