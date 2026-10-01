<?php

namespace App\Domain\Platform\Application\Schools;

use App\Domain\Platform\Application\Elevation\ElevationEndReason;
use App\Domain\Platform\Application\Elevation\SchoolElevationService;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Domains\DomainDirectory;
use App\Support\Tenancy\SchoolStatus;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Phase 0N.9 (ADR 0047 sections 2-3, 5, 8, 10): the only code that creates
 * a School or changes `schools.status` -- CREATE, ACTIVATE, SUSPEND,
 * RESUME, and (E21.2F, E21-D11) CLOSE and REOPEN. There is no archive and
 * no delete (legal gate, section 12): closure freezes and records, it never
 * removes anything.
 *
 * Every operation runs SchoolLifecycleAuthority's checks, then one
 * transaction that locks the School row, re-checks the state under the
 * lock (a racing operation is refused as an invalid transition, never
 * repeated) and writes the change with its platform audit event. The
 * database independently refuses any other transition.
 */
class SchoolLifecycleService
{
    public function __construct(
        private readonly SchoolLifecycleAuthority $authority,
        private readonly SchoolBootstrapAdministrationService $bootstrap,
        private readonly SchoolElevationService $elevations,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * CREATE: a `provisioning` School and its bootstrap School
     * Administrator, in one transaction. Never activates it, adds it to a
     * Group, elevates anyone or creates tenant-domain records.
     *
     * @param  array<string, mixed>  $input  name, slug, optional code, admin
     */
    public function create(Request $request, User $actor, array $input, mixed $confirmed, mixed $code): School
    {
        $operation = SchoolLifecycleOperation::Create;
        $this->authority->authorize($request, $actor, $operation, null);

        [$name, $slug, $schoolCode] = $this->validated($input);
        $target = $this->authority->bootstrapTarget($request, $actor, $operation, null, $input['admin'] ?? null);

        $this->authority->confirmAndReverify($request, $actor, $operation, null, $confirmed, $code);

        try {
            return DB::transaction(function () use ($actor, $target, $name, $slug, $schoolCode): School {
                $school = School::query()->create([
                    'name' => $name,
                    'slug' => $slug,
                    'code' => $schoolCode,
                    'status' => SchoolStatus::Provisioning->value,
                ]);

                $membership = $this->bootstrap->establish($school, $target, $actor);

                $this->audit->platform(SchoolLifecycleAudit::CREATED, actor: $actor, subject: $school, metadata: [
                    'status' => SchoolStatus::Provisioning->value,
                ]);
                $this->audit->platform(SchoolLifecycleAudit::BOOTSTRAP_ADMIN_ASSIGNED, actor: $actor, subject: $school, metadata: [
                    'user_id' => $target->id,
                    'membership_id' => $membership->id,
                ]);

                return $school;
            });
        } catch (UniqueConstraintViolationException $e) {
            $field = str_contains($e->getMessage(), 'code') ? 'code' : 'slug';

            throw ValidationException::withMessages([$field => "That {$field} is already used by another School."]);
        }
    }

    /**
     * ACTIVATE: provisioning -> active, only with a qualifying School
     * administrator in that exact School. Closes the bootstrap path.
     */
    public function activate(Request $request, User $actor, School $school, mixed $confirmed, mixed $code): void
    {
        $operation = SchoolLifecycleOperation::Activate;
        $this->authority->authorize($request, $actor, $operation, $school);
        $this->requireStatus($request, $actor, $operation, $school, SchoolStatus::Provisioning);

        if ($this->authority->qualifyingAdministrators($school)->isEmpty()) {
            $this->denyAdminMissing($request, $actor, $school, $this->authority->missingAdministratorOutcome($school));
        }

        $this->authority->confirmAndReverify($request, $actor, $operation, $school, $confirmed, $code);

        $failure = DB::transaction(function () use ($actor, $school): ?string {
            $locked = School::query()->whereKey($school->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isProvisioning()) {
                return 'invalid_transition';
            }

            if ($this->authority->qualifyingAdministrators($locked)->isEmpty()) {
                return $this->authority->missingAdministratorOutcome($locked);
            }

            $locked->update(['status' => SchoolStatus::Active->value]);
            $this->transitionAudit(SchoolLifecycleAudit::ACTIVATED, $actor, $locked, SchoolStatus::Provisioning, SchoolStatus::Active);

            return null;
        });

        $this->failAfterTransaction($request, $actor, $operation, $school, $failure);
        // ADR 0054 section 8.8: a custom domain resolves only while its School
        // is active -- the Host cache must not outlive the change.
        app(DomainDirectory::class)->forgetSchool($school->id);
    }

    /**
     * SUSPEND: active -> suspended with a closed reason code. In the same
     * transaction every active elevation into the School (Platform- or
     * Group-derived) ends with `school_suspended`. Deletes, revokes and
     * removes nothing.
     */
    public function suspend(Request $request, User $actor, School $school, mixed $reasonCode, mixed $confirmed, mixed $code): void
    {
        $operation = SchoolLifecycleOperation::Suspend;
        $this->authority->authorize($request, $actor, $operation, $school);
        $this->requireStatus($request, $actor, $operation, $school, SchoolStatus::Active);

        $reason = is_string($reasonCode) ? SchoolSuspensionReason::tryFrom($reasonCode) : null;

        if ($reason === null) {
            throw ValidationException::withMessages(['reason_code' => 'Choose one of the listed reasons.']);
        }

        $this->authority->confirmAndReverify($request, $actor, $operation, $school, $confirmed, $code);

        $failure = DB::transaction(function () use ($actor, $school, $reason): ?string {
            $locked = School::query()->whereKey($school->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isActive()) {
                return 'invalid_transition';
            }

            $locked->update(['status' => SchoolStatus::Suspended->value]);
            $this->transitionAudit(SchoolLifecycleAudit::SUSPENDED, $actor, $locked, SchoolStatus::Active, SchoolStatus::Suspended, ['reason_code' => $reason->value]);

            $this->elevations->terminateWhere(
                fn ($query) => $query->where('school_id', $locked->id),
                ElevationEndReason::SchoolSuspended,
            );

            return null;
        });

        $this->failAfterTransaction($request, $actor, $operation, $school, $failure);
        // ADR 0054 section 8.8: a custom domain resolves only while its School
        // is active -- the Host cache must not outlive the change.
        app(DomainDirectory::class)->forgetSchool($school->id);
    }

    /**
     * RESUME: suspended -> active. Recreates nothing, restores no ended
     * elevation and replays nothing: deferred deliveries and held
     * announcements continue through their normal schedulers.
     */
    public function resume(Request $request, User $actor, School $school, mixed $confirmed, mixed $code): void
    {
        $operation = SchoolLifecycleOperation::Resume;
        $this->authority->authorize($request, $actor, $operation, $school);
        $this->requireStatus($request, $actor, $operation, $school, SchoolStatus::Suspended);
        $this->requireNotClosed($request, $actor, $operation, $school);

        $this->authority->confirmAndReverify($request, $actor, $operation, $school, $confirmed, $code);

        $failure = DB::transaction(function () use ($actor, $school): ?string {
            $locked = School::query()->whereKey($school->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isSuspended() || $locked->isClosed()) {
                return 'invalid_transition';
            }

            $locked->update(['status' => SchoolStatus::Active->value]);
            $this->transitionAudit(SchoolLifecycleAudit::RESUMED, $actor, $locked, SchoolStatus::Suspended, SchoolStatus::Active);

            return null;
        });

        $this->failAfterTransaction($request, $actor, $operation, $school, $failure);
        // ADR 0054 section 8.8: a custom domain resolves only while its School
        // is active -- the Host cache must not outlive the change.
        app(DomainDirectory::class)->forgetSchool($school->id);
    }

    /**
     * E21.2F (E21-D11) CLOSE: freeze -> retain -> controlled purge, step one.
     * An `active` or `suspended` School becomes (or stays) `suspended` and
     * is marked closed with a closed reason code, in one transaction under
     * the School row lock. Closing an active School ends every elevation
     * into it, exactly like a suspension.
     *
     * It deletes, revokes and removes nothing: memberships, Students,
     * Employees, Finance, Documents and audit all stay. Category retention
     * continues through its own scheduled commands, which walk closed
     * Schools (the School-walker allowlist). Closing an already-closed
     * School changes nothing (idempotent).
     */
    public function close(Request $request, User $actor, School $school, mixed $reasonCode, mixed $confirmed, mixed $code): void
    {
        $operation = SchoolLifecycleOperation::Close;
        $this->authority->authorize($request, $actor, $operation, $school);

        $fresh = $school->fresh();
        if ($fresh === null || ! in_array($fresh->lifecycleStatus(), [SchoolStatus::Active, SchoolStatus::Suspended], true)) {
            $this->authority->deny($request, $actor, $operation, $school, 'invalid_transition', 409, 'school', 'That change is not possible from the School\'s current status.');
        }

        $reason = is_string($reasonCode) ? SchoolClosureReason::tryFrom($reasonCode) : null;

        if ($reason === null) {
            throw ValidationException::withMessages(['reason_code' => 'Choose one of the listed reasons.']);
        }

        $this->authority->confirmAndReverify($request, $actor, $operation, $school, $confirmed, $code);

        $failure = DB::transaction(function () use ($actor, $school, $reason): ?string {
            $locked = School::query()->whereKey($school->id)->lockForUpdate()->first();

            if ($locked === null || ! ($locked->isActive() || $locked->isSuspended())) {
                return 'invalid_transition';
            }

            if ($locked->isClosed()) {
                return null;
            }

            $from = $locked->lifecycleStatus() ?? SchoolStatus::Suspended;
            $locked->forceFill([
                'status' => SchoolStatus::Suspended->value,
                'closed_at' => now(),
                'closure_reason' => $reason->value,
                'closed_by_user_id' => $actor->id,
            ])->save();
            $this->transitionAudit(SchoolLifecycleAudit::CLOSED, $actor, $locked, $from, SchoolStatus::Suspended, ['reason_code' => $reason->value]);

            if ($from === SchoolStatus::Active) {
                $this->elevations->terminateWhere(
                    fn ($query) => $query->where('school_id', $locked->id),
                    ElevationEndReason::SchoolSuspended,
                );
            }

            return null;
        });

        $this->failAfterTransaction($request, $actor, $operation, $school, $failure);
        app(DomainDirectory::class)->forgetSchool($school->id);
    }

    /**
     * E21.2F REOPEN: withdraw a closure. A closed School returns to
     * `active` and its closure marker is cleared in the same write (the
     * database refuses an active School with a closure). Nothing is
     * restored or replayed, exactly like a resume, so a mistaken closure
     * never needs a backup restore.
     */
    public function reopen(Request $request, User $actor, School $school, mixed $confirmed, mixed $code): void
    {
        $operation = SchoolLifecycleOperation::Reopen;
        $this->authority->authorize($request, $actor, $operation, $school);

        if ($school->fresh()?->isClosed() !== true) {
            $this->authority->deny($request, $actor, $operation, $school, 'invalid_transition', 409, 'school', 'That change is not possible from the School\'s current status.');
        }

        $this->authority->confirmAndReverify($request, $actor, $operation, $school, $confirmed, $code);

        $failure = DB::transaction(function () use ($actor, $school): ?string {
            $locked = School::query()->whereKey($school->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->isClosed()) {
                return 'invalid_transition';
            }

            $locked->forceFill(['status' => SchoolStatus::Active->value, 'closed_at' => null, 'closure_reason' => null, 'closed_by_user_id' => null])->save();
            $this->transitionAudit(SchoolLifecycleAudit::REOPENED, $actor, $locked, SchoolStatus::Suspended, SchoolStatus::Active);

            return null;
        });

        $this->failAfterTransaction($request, $actor, $operation, $school, $failure);
        app(DomainDirectory::class)->forgetSchool($school->id);
    }

    private function requireNotClosed(Request $request, User $actor, SchoolLifecycleOperation $operation, School $school): void
    {
        if ($school->fresh()?->isClosed() === true) {
            $this->authority->deny($request, $actor, $operation, $school, 'school_closed', 409, 'school', 'This School is closed. Reopen it to make it operational again.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function validated(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $slug = strtolower(trim((string) ($input['slug'] ?? '')));
        $code = strtoupper(trim((string) ($input['code'] ?? '')));

        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'Enter the School\'s name (at most 255 characters).']);
        }

        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1 || strlen($slug) > 100) {
            throw ValidationException::withMessages(['slug' => 'Use lowercase letters, digits and single hyphens.']);
        }

