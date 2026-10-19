<?php

namespace App\Domain\Library\Application;

use App\Domain\Campuses\Application\ActiveCampus;
use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Library\Infrastructure\LibraryTitle;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ORG.7D-5 (ADR 0076 §41): the one writer of a physical Copy's Campus and
 * status, for the whole-School catalogue (`library.catalogue.manage`) on web
 * and API alike. Campus-scoped circulation never moves a Copy.
 *
 * - A Copy is placed on an active Campus of this School (ActiveCampus) or on
 *   none (School-shared, whole-School circulation only).
 * - A Copy on an active loan never changes Campus: the move locks the Copy
 *   row FOR UPDATE -- the same lock checkout takes -- so a move and a
 *   checkout serialize, and the loan's frozen Campus is always the Copy's
 *   Campus at checkout. `library_copies_campus_move_guard` is the database
 *   backstop.
 * - The change and its audit commit together.
 */
final class LibraryCopyService
{
    public const string ON_LOAN_MESSAGE = 'A Copy on loan cannot change Campus. Check it in first.';

    public function __construct(private readonly ActiveCampus $campuses, private readonly AuditRecorder $audit) {}

    public function create(School $school, LibraryTitle $title, string $code, ?string $campusId, ?User $actor): LibraryCopy
    {
        return DB::transaction(function () use ($school, $title, $code, $campusId, $actor): LibraryCopy {
            if ($campusId !== null) {
                $this->campuses->require($school, $campusId);
            }

            $copy = LibraryCopy::query()->create([
                'school_id' => $school->id,
                'library_title_id' => $title->id,
                'campus_id' => $campusId,
                'code' => $code,
                'status' => 'active',
            ]);

            $this->audit->school($school, 'library.copy.created', actor: $actor, subject: $copy, metadata: [
                'libraryTitleId' => $title->id,
                'code' => $copy->code,
                'campusId' => $copy->campus_id,
            ]);

            return $copy;
        });
    }

    /**
     * @param  array{status?: string, campus_id?: string|null}  $changes
     */
    public function update(School $school, string $copyId, array $changes, ?User $actor): LibraryCopy
    {
        return DB::transaction(function () use ($school, $copyId, $changes, $actor): LibraryCopy {
            $copy = LibraryCopy::query()->where('school_id', $school->id)->whereKey($copyId)->lockForUpdate()->firstOrFail();

            if (array_key_exists('campus_id', $changes) && $changes['campus_id'] !== $copy->campus_id) {
                if ($changes['campus_id'] !== null) {
                    $this->campuses->require($school, $changes['campus_id']);
                }
                if ($copy->loans()->where('status', 'active')->exists()) {
                    throw ValidationException::withMessages(['campus_id' => [self::ON_LOAN_MESSAGE]]);
                }
            }

            $before = $copy->only(array_keys($changes));
            $copy->update($changes);

            $this->audit->school($school, 'library.copy.updated', actor: $actor, subject: $copy, metadata: [
                'before' => $before,
                'after' => $changes,
            ]);

            return $copy->refresh();
        });
    }
}
