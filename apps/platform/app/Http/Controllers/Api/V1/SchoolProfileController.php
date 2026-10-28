<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Schools\Application\SchoolIdentityService;
use App\Domain\Schools\Application\SchoolProfileService;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 0D sections 7-8, 52. Deliberately does NOT accept `status` --
 * that column is the PLATFORM tenant-lifecycle state (Phase 0B), never
 * writable through a School-scoped capability. See School.php's
 * docblock and docs/modules/ORGANIZATION.md.
 */
class SchoolProfileController extends Controller
{
    use AuthorizesCapability;

    public function show(School $school): JsonResponse
    {
        $this->authorizeCapability('school.profile.view', $school);

        return response()->json(['data' => $this->present($school)]);
    }

    public function update(Request $request, School $school, SchoolProfileService $profiles): JsonResponse
    {
        $this->authorizeCapability('school.profile.manage', $school);

        // ORG.12C (ADR 0080 §8): the institutional identity changes only through
        // the web identity form (fresh MFA, closed reason, stale-form check).
        $request->validate(array_fill_keys(SchoolIdentityService::FIELDS, ['prohibited']), [
            'prohibited' => 'The institutional identity is changed only from the School profile page, with a fresh MFA code and a reason.',
        ]);
        $profiles->update($school, $request->only(SchoolProfileService::FIELDS), $request->user());

        return response()->json(['data' => $this->present($school->refresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(School $school): array
    {
        return [
            'id' => $school->id,
            'name' => $school->name,
            'legalName' => $school->legal_name,
            'code' => $school->code,
            'status' => $school->status,
            'timezone' => $school->timezone,
            'defaultLocale' => $school->default_locale,
            'website' => $school->website,
            'email' => $school->email,
            'phone' => $school->phone,
            'addressLine1' => $school->address_line1,
            'addressLine2' => $school->address_line2,
            'city' => $school->city,
            'stateRegion' => $school->state_region,
            'postalCode' => $school->postal_code,
            'countryCode' => $school->country_code,
            'educationBoardId' => $school->education_board_id,
            'registrationReference' => $school->registration_reference,
            'affiliationReference' => $school->affiliation_reference,
        ];
    }
}