        if ($code !== '' && (preg_match('/^[A-Z0-9][A-Z0-9_-]*$/', $code) !== 1 || strlen($code) > 32)) {
            throw ValidationException::withMessages(['code' => 'Use letters, digits, hyphens or underscores (at most 32).']);
        }

        if (School::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['slug' => 'That slug is already used by another School.']);
        }

        if ($code !== '' && School::query()->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => 'That code is already used by another School.']);
        }

        return [$name, $slug, $code === '' ? null : $code];
    }

    private function requireStatus(Request $request, User $actor, SchoolLifecycleOperation $operation, School $school, SchoolStatus $expected): void
    {
        if ($school->fresh()?->lifecycleStatus() !== $expected) {
            $this->authority->deny($request, $actor, $operation, $school, 'invalid_transition', 409, 'school', 'That change is not possible from the School\'s current status.');
        }
    }

    private function denyAdminMissing(Request $request, User $actor, School $school, string $outcome = 'admin_missing'): never
    {
        $outcome === 'admin_not_activated'
            ? $this->authority->deny($request, $actor, SchoolLifecycleOperation::Activate, $school, $outcome, 422, 'school', 'The School\'s administrator has not activated their account yet. They must set their password with their activation link first.')
            : $this->authority->deny($request, $actor, SchoolLifecycleOperation::Activate, $school, 'admin_missing', 422, 'school', 'The School needs an active administrator before it can be activated.');
    }

    private function failAfterTransaction(Request $request, User $actor, SchoolLifecycleOperation $operation, School $school, ?string $failure): void
    {
        match ($failure) {
            null => null,
            'admin_missing', 'admin_not_activated' => $this->denyAdminMissing($request, $actor, $school, $failure),
            default => $this->authority->deny($request, $actor, $operation, $school, $failure, 409, 'school', 'That change is not possible from the School\'s current status.'),
        };
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function transitionAudit(string $event, User $actor, School $school, SchoolStatus $from, SchoolStatus $to, array $extra = []): void
    {
        $this->audit->platform($event, actor: $actor, subject: $school, metadata: [
            'from' => $from->value,
            'to' => $to->value,
        ] + $extra);
    }
}
