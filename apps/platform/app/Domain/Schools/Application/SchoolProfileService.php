<?php

namespace App\Domain\Schools\Application;

use App\Domain\Schools\Events\SchoolProfileUpdated;
use App\Models\EducationBoard;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Validation\InstitutionalProfileRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ORG.12C (ADR 0080 §8): ordinary School profile edits -- institutional
 * contact (email, phone, website), the structured address and the
 * education board (plus the display name and code, which the API also
 * edits) -- for the web page and the API alike.
 *
 * - Whole-School `school.profile.manage`; never `status` (rule 75) and never
 *   the institutional identity (legal name, registration and affiliation
 *   references), which only SchoolIdentityService writes.
 * - Contacts belong to the institution, never to a User account.
 * - Audit metadata names the changed fields only; addresses and contact
 *   values are not copied into it.
 */
class SchoolProfileService
{
    public const array FIELDS = [
        'name', 'code',
        'email', 'phone', 'website',
        'address_line1', 'address_line2', 'city', 'state_region', 'postal_code', 'country_code',
        'education_board_id',
    ];

    public function __construct(
        private readonly CapabilityResolver $capabilities,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input  only the submitted fields are changed
     * @return list<string> the changed fields
     */
    public function update(School $school, array $input, User $actor): array
    {
        if (! $this->capabilities->canInSchool($actor, 'school.profile.manage', $school)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        $input = InstitutionalProfileRules::normalize(array_intersect_key($input, array_flip(self::FIELDS)));
        $validated = Validator::make($input, [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:32', Rule::unique('schools', 'code')->ignore($school->id)],
            ...InstitutionalProfileRules::contact($school->phone, 'sometimes'),
            'website' => InstitutionalProfileRules::website('sometimes'),
            ...InstitutionalProfileRules::address('sometimes', countryNullable: false),
            'education_board_id' => ['sometimes', 'nullable', 'uuid'],
        ])->validate();

        if (($validated['education_board_id'] ?? null) !== null
            && ! EducationBoard::query()->whereKey($validated['education_board_id'])->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['education_board_id' => 'Choose an active education board.']);
        }

        return DB::transaction(function () use ($school, $validated, $actor): array {
            $school->fill($validated);
            $changed = array_keys($school->getDirty());
            if ($changed === []) {
                return [];
            }
            $school->save();

            $this->audit->school($school, 'school.profile_updated', actor: $actor, subject: $school, metadata: ['changed' => $changed]);
            event(new SchoolProfileUpdated($school->id, $changed));

            return $changed;
        });
    }
}
