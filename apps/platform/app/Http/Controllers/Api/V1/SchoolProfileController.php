<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Schools\Events\SchoolProfileUpdated;
use App\Http\Controllers\Controller;
use App\Models\EducationBoard;
use App\Models\School;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    public function update(Request $request, School $school): JsonResponse
    {
        $this->authorizeCapability('school.profile.manage', $school);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'website' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'state_region' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'country_code' => ['sometimes', 'string', 'size:2'],
            'education_board_id' => ['sometimes', 'nullable', 'string'],
        ]);

        if (array_key_exists('education_board_id', $validated) && $validated['education_board_id'] !== null) {
            $board = EducationBoard::query()->find($validated['education_board_id']);

            if ($board === null || ! $board->isActive()) {
                throw ValidationException::withMessages(['education_board_id' => ['This Education Board is not available.']]);
            }
        }

        $before = $school->only(array_keys($validated));

        DB::transaction(function () use ($school, $validated, $before, $request) {
            $school->update($validated);

            app(AuditRecorder::class)->school($school, 'school.profile_updated', actor: $request->user(), subject: $school, metadata: [
                'before' => $before,
                'after' => $validated,
            ]);

            event(new SchoolProfileUpdated($school->id, array_keys($validated)));
        });

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
        ];
    }
}
