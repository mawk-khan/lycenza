<?php

namespace App\Http\Controllers\App\Concerns;

use App\Domain\AcademicStructure\Application\Exceptions\AcademicStructureException;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * SWC.2 (ADR 0073 section 2.5): the web side of an Academic Structure
 * domain refusal. `bootstrap/app.php` renders AcademicStructureException as
 * its 4xx envelope for `api/*` only, so on an Inertia page it used to become
 * a 500. Here it becomes a field error on the form that caused it -- the
 * house pattern (StudentSubjectEnrollmentController) -- with the domain's own
 * message. The refusal itself, and every write it prevents, is unchanged.
 */
trait MapsAcademicStructureErrors
{
    /**
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T
     */
    private function withFormErrors(string $field, Closure $action): mixed
    {
        try {
            return $action();
        } catch (AcademicStructureException $e) {
            throw ValidationException::withMessages([$field => [$e->getMessage()]]);
        }
    }
}
