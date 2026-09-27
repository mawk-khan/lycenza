<?php

namespace Tests\Feature\Email;

use App\Models\EmailSuppression;
use App\Models\PlatformAuditEvent;
use App\Support\Email\EmailConfigurationException;
use App\Support\Email\EmailKind;
use App\Support\Email\Events\FakeEmailEventAdapter;
use App\Support\Email\Suppression\EmailSuppressionService;
use App\Support\Email\Suppression\SuppressionKeyRing;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesEmailFixtures;
use Tests\TestCase;

/**
 * Phase 0O.9A (ADR 0055 section 12): the global suppression list --
 * fingerprints only, scopes, operator-only release, and the bounded HMAC
 * key ring (current + previous) that keeps every suppression matchable
 * across a key rotation.
 */
class EmailSuppressionTest extends TestCase
{
    use CreatesEmailFixtures;

    private const PREVIOUS = 'rotation-test-previous-suppression-key-000001';

    private function service(): EmailSuppressionService
    {
        return app(EmailSuppressionService::class);
    }

    #[Test]
    public function only_a_keyed_fingerprint_is_stored_and_normalization_matches_sending(): void
    {
        $row = $this->service()->suppress('  Person@Example.COM ', 'all', 'hard_bounce');

        $this->assertSame('suite-1', $row->key_id);
        $this->assertSame(hash_hmac('sha256', 'person@example.com', (string) config('email.suppression.key')), $row->address_fingerprint);
        $this->assertStringNotContainsString('person', json_encode(EmailSuppression::query()->get()->toArray()));
        $this->assertNotNull($this->service()->blocking('person@example.com', EmailKind::Standard));
        $this->assertNull($this->service()->blocking('person+tag@example.com', EmailKind::Standard), 'no provider-specific aliasing');
    }

    #[Test]
    public function scopes_decide_which_mail_is_blocked(): void
    {
        $this->service()->suppress('standard@example.com', 'standard', 'complaint');
        $this->service()->suppress('all@example.com', 'all', 'hard_bounce');

        $this->assertNotNull($this->service()->blocking('standard@example.com', EmailKind::Standard));
        $this->assertNull($this->service()->blocking('standard@example.com', EmailKind::Critical));
        $this->assertNotNull($this->service()->blocking('all@example.com', EmailKind::Standard));
        $this->assertNotNull($this->service()->blocking('all@example.com', EmailKind::Critical));

        // Suppressing again is idempotent; a wider scope is added once.
        $this->service()->suppress('standard@example.com', 'standard', 'complaint');
        $this->service()->suppress('all@example.com', 'standard', 'complaint');
        $this->assertSame(2, EmailSuppression::query()->count());
        $this->service()->suppress('standard@example.com', 'all', 'hard_bounce');
        $this->assertSame(3, EmailSuppression::query()->count());
    }

