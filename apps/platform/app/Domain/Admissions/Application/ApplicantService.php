<?php

namespace App\Domain\Admissions\Application;

use App\Domain\Admissions\Application\Exceptions\AdmissionCorrectionNotPermittedException;
use App\Domain\Admissions\Application\Exceptions\AdmissionRecordChangedException;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only sanctioned write path for Applicant identity (Phase 1D.2) --
 * mirrors App\Domain\Students\Application\StudentService::create()'s
 * exact shape: `$attributes` are trusted, prevalidated primitives, no
 * domain validation beyond what Eloquent's own casts enforce
 * (docs/modules/ADMISSIONS.md §4 -- Applicant mirrors Student's own
 * identity field shape exactly, and HTTP-boundary validation is a
 * future 1D.5 concern, not this service's).
 *
 * Deliberately authorization-neutral, matching every other
 * Application-layer service in this codebase -- Phase 1D.4 owns the
 * `admissions.manage` capability check a future controller applies
 * before ever reaching this service.
 *
 * Audit metadata never carries an Applicant's name/date_of_birth
 * (Sensitive personal data of a minor,
 * docs/security/DATA-CLASSIFICATION.md) -- only the Applicant id
 * (captured automatically as the audit event's subject), mirroring
 * StudentService's identical "identity-safe by construction" audit
 * design.
 */
class ApplicantService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array{first_name: string, middle_name?: string|null, last_name?: string|null, date_of_birth: string}  $attributes
     */
    public function create(School $school, array $attributes, ?User $actor = null): Applicant
    {
        return $this->context->withSchool($school, fn () => DB::transaction(function () use ($school, $attributes, $actor) {
            $applicant = Applicant::query()->create([
                'school_id' => $school->id,
                'first_name' => $attributes['first_name'],
                'middle_name' => $attributes['middle_name'] ?? null,
                'last_name' => $attributes['last_name'] ?? null,
                'date_of_birth' => $attributes['date_of_birth'],
            ]);

            $this->audit->school($school, 'admission_applicant.created', actor: $actor, subject: $applicant);

            return $applicant;
        }));
    }

    /**
     * SIS 3.1: corrects an Applicant's identity (names, date of birth) while
     * none of their applications has been converted -- once one has, the
     * Student is the canonical identity and the Applicant stays the historical
     * snapshot conversion copied (ADMISSIONS.md §6, §8).
     *
     * Serialized with conversion: every application of the Applicant is
     * locked first (in id order), and conversion locks its application row
     * before it reads the Applicant, so a Student is created either before
     * this check sees `converted` (refused) or from the corrected identity.
     * `$expectedUpdatedAt` is the Applicant version the form was opened on; a
     * newer version is refused, never overwritten.
     *
     * Audit metadata names the corrected fields only, never their values.
     *
     * @param  array{first_name: string, middle_name?: string|null, last_name?: string|null, date_of_birth: string}  $attributes
     */
    public function correct(Applicant $applicant, array $attributes, string $expectedUpdatedAt, ?User $actor = null): Applicant
    {
        return $this->context->withSchool($applicant->school, fn () => DB::transaction(function () use ($applicant, $attributes, $expectedUpdatedAt, $actor) {
            $statuses = AdmissionApplication::query()->where('applicant_id', $applicant->id)
                ->orderBy('id')->lockForUpdate()->pluck('status');
            $locked = Applicant::query()->whereKey($applicant->id)->lockForUpdate()->firstOrFail();

            if ($statuses->contains('converted')) {
                throw AdmissionCorrectionNotPermittedException::applicantAdmitted();
            }
            if ($locked->updated_at?->toIso8601String() !== $expectedUpdatedAt) {
                throw new AdmissionRecordChangedException;
            }

            $current = [
                'first_name' => $locked->first_name,
                'middle_name' => $locked->middle_name,
                'last_name' => $locked->last_name,
                'date_of_birth' => $locked->date_of_birth->toDateString(),
            ];
            $corrected = [
                'first_name' => $attributes['first_name'],
                'middle_name' => ($attributes['middle_name'] ?? null) ?: null,
                'last_name' => ($attributes['last_name'] ?? null) ?: null,
                'date_of_birth' => Carbon::parse($attributes['date_of_birth'])->toDateString(),
            ];
            $changed = array_keys(array_filter($corrected, fn ($value, $field) => $value !== $current[$field], ARRAY_FILTER_USE_BOTH));

            if ($changed === []) {
                return $locked;
            }

            $locked->fill($corrected)->save();

            $this->audit->school($locked->school, 'admission_applicant.corrected', actor: $actor, subject: $locked, metadata: [
                'fields' => $changed,
            ]);

            return $locked;
        }));
    }
}
