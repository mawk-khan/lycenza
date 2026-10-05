<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationAttachment;
use App\Domain\Communications\Infrastructure\CommunicationMessage;
use App\Domain\Communications\Infrastructure\CommunicationThread;
use App\Models\School;
use App\Support\Retention\RetentionHolds;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * E21.2C (E21-D3): `platform:communications-prune`, on fixed clocks.
 * - Content goes 3 calendar years after the END of the Academic Year it was
 *   sent in (School-local date, Asia/Kolkata by default).
 * - Delivery telemetry goes 1 calendar year after its terminal time.
 * - Undeterminable years and never-sent units are kept.
 */
class CommunicationsRetentionPruneTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disk = (string) config('communications.attachments.disk');
        Storage::fake($this->disk);
        // Local 2026-06-15 => content cutoff 2023-06-15.
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
        config([
            'retention.communications_content_years' => 3,
            'retention.communications_delivery_years' => 1,
            'retention.hold_school_ids' => [],
            'retention.batch_size' => 500,
        ]);
    }

    /** Three consecutive years: 2021-22, 2022-23 (ends 2023-03-31), 2023-24. */
    private function schoolWithYears(): School
    {
        $school = $this->createSchool();
        foreach ([['2021-04-01', '2022-03-31'], ['2022-04-01', '2023-03-31'], ['2023-04-01', '2024-03-31']] as [$from, $to]) {
            $this->createAcademicYear($school, ['starts_on' => $from, 'ends_on' => $to, 'status' => 'closed', 'code' => 'AY'.Str::upper(Str::random(6))]);
        }

        return $school;
    }

    private function inSchool(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    /** A thread with one message per UTC timestamp given; optionally one attachment with bytes. */
    private function thread(School $school, array $sentAt, bool $attachment = false): CommunicationThread
    {
        $sender = $this->createUser();
        $thread = $this->createThread($school, $sender);
        foreach ($sentAt as $at) {
            $message = $this->createMessage($thread, $sender, ['created_at' => $at, 'updated_at' => $at]);
            $delivery = $this->createDelivery($this->createRecipient($message, $this->createUser()), ['status' => 'delivered', 'delivered_at' => $at]);
            $this->createDeliveryAttempt($delivery);
        }
        if ($attachment) {
            $this->attach($school, ['communication_thread_id' => $thread->id], "threads/{$thread->id}");
        }

        return $thread;
    }

    /** A published announcement (with its message) sent at $publishedAt (UTC). */
    private function announcement(School $school, string $publishedAt, bool $attachment = false): CommunicationAnnouncement
    {
        $creator = $this->createUser();
        $announcement = $this->createAnnouncement($school, $creator, ['status' => 'draft']);
        $this->inSchool($school, function () use ($school, $announcement, $creator, $publishedAt) {
            $message = CommunicationMessage::factory()->create([
                'school_id' => $school->id, 'thread_id' => null, 'announcement_id' => $announcement->id, 'sender_user_id' => $creator->id,
                'created_at' => $publishedAt, 'updated_at' => $publishedAt,
            ]);
            DB::table('communication_announcements')->where('id', $announcement->id)->update(['status' => 'published', 'published_at' => $publishedAt, 'message_id' => $message->id]);
            $this->createDelivery($this->createRecipient($message, $this->createUser()), ['status' => 'delivered', 'delivered_at' => $publishedAt]);
        });
        if ($attachment) {
            $this->attach($school, ['communication_announcement_id' => $announcement->id], "announcements/{$announcement->id}");
        }

        return $announcement;
    }

    private function attach(School $school, array $owner, string $folder): CommunicationAttachment
    {
        $path = "schools/{$school->id}/communications/{$folder}/".Str::uuid7().'.pdf';
        Storage::disk($this->disk)->put($path, 'bytes');

        return $this->inSchool($school, fn () => CommunicationAttachment::query()->forceCreate(array_merge([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'storage_disk' => $this->disk, 'storage_path' => $path,
            'original_filename' => 'a.pdf', 'safe_display_name' => 'a.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 5,
            'checksum_sha256' => str_repeat('a', 64), 'created_by_user_id' => $this->createUser()->id,
        ], $owner)));
    }

    private function exists(School $school, string $table, string $id): bool
    {
        return $this->inSchool($school, fn () => DB::table($table)->where('id', $id)->exists());
    }

    #[Test]
    public function nothing_is_deleted_while_unconfigured_and_invalid_values_fail(): void
    {
        $school = $this->schoolWithYears();
        $old = $this->thread($school, ['2021-06-01 06:00:00']);
        config(['retention.communications_content_years' => null, 'retention.communications_delivery_years' => null]);

        $this->artisan('platform:communications-prune')->expectsOutputToContain('not configured')->assertSuccessful();
        config(['retention.communications_content_years' => 'x']);
        $this->artisan('platform:communications-prune')->assertFailed();
        $this->artisan('platform:communications-prune', ['--only' => 'other'])->assertFailed();

        $this->assertTrue($this->exists($school, 'communication_threads', $old->id));
    }

    #[Test]
    public function content_goes_three_years_after_the_end_of_the_year_it_was_sent_in(): void
    {
        $school = $this->schoolWithYears();
        // Sent in 2022-23 (ends 2023-03-31 < cutoff 2023-06-15): eligible.
        $oldThread = $this->thread($school, ['2022-05-01 06:00:00', '2023-01-10 06:00:00'], attachment: true);
        $oldAnnouncement = $this->announcement($school, '2022-09-01 06:00:00', attachment: true);
        // A thread that continued into 2023-24: the latest year (ends 2024-03-31) keeps it.
        $continued = $this->thread($school, ['2022-05-01 06:00:00', '2023-05-01 06:00:00']);
        // Sent in 2023-24: kept.
        $young = $this->announcement($school, '2023-04-02 06:00:00');
        $paths = $this->inSchool($school, fn () => DB::table('communication_attachments')->pluck('storage_path')->all());

        $this->artisan('platform:communications-prune', ['--only' => 'content'])->expectsOutputToContain('Deleted 2 communication(s)')->assertSuccessful();

        $this->assertFalse($this->exists($school, 'communication_threads', $oldThread->id));
        $this->assertFalse($this->exists($school, 'communication_announcements', $oldAnnouncement->id));
        $this->assertSame(0, $this->inSchool($school, fn () => DB::table('communication_messages')->where('thread_id', $oldThread->id)->count()));
        $this->assertTrue($this->exists($school, 'communication_threads', $continued->id));
        $this->assertTrue($this->exists($school, 'communication_announcements', $young->id));
        // Metadata and bytes went together.
        $this->assertSame(0, $this->inSchool($school, fn () => DB::table('communication_attachments')->count()));
        foreach ($paths as $path) {
            Storage::disk($this->disk)->assertMissing($path);
        }
    }

    #[Test]
    public function the_cutoff_is_exact_and_a_leap_day_never_shortens_it(): void
    {
        $school = $this->schoolWithYears();
        $thread = $this->thread($school, ['2022-05-01 06:00:00']);

        // Year ends 2023-03-31: retained on local 2026-03-31, eligible on 2026-04-01.
        $this->travelTo(Carbon::parse('2026-03-31 12:00:00', 'UTC'));
        $this->artisan('platform:communications-prune', ['--only' => 'content'])->assertSuccessful();
        $this->assertTrue($this->exists($school, 'communication_threads', $thread->id));
        $this->travelTo(Carbon::parse('2026-04-01 12:00:00', 'UTC'));
        $this->artisan('platform:communications-prune', ['--only' => 'content'])->assertSuccessful();
        $this->assertFalse($this->exists($school, 'communication_threads', $thread->id));

        // A year ending 2024-02-29: kept through 2027-02-28, eligible on 2027-03-01.
        $leap = $this->createSchool();
        $this->createAcademicYear($leap, ['starts_on' => '2023-03-01', 'ends_on' => '2024-02-29', 'status' => 'closed', 'code' => 'LEAP1']);
        $leapThread = $this->thread($leap, ['2023-06-01 06:00:00']);
        $this->travelTo(Carbon::parse('2027-02-28 12:00:00', 'UTC'));
        $this->artisan('platform:communications-prune', ['--only' => 'content'])->assertSuccessful();
        $this->assertTrue($this->exists($leap, 'communication_threads', $leapThread->id));
        $this->travelTo(Carbon::parse('2027-03-01 12:00:00', 'UTC'));
        $this->artisan('platform:communications-prune', ['--only' => 'content'])->assertSuccessful();
        $this->assertFalse($this->exists($leap, 'communication_threads', $leapThread->id));
    }

    #[Test]
    public function an_undeterminable_year_or_a_never_sent_unit_is_kept(): void
    {
        $school = $this->schoolWithYears();
        // In no year (before 2021-04-01) and in two overlapping years.
        $gap = $this->thread($school, ['2019-01-01 06:00:00']);
        $this->createAcademicYear($school, ['starts_on' => '2020-01-01', 'ends_on' => '2021-06-30', 'status' => 'closed', 'code' => 'OVERLAP']);
        $overlap = $this->thread($school, ['2021-05-01 06:00:00']);
        // Sent across midnight UTC: 2022-03-31 20:00 UTC is local 2022-04-01 (2022-23).
        $local = $this->thread($school, ['2022-03-31 20:00:00']);
        $draft = $this->createAnnouncement($school, $this->createUser(), ['status' => 'draft', 'created_at' => '2021-09-01 06:00:00']);
        $empty = $this->createThread($school, $this->createUser(), ['created_at' => '2021-09-01 06:00:00']);

        $this->artisan('platform:communications-prune', ['--only' => 'content'])
            ->expectsOutputToContain('Deleted 1 communication(s) (unresolved year: 2, never sent: 2')->assertSuccessful();

        foreach ([[$gap, 'communication_threads'], [$overlap, 'communication_threads'], [$draft, 'communication_announcements'], [$empty, 'communication_threads']] as [$row, $table]) {
            $this->assertTrue($this->exists($school, $table, $row->id));
        }
        $this->assertFalse($this->exists($school, 'communication_threads', $local->id));
    }

    #[Test]
    public function a_held_school_keeps_everything_and_another_school_is_unaffected(): void
    {
        $a = $this->schoolWithYears();
        $held = $this->schoolWithYears();
        config(['retention.hold_school_ids' => [$held->id]]);
        // E21-RH.6: the database hold is authoritative (a configured hold must also be recorded).
        app(RetentionHolds::class)->place($held->id, 'litigation', 'TEST-HOLD');
        $oldA = $this->thread($a, ['2022-05-01 06:00:00']);
        $youngA = $this->thread($a, ['2024-01-01 06:00:00']);
        $oldHeld = $this->thread($held, ['2022-05-01 06:00:00'], attachment: true);
        $heldDelivery = $this->inSchool($held, fn () => DB::table('communication_deliveries')->value('id'));

        $this->artisan('platform:communications-prune')->expectsOutputToContain('held: 1)')->assertSuccessful();

        $this->assertFalse($this->exists($a, 'communication_threads', $oldA->id));
        $this->assertTrue($this->exists($a, 'communication_threads', $youngA->id));
        $this->assertTrue($this->exists($held, 'communication_threads', $oldHeld->id));
        $this->assertTrue($this->exists($held, 'communication_deliveries', $heldDelivery));
        Storage::disk($this->disk)->assertExists($this->inSchool($held, fn () => DB::table('communication_attachments')->value('storage_path')));
    }

    #[Test]
    public function delivery_telemetry_goes_a_year_after_its_terminal_time_and_content_survives(): void
    {
        // now 2026-06-15 12:00 UTC => telemetry cutoff 2025-06-15 12:00.
        $school = $this->schoolWithYears();
        $sender = $this->createUser();
        $thread = $this->createThread($school, $sender);
        $message = $this->createMessage($thread, $sender, ['created_at' => '2025-01-10 06:00:00']);
        $recipient = $this->createRecipient($message, $this->createUser());
        // One delivery per (recipient, channel): each case gets its own recipient.
        $make = fn (array $a) => $this->createDelivery($this->createRecipient($message, $this->createUser()), $a)->id;
        $gone = [
            $make(['status' => 'delivered', 'delivered_at' => '2025-06-15 11:59:59']),
            $make(['status' => 'read', 'delivered_at' => '2025-01-01', 'read_at' => '2025-02-01']),
            $make(['status' => 'bounced', 'failed_at' => '2024-01-01']),
        ];
        $kept = [
            $make(['status' => 'delivered', 'delivered_at' => '2025-06-15 12:00:00']),
            $make(['status' => 'read', 'delivered_at' => '2025-01-01', 'read_at' => '2025-07-01']),
            $make(['status' => 'pending', 'created_at' => '2020-01-01']),
            $make(['status' => 'sent', 'sent_at' => '2020-01-01']),
            $make(['status' => 'cancelled', 'updated_at' => '2020-01-01']),
        ];
        $oldDecision = $this->createPolicyDecision($school, $message->id, $this->createUser(), ['created_at' => '2025-01-01 00:00:00']);
        $youngDecision = $this->createPolicyDecision($school, $message->id, $this->createUser(), ['created_at' => '2025-12-01 00:00:00']);

        $this->artisan('platform:communications-prune', ['--only' => 'delivery'])
            ->expectsOutputToContain('4 delivery record(s) (no terminal time: 1')->assertSuccessful();

        foreach ($gone as $id) {
            $this->assertFalse($this->exists($school, 'communication_deliveries', $id));
        }
        foreach ($kept as $id) {
            $this->assertTrue($this->exists($school, 'communication_deliveries', $id));
        }
        $this->assertFalse($this->exists($school, 'communication_delivery_policy_decisions', $oldDecision->id));
        $this->assertTrue($this->exists($school, 'communication_delivery_policy_decisions', $youngDecision->id));
        // The retained communication itself, and who it went to, stay.
        $this->assertTrue($this->exists($school, 'communication_messages', $message->id));
        $this->assertTrue($this->exists($school, 'communication_recipients', $recipient->id));
    }

    #[Test]
    public function dry_run_and_reruns_are_safe(): void
    {
        $school = $this->schoolWithYears();
        $threads = collect(range(1, 3))->map(fn () => $this->thread($school, ['2022-05-01 06:00:00']));

        $this->artisan('platform:communications-prune', ['--dry-run' => true])->expectsOutputToContain('Dry run: would delete 3 communication(s)')->assertSuccessful();
        $threads->each(fn ($t) => $this->assertTrue($this->exists($school, 'communication_threads', $t->id)));

        config(['retention.batch_size' => 2]);
        $this->artisan('platform:communications-prune')->expectsOutputToContain('Deleted 3 communication(s)')->assertSuccessful();
        $this->artisan('platform:communications-prune')->expectsOutputToContain('Deleted 0 communication(s)')->assertSuccessful();
    }
}
