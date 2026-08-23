<?php

namespace Tests\Feature\Events;

use App\Models\DomainEventOutbox;
use App\Support\Settings\SchoolSettingsService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Section 7/67: transaction commit -> event exists durably; transaction
 * rollback -> event disappears too. This is the core guarantee the
 * entire outbox pattern exists to provide.
 */
class OutboxTransactionalityTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function a_committed_transaction_leaves_the_event_durably_in_the_outbox(): void
    {
        $school = $this->createSchool();

        app(SchoolSettingsService::class)->set($school, 'communications.digest_frequency', 'weekly');

        $event = DomainEventOutbox::query()->where('school_id', $school->id)->first();

        $this->assertNotNull($event);
        $this->assertSame('school.setting.changed.v1', $event->event_type);
        $this->assertSame(1, $event->event_version);
        $this->assertSame('pending', $event->status);
        $this->assertSame(['key' => 'communications.digest_frequency', 'value' => 'weekly'], $event->payload);
        $this->assertNotNull($event->correlation_id);
    }

    #[Test]
    public function a_rolled_back_transaction_leaves_no_event_behind(): void
    {
        $school = $this->createSchool();

        try {
            DB::transaction(function () use ($school): void {
                app(SchoolSettingsService::class)->set($school, 'communications.digest_frequency', 'off');
                throw new RuntimeException('Simulated failure after the state change and event dispatch.');
            });
            $this->fail('Expected the RuntimeException to propagate.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, DomainEventOutbox::query()->where('school_id', $school->id)->count());
    }

    #[Test]
    public function an_invalid_setting_value_is_rejected_before_any_state_change_or_event(): void
    {
        $school = $this->createSchool();

        try {
            app(SchoolSettingsService::class)->set($school, 'communications.digest_frequency', 'hourly');
            $this->fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, DomainEventOutbox::query()->where('school_id', $school->id)->count());
    }
}