    #[Test]
    public function only_the_operator_command_releases_and_it_is_audited_without_the_address(): void
    {
        $this->service()->suppress('fixed@example.com', 'all', 'hard_bounce');

        $this->artisan('platform:mail-suppression-release', ['--reason' => 'mailbox_repaired', '--force' => true])
            ->expectsQuestion('Email address to release', 'fixed@example.com')
            ->assertExitCode(0);

        $this->assertNull($this->service()->blocking('fixed@example.com', EmailKind::Critical));
        $this->assertNotNull(EmailSuppression::query()->sole()->released_at, 'history is kept');
        $this->assertDatabaseHas('platform_audit_events', ['event_type' => 'platform.email_suppression.released']);
        $this->assertStringNotContainsString('fixed@', json_encode(PlatformAuditEvent::query()->get()->toArray()));

        $this->artisan('platform:mail-suppression-release', ['--force' => true])->assertExitCode(1);

        // No School-facing route can release a suppression.
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsStringIgnoringCase('suppression', $route->uri(), 'suppression is never a School or HTTP operation');
        }
    }

    #[Test]
    public function after_a_key_rotation_old_suppressions_still_match_and_are_rehashed_on_observation(): void
    {
        // Before rotation: recorded under the key that becomes "previous".
        config(['email.suppression.key' => self::PREVIOUS, 'email.suppression.key_id' => 'old-1']);
        $this->service()->suppress('rotated@example.com', 'all', 'hard_bounce');

        // Rotate: a new current key, the old one kept as previous.
        config(['email.suppression.key' => 'rotation-test-current-suppression-key-000002', 'email.suppression.key_id' => 'new-2',
            'email.suppression.previous_key' => self::PREVIOUS, 'email.suppression.previous_key_id' => 'old-1']);
        $this->assertSame(1, $this->service()->previousKeyCount());

        $this->assertNotNull($this->service()->blocking('rotated@example.com', EmailKind::Critical), 'still matched under the previous key');
        $this->assertSame(['new-2', 'old-1'], EmailSuppression::query()->active()->orderBy('key_id')->pluck('key_id')->all(), 're-recorded under the current key');

        // Once the previous key is removed, the re-keyed row keeps protecting.
        config(['email.suppression.previous_key' => null, 'email.suppression.previous_key_id' => null]);
        $this->assertNotNull($this->service()->blocking('rotated@example.com', EmailKind::Critical));
        $this->assertSame(1, $this->service()->orphanedCount(), 'the old-key row is reported, never silently dropped');
    }

    #[Test]
    public function the_rekey_command_rekeys_what_it_can_reconstruct_and_reports_the_rest(): void
    {
        config(['email.suppression.key' => self::PREVIOUS, 'email.suppression.key_id' => 'old-1']);
        $this->fakeEmail();
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$email] = $this->queueStandardEmail($school, $admin, 'bounced@example.com');
        $providerId = $this->emailRow($school, $email->id)->provider_message_id;
        $body = (string) json_encode(['events' => [['id' => 'rk-1', 'type' => 'hard_bounce', 'message_id' => $providerId, 'occurred_at' => now()->toIso8601String()]]]);
        $this->call('POST', 'http://localhost/api/integrations/email-provider/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LYCENZA_FAKE_EMAIL_SIGNATURE' => FakeEmailEventAdapter::sign($body, (string) config('email.events.secrets')[0]),
        ], $body)->assertStatus(202);
        $this->service()->suppress('no-source@example.com', 'all', 'hard_bounce');

        config(['email.suppression.key' => 'rotation-test-current-suppression-key-000002', 'email.suppression.key_id' => 'new-2',
            'email.suppression.previous_key' => self::PREVIOUS, 'email.suppression.previous_key_id' => 'old-1']);

        $this->artisan('platform:mail-suppression-rekey')
            ->expectsOutputToContain('Re-keyed 1; cannot be re-keyed (keep the previous key until they are released or re-observed): 1.')
            ->assertExitCode(0);

        config(['email.suppression.previous_key' => null, 'email.suppression.previous_key_id' => null]);
        $this->assertNotNull($this->service()->blocking('bounced@example.com', EmailKind::Critical));
    }

    #[Test]
    public function an_invalid_key_ring_is_refused(): void
    {
        foreach ([
            ['email.suppression.key' => 'short'],
            ['email.suppression.key_id' => 'Not An Id'],
            ['email.suppression.previous_key' => (string) config('email.suppression.key'), 'email.suppression.previous_key_id' => 'other'],
            ['email.suppression.previous_key' => self::PREVIOUS, 'email.suppression.previous_key_id' => (string) config('email.suppression.key_id')],
            ['email.suppression.previous_key' => self::PREVIOUS],
        ] as $bad) {
            $original = config('email.suppression');
            config(collect($bad)->all());
            try {
                (new SuppressionKeyRing(config()))->all();
                $this->fail('refused: '.json_encode(array_keys($bad)));
            } catch (EmailConfigurationException $e) {
                $this->assertSame('mail_suppression_keys_invalid', $e->reason);
                $this->assertStringNotContainsString(self::PREVIOUS, $e->getMessage());
            } finally {
                config(['email.suppression' => $original]);
            }
        }
    }
}
