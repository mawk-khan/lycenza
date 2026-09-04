<?php

namespace Tests\Feature\Auth\Mfa;

use App\Models\User;
use App\Models\UserMfaRecoveryCode;
use App\Support\Auth\Mfa\MfaRecoveryCodeService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MfaRecoveryCodeServiceTest extends TestCase
{
    #[Test]
    public function issue_creates_the_configured_number_of_codes(): void
    {
        $user = User::factory()->create();

        $codes = app(MfaRecoveryCodeService::class)->issue($user);

        $this->assertCount((int) config('mfa.recovery_codes_count'), $codes);
        $this->assertSame((int) config('mfa.recovery_codes_count'), UserMfaRecoveryCode::query()->where('user_id', $user->id)->whereNull('consumed_at')->count());
    }

    #[Test]
    public function consume_accepts_a_valid_unused_code_exactly_once(): void
    {
        $user = User::factory()->create();
        $service = app(MfaRecoveryCodeService::class);
        $codes = $service->issue($user);

        $this->assertTrue($service->consume($user, $codes[0]));
        $this->assertFalse($service->consume($user, $codes[0]), 'A code must not be usable twice.');
    }

    #[Test]
    public function consume_rejects_an_unknown_code(): void
    {
        $user = User::factory()->create();
        app(MfaRecoveryCodeService::class)->issue($user);

        $this->assertFalse(app(MfaRecoveryCodeService::class)->consume($user, 'NOTREAL-CODE1'));
    }

    #[Test]
    public function consume_never_matches_another_users_code(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $service = app(MfaRecoveryCodeService::class);
        $codesA = $service->issue($userA);

        $this->assertFalse($service->consume($userB, $codesA[0]));
    }

    #[Test]
    public function issuing_a_new_set_invalidates_every_previously_unused_code(): void
    {
        $user = User::factory()->create();
        $service = app(MfaRecoveryCodeService::class);
        $firstSet = $service->issue($user);

        $service->issue($user);

        $this->assertFalse($service->consume($user, $firstSet[0]), 'Regeneration must invalidate the prior set.');
    }

    #[Test]
    public function remaining_count_reflects_only_unconsumed_codes(): void
    {
        $user = User::factory()->create();
        $service = app(MfaRecoveryCodeService::class);
        $codes = $service->issue($user);

        $service->consume($user, $codes[0]);

        $this->assertSame(count($codes) - 1, $service->remainingCount($user));
    }

    #[Test]
    public function generated_codes_are_never_stored_in_plaintext(): void
    {
        $user = User::factory()->create();
        $codes = app(MfaRecoveryCodeService::class)->issue($user);

        $stored = UserMfaRecoveryCode::query()->where('user_id', $user->id)->pluck('code_hash')->all();

        foreach ($codes as $plaintext) {
            $this->assertNotContains($plaintext, $stored, 'A recovery code must never be stored in plaintext.');
        }
    }
}
