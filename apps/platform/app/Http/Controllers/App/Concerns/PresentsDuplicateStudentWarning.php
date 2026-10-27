<?php

namespace App\Http\Controllers\App\Concerns;

use App\Domain\Students\Application\Exceptions\PossibleDuplicateStudentException;
use App\Domain\Students\Application\StudentDuplicateCandidateReader;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * SIS 2.3: the browser side of a duplicate-risk refusal, shared by Add
 * Student, Admissions conversion and Campus onboarding.
 *
 * The refused POST flashes only the candidate ids, the acknowledgement and
 * the form it belongs to (never names or dates of birth), then fails like
 * any validation error so the form keeps its input. The page's GET re-reads
 * what the actor may see of those candidates (StudentDuplicateCandidateReader)
 * and shows the warning. "Create a different Student" resubmits the same
 * form with `duplicate_acknowledgement`; the service re-evaluates the
 * candidates and refuses again if they changed.
 */
trait PresentsDuplicateStudentWarning
{
    private const DUPLICATE_WARNING_SESSION_KEY = 'studentDuplicateWarning';

    /** Never returns. */
    protected function refuseAsPossibleDuplicate(Request $request, string $form, PossibleDuplicateStudentException $e): never
    {
        $request->session()->flash(self::DUPLICATE_WARNING_SESSION_KEY, [
            'form' => $form,
            'candidateIds' => $e->candidateIds,
            'acknowledgement' => $e->acknowledgement,
        ]);

        throw ValidationException::withMessages([
            'duplicate_acknowledgement' => ['A Student with similar identity details already exists. Check the existing record before you continue.'],
        ]);
    }

    /**
     * The warning for this form, or null.
     *
     * @return array{acknowledgement: string, candidateCount: int, hiddenCount: int, candidates: list<array<string, mixed>>}|null
     */
    protected function duplicateWarningFor(Request $request, School $school, User $actor, string $form, bool $campusSurface = false): ?array
    {
        $flashed = $request->hasSession() ? $request->session()->get(self::DUPLICATE_WARNING_SESSION_KEY) : null;
        if (! is_array($flashed) || ($flashed['form'] ?? null) !== $form || ! is_array($flashed['candidateIds'] ?? null) || ! is_string($flashed['acknowledgement'] ?? null)) {
            return null;
        }

        return [
            'acknowledgement' => $flashed['acknowledgement'],
            ...app(StudentDuplicateCandidateReader::class)->present($school, $actor, array_values(array_filter($flashed['candidateIds'], 'is_string')), $campusSurface),
        ];
    }
}
