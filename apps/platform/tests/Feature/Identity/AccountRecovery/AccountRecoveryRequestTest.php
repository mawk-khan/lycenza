<?php

namespace Tests\Feature\Identity\AccountRecovery;

use App\Domain\Identity\Infrastructure\AccountRecoveryRequest;
use App\Http\Controllers\Auth\AccountRecoveryController;
use App\Jobs\IssueAccountRecoveryJob;
use App\Models\EmailMessage;
use App\Models\User;
use App\Support\Email\PlatformEmailScope;
use App\Support\Email\Suppression\EmailSuppressionService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesSchoolDomains;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\TestCase;

/**
 * Phase 0O.10A (ADR 0056 sections 5, 7): the request endpoint never tells
 * anyone whether an account exists or can recover -- one generic response
 * for every well-formed address -- and its limits bound abuse without
 * becoming an oracle.
 */
class AccountRecoveryRequestTest extends TestCase
{
    use CreatesSchoolDomains, CreatesTenancyFixtures, FakesEmail;

    protected function setUp(): void
    {
        parent::setUp();
        config(['account_recovery.enabled' => true]);
        $this->fakeEmail();
    }

    private function requestRecovery(string $email, string $ip = '203.0.113.10'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->post('/account-recovery', ['email' => $email]);
    }

    /** The whole observable response: status, target, flash, cookies aside. */
    private function shape(TestResponse $response): array
    {
        return [$response->getStatusCode(), $response->headers->get('Location'), session('account_recovery_status'), session('errors')?->toArray()];
    }

    #[Test]
    public function the_login_page_offers_recovery_only_when_it_is_enabled(): void
    {
        $this->get('/login')->assertInertia(fn (AssertableInertia $page) => $page->where('recoveryUrl', 'http://localhost:8000/account-recovery'));

        config(['account_recovery.enabled' => false]);
        $this->get('/login')->assertInertia(fn (AssertableInertia $page) => $page->where('recoveryUrl', null));
    }

    #[Test]
    public function every_well_formed_address_gets_the_same_generic_response(): void
    {
        $eligible = $this->createUser(['email' => 'eligible@example.test']);
        $disabled = $this->createUser(['email' => 'disabled@example.test']);
        $disabled->forceFill(['is_disabled' => true, 'disabled_at' => now()])->save();
        $root = $this->createPlatformRoot(['email' => 'root-person@example.test']);
        // Phase 0O.12B (ADR 0059 section 4): "no local credential" is NULL.
        $noPassword = $this->createUser(['email' => 'no-password@example.test', 'password' => null]);

        $shapes = [];
        foreach (['eligible@example.test', 'nobody@example.test', 'disabled@example.test', 'root-person@example.test', 'no-password@example.test'] as $i => $email) {
            $this->flushSession();
            $shapes[$email] = $this->shape($this->requestRecovery($email, "203.0.113.{$i}"));
        }

        $this->assertCount(1, array_unique(array_map('serialize', $shapes)), 'identical for every case');
        $this->assertSame([302, 'http://localhost:8000/account-recovery', AccountRecoveryController::GENERIC_STATUS, null], $shapes['nobody@example.test']);

        // Only the eligible account received anything.
        $this->assertEmailAcceptedCount(1);
        $this->assertEmailAcceptedTo('eligible@example.test');
        $this->assertSame(1, AccountRecoveryRequest::query()->count());
        $this->assertSame($eligible->id, AccountRecoveryRequest::query()->value('user_id'));
        $this->assertSame(0, AccountRecoveryRequest::query()->whereIn('user_id', [$disabled->id, $root->id, $noPassword->id])->count());
    }

    #[Test]
    public function the_address_is_matched_in_its_canonical_form(): void
    {
        $this->createUser(['email' => 'mixed.case@example.test']);

        $this->requestRecovery('  Mixed.CASE@Example.TEST ')->assertRedirect('/account-recovery');

        $this->assertEmailAcceptedTo('mixed.case@example.test');
    }

    #[Test]
    public function issuance_is_an_encrypted_job_carrying_only_the_canonical_address(): void
    {
        Queue::fake();

        $this->requestRecovery('Someone@Example.test');

        Queue::assertPushedOn('notifications', IssueAccountRecoveryJob::class, fn (IssueAccountRecoveryJob $job) => $job->email === 'someone@example.test');
        $this->assertContains(ShouldBeEncrypted::class, class_implements(IssueAccountRecoveryJob::class));
        $this->assertSame(1, (new \ReflectionClass(IssueAccountRecoveryJob::class))->newInstanceWithoutConstructor()->tries);
    }

    #[Test]
    public function a_malformed_address_is_a_validation_error_not_an_oracle(): void
    {
        $this->from('/account-recovery')->post('/account-recovery', ['email' => 'not-an-address'])->assertSessionHasErrors('email');
        $this->assertNoEmailAccepted();
    }

    #[Test]
    public function disabled_recovery_still_answers_generically_and_issues_nothing(): void
    {
        config(['account_recovery.enabled' => false]);
        $this->createUser(['email' => 'eligible@example.test']);

        $this->get('/account-recovery')->assertOk();
        $this->requestRecovery('eligible@example.test')->assertRedirect('/account-recovery');

        $this->assertSame(AccountRecoveryController::GENERIC_STATUS, session('account_recovery_status'));
        $this->assertNoEmailAccepted();
        $this->assertSame(0, AccountRecoveryRequest::query()->count());
    }

