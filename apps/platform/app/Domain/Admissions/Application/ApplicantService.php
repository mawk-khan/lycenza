<?php

namespace App\Domain\Admissions\Application;

use App\Domain\Admissions\Infrastructure\Applicant;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
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
}
