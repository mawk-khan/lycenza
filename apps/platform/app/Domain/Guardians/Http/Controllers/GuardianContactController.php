<?php

namespace App\Domain\Guardians\Http\Controllers;

use App\Domain\Guardians\Application\GuardianContactService;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\GuardianContact;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Phase 1A.5: administrative mutation endpoints over
 * App\Domain\Guardians\Application\GuardianContactService (Phase
 * 1A.3) -- never rewritten here, never duplicated. This controller's
 * only cryptography-adjacent responsibility is translating the two
 * expected failure modes GuardianContactService can raise
 * (InvalidArgumentException from EmailNormalizer/PhoneNormalizer;
 * UniqueConstraintViolationException from the database's own duplicate/
 * second-primary constraints) into the established validation response
 * shape, so a plausible user action never surfaces a raw 500. No
 * verification workflow (OTP/email/SMS) exists -- `verified_at` remains
 * metadata only.
 */
class GuardianContactController extends Controller
{
    use AuthorizesCapability;

    public function store(Request $request, School $school, string $guardian, GuardianContactService $service): JsonResponse
    {
        $this->authorizeCapability('guardians.manage', $school);

        $model = Guardian::query()->findOrFail($guardian);

        $validated = $request->validate([
            'type' => ['required', Rule::enum(ContactType::class)],
            'value' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        $attributes = collect($validated)->only(['label', 'is_primary'])->all();

        try {
            $contact = $service->create(
                $model,
                ContactType::from($validated['type']),
                $validated['value'],
                $attributes,
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['value' => [$e->getMessage()]]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'value' => ['This contact conflicts with an existing record for this Guardian (a duplicate value, or an existing active primary contact of this type).'],
            ]);
        }

        return response()->json(['data' => $this->presentContact($contact)], 201);
    }

    public function setPrimary(Request $request, School $school, string $contact, GuardianContactService $service): JsonResponse
    {
        $this->authorizeCapability('guardians.manage', $school);

        $model = GuardianContact::query()->findOrFail($contact);

        $updated = $service->setPrimary($model, $request->user());

        return response()->json(['data' => $this->presentContact($updated)]);
    }

    public function deactivate(Request $request, School $school, string $contact, GuardianContactService $service): JsonResponse
    {
        $this->authorizeCapability('guardians.manage', $school);

        $model = GuardianContact::query()->findOrFail($contact);

        $updated = $service->deactivate($model, $request->user());

        return response()->json(['data' => $this->presentContact($updated)]);
    }

    /**
     * Approved contact fields only -- see
     * App\Domain\Guardians\Http\Controllers\GuardianController's
     * identical helper docblock. Never `encrypted_value`/`lookup_hash`/
     * `lookup_key_version`.
     *
     * @return array<string, mixed>
     */
    private function presentContact(GuardianContact $contact): array
    {
        return [
            'id' => $contact->id,
            'type' => $contact->type->value,
            'value' => $contact->encrypted_value,
            'label' => $contact->label,
            'isPrimary' => $contact->is_primary,
            'isActive' => $contact->is_active,
            'verifiedAt' => $contact->verified_at?->toIso8601String(),
        ];
    }
}
