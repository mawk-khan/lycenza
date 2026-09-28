<?php

namespace Tests\Feature\Identity\Staff;

use App\Http\Middleware\RequireSchoolContext;
use App\Models\MembershipRoleAssignment;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Email\EmailPurpose;
use App\Support\Email\Providers\OutboundEmail;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

/**
 * Phase 0O.12B (ADR 0059) test helpers: School administrators with an MFA
 * factor and single-use recovery codes (a fresh code per mutation), the
 * Staff accounts HTTP surface, and the invitation link read from the fake
 * email provider (selector + fragment secret).
 */
trait StaffAccountTestHelpers
{
    public const STAFF_PASSWORD = 'staff-password-1234';

    /** @var array<string, list<string>> */
    private array $staffCodes = [];

    /**
     * An active School Admin (credential, MFA factor, recovery codes).
     *
     * @return array{0: User, 1: School, 2: SchoolMembership}
     */
    protected function staffAdmin(?School $school = null, array $attributes = []): array
    {
        $school ??= $this->createSchool();
        $user = $this->createUser(['password' => Hash::make(self::STAFF_PASSWORD)] + $attributes);
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, 'school_admin');
        $this->enrollActiveMfaFactor($user);
        $this->staffCodes[$user->id] = $this->issueRecoveryCodes($user, 12);

        return [$user, $school, $membership];
    }

    /** A staff member holding one role (no MFA). @return array{0: User, 1: SchoolMembership} */
    protected function staffMember(School $school, string $role = 'principal', array $attributes = []): array
    {
        $user = $this->createUser(['password' => Hash::make(self::STAFF_PASSWORD)] + $attributes);
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, $role);

        return [$user, $membership];
    }

    protected function withMfaCodes(User $user): User
    {
        if (! isset($this->staffCodes[$user->id])) {
            $this->enrollActiveMfaFactor($user);
            $this->staffCodes[$user->id] = $this->issueRecoveryCodes($user, 12);
        }

        return $user;
    }

    protected function staffCode(User $user): string
    {
        return array_shift($this->staffCodes[$user->id]);
    }

    protected function enterSchool(User $user, School $school): void
    {
        $this->actingAs($user)->withSession([RequireSchoolContext::SESSION_KEY => $school->id]);
    }

    /**
     * A Staff accounts mutation as $actor in $school, with a fresh code.
     *
     * @param  array<string, mixed>  $data
     */
    protected function staffPost(User $actor, School $school, string $path, array $data = [], bool $withCode = true): TestResponse
    {
        RateLimiter::clear(md5('staff-account-management'.$actor->id));
        $this->enterSchool($actor, $school);

        if ($withCode && isset($this->staffCodes[$actor->id])) {
            $data['mfa_code'] = $this->staffCode($actor);
        }

        return $this->postJson('http://localhost/app/settings/staff'.$path, $data);
    }

    /** @param list<string> $roles */
    protected function inviteStaff(User $actor, School $school, string $email, array $roles = ['principal']): TestResponse
    {
        return $this->staffPost($actor, $school, '/invitations', ['email' => $email, 'roles' => $roles]);
    }

    /** @return array{0: string, 1: string} [selector, secret] of the last staff invitation email */
    protected function staffLink(?OutboundEmail $mail = null): array
    {
        $mail ??= $this->lastAcceptedEmail();
        $this->assertSame(EmailPurpose::StaffAccountInvitation, $mail->purpose);
        $this->assertSame(1, preg_match('#/invitations/[0-9a-f-]{36}/staff/([A-Za-z0-9_-]{22})\#([A-Za-z0-9_-]{43})#', $mail->text, $m), 'the link carries the secret in its fragment');

        return [$m[1], $m[2]];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function acceptStaff(School $school, string $selector, string $secret, array $data = []): TestResponse
    {
        return $this->post("http://localhost/invitations/{$school->id}/staff/{$selector}", $data + [
            'secret' => $secret,
            'mode' => 'new',
            'name' => 'New Staff',
            'password' => self::STAFF_PASSWORD,
            'password_confirmation' => self::STAFF_PASSWORD,
        ]);
    }

    /** @return list<string> active role keys of a membership */
    protected function activeRoles(SchoolMembership $membership): array
    {
        return app(TenantContext::class)->withSchool($membership->school, fn () => MembershipRoleAssignment::query()
            ->where('school_membership_id', $membership->id)->active()->with('role')->get()
            ->map(fn (MembershipRoleAssignment $a) => $a->role->key)->sort()->values()->all());
    }

    /** @return list<array{role: string, revoked: bool, reason: ?string}> the full grant history */
    protected function grantHistory(SchoolMembership $membership): array
    {
        return app(TenantContext::class)->withSchool($membership->school, fn () => MembershipRoleAssignment::query()
            ->where('school_membership_id', $membership->id)->with('role')->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (MembershipRoleAssignment $a) => ['role' => $a->role->key, 'revoked' => $a->revoked_at !== null, 'reason' => $a->revocation_reason])->all());
    }
}
