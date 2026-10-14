<?php

namespace App\Domain\AcademicStructure\Application\Concerns;

use App\Models\School;
use App\Models\User;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\CodeNormalizer;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;

/**
 * SWC.2 (ADR 0073 section 4.2): the shared shape of the Academic Structure
 * reference-data administration services, which both the `/api/v1` JSON
 * controllers and the staff web controllers call -- one implementation of
 * each write, never a copy per surface.
 *
 *   - Authorization: the capability is re-checked here for the acting User
 *     (defence in depth; both controllers already checked it first, so the
 *     API's authorize-before-validate order is unchanged).
 *   - Validation: the caller's raw input is validated here, after the code
 *     is normalized with the one CodeNormalizer, so `Rule::unique()` compares
 *     the stored form ("math" and "MATH" collide) and a ValidationException
 *     renders as the API's 422 envelope or as Inertia field errors alike.
 *   - Races: the database unique constraint stays the final boundary. A
 *     concurrent duplicate that passed validation is re-validated after the
 *     failed write, so it surfaces as the same field error -- never a 500.
 */
trait AdministersReferenceData
{
    use AuthorizesCapability;

    private function authorizeFor(User $actor, string $capability, School $school): void
    {
        $this->authorizeCapabilityFor($actor, $capability, $school);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validateInput(array $input, array $rules): array
    {
        if (array_key_exists('code', $input) && is_string($input['code'])) {
            $input['code'] = CodeNormalizer::normalize($input['code']);
        }

        return Validator::make($input, $rules)->validate();
    }

    /**
     * Runs the write; on a unique-constraint violation re-runs $revalidate
     * (which throws the field-level ValidationException once the competing
     * row is visible) and only rethrows when it does not.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    private function writeUnique(Closure $write, Closure $revalidate): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            $revalidate();

            throw $e;
        }
    }
}