    #[Test]
    public function unavailable_critical_email_issues_nothing_but_answers_the_same(): void
    {
        config(['email.provider' => 'none']);
        $this->createUser(['email' => 'eligible@example.test']);

        $this->requestRecovery('eligible@example.test')->assertRedirect('/account-recovery');

        $this->assertSame(AccountRecoveryController::GENERIC_STATUS, session('account_recovery_status'));
        $this->assertSame(0, AccountRecoveryRequest::query()->count(), 'no credential nobody could receive');
    }

    #[Test]
    public function recovery_mail_respects_suppression_and_expires_with_its_credential(): void
    {
        $this->createUser(['email' => 'suppressed@example.test']);
        app(EmailSuppressionService::class)->suppress('suppressed@example.test', 'all', 'hard_bounce');

        $this->requestRecovery('suppressed@example.test')->assertRedirect('/account-recovery');
        $this->assertSame(AccountRecoveryController::GENERIC_STATUS, session('account_recovery_status'));
        $this->assertNoEmailAccepted();

        $request = AccountRecoveryRequest::query()->firstOrFail();
        $message = app(PlatformEmailScope::class)->run(fn () => EmailMessage::query()->findOrFail($request->email_message_id));
        $this->assertSame('suppressed', $message->status->value);
        $this->assertTrue($message->expires_at->equalTo($request->expires_at), 'the email never outlives the link');
    }

    #[Test]
    public function the_per_address_limit_is_silent_three_per_hour(): void
    {
        $this->createUser(['email' => 'eligible@example.test']);

        foreach (range(1, 5) as $i) {
            $this->flushSession();
            $this->requestRecovery('eligible@example.test', "198.51.100.{$i}")->assertRedirect('/account-recovery');
            $this->assertSame(AccountRecoveryController::GENERIC_STATUS, session('account_recovery_status'));
        }

        // Three issued (which is also the open-credential cap); the rest were
        // absorbed by the per-address limiter, answering the same success.
        $this->assertSame(3, AccountRecoveryRequest::query()->count());
        $this->assertEmailAcceptedTo('eligible@example.test', 3);

        // The daily bound: after the hour, still at most 10 per day.
        AccountRecoveryRequest::query()->update(['invalidated_at' => now(), 'invalidation_reason' => 'operator']);
        $this->travel(61)->minutes();
        foreach (range(1, 12) as $i) {
            $this->requestRecovery('eligible@example.test', "198.51.100.{$i}");
            if ($i % 3 === 0) {
                AccountRecoveryRequest::query()->whereNull('invalidated_at')->update(['invalidated_at' => now(), 'invalidation_reason' => 'operator']);
                $this->travel(61)->minutes();
            }
        }
        $this->assertSame(10, AccountRecoveryRequest::query()->count(), '10 per address per rolling day');
    }

    #[Test]
    public function the_per_ip_limit_answers_429(): void
    {
        foreach (range(1, 10) as $i) {
            $this->requestRecovery("person{$i}@example.test", '192.0.2.77')->assertRedirect('/account-recovery');
        }

        $this->requestRecovery('another@example.test', '192.0.2.77')->assertStatus(429);
        $this->requestRecovery('another@example.test', '192.0.2.78')->assertRedirect('/account-recovery');
    }

    #[Test]
    public function the_global_limit_answers_429(): void
    {
        config(['account_recovery.limits.global_per_hour' => 3]);

        foreach (range(1, 3) as $i) {
            $this->requestRecovery("person{$i}@example.test", "192.0.2.{$i}")->assertRedirect('/account-recovery');
        }

        $this->requestRecovery('another@example.test', '192.0.2.200')->assertStatus(429);
    }

    #[Test]
    public function recovery_is_served_on_the_platform_host_only(): void
    {
        $school = $this->createSchool();
        $this->createSchoolDomain($school, 'erp.northfield.org');

        $this->get('http://localhost:8000/account-recovery')->assertOk();

        $this->get('http://erp.northfield.org/account-recovery')->assertNotFound();
        $this->post('http://erp.northfield.org/account-recovery', ['email' => 'a@example.test'])->assertNotFound();
        $this->get('http://erp.northfield.org/account-recovery/'.str_repeat('A', 22))->assertNotFound();

        $this->get('http://unknown-host.example/account-recovery')->assertStatus(421);
    }

    #[Test]
    public function the_pages_are_private_no_store_and_send_no_referrer(): void
    {
        foreach (['/account-recovery', '/account-recovery/'.str_repeat('A', 22)] as $path) {
            $response = $this->get($path)->assertOk();
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), $path);
            $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'), $path);
        }
    }

    #[Test]
    public function a_signed_in_user_may_use_the_page_and_nothing_accepts_a_return_url(): void
    {
        $user = $this->createUser(['email' => 'eligible@example.test']);

        $this->actingAs($user)->post('/account-recovery', ['email' => 'eligible@example.test', 'redirect' => 'https://evil.example/', 'return' => '//evil.example'])
            ->assertRedirect('/account-recovery');
        $this->assertInstanceOf(User::class, User::query()->find($user->id));
    }
}
