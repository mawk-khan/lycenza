<?php

namespace App\Domain\Library\Application\Setup;

use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Library\Infrastructure\LibraryFinePolicy;
use App\Domain\Library\Infrastructure\LibraryTitle;
use App\Models\School;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupReadinessProvider;

/**
 * ORG.12A (ADR 0080 §10): Library readiness -- an active title, an active
 * copy and an active fine policy. EXISTS answers only; never a borrower,
 * a loan or a fine amount.
 */
class LibrarySetupReadiness implements SetupReadinessProvider
{
    public function keys(): array
    {
        return ['library.library'];
    }

    public function evaluate(string $key, School $school): SetupReadiness
    {
        return SetupReadiness::fromChecks(
            [
                'titles' => LibraryTitle::query()->where('school_id', $school->id)->where('status', 'active')->exists(),
                'copies' => LibraryCopy::query()->where('school_id', $school->id)->where('status', 'active')->exists(),
                'fine_policy' => LibraryFinePolicy::query()->where('school_id', $school->id)->where('status', LibraryFinePolicy::STATUS_ACTIVE)->exists(),
            ],
            configured: 'Titles, copies and a fine policy are set up.',
            incomplete: 'Catalogue titles and copies, and activate a fine policy.',
        );
    }
}
