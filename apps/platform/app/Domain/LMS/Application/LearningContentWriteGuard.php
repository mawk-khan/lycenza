<?php

namespace App\Domain\LMS\Application;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\LMS\Application\Ownership\SectionAudience;
use App\Domain\LMS\Infrastructure\LearningContent;
use App\Models\School;

/**
 * TCH.5C (ADR 0063 section 36) -- the optional Tier 2 check the
 * LearningContentService runs INSIDE its own transaction, before any
 * Learning Content row lock. Administrative (Tier 1) callers pass none.
 */
interface LearningContentWriteGuard
{
    /**
     * Holds identity and every audience Section's ownership, and returns the
     * server-derived ownership of the row about to be created.
     *
     * @param  list<string>  $sectionIds
     */
    public function beforeCreate(School $school, SubjectOffering $offering, array $sectionIds): SectionAudience;

    /** Holds identity and ownership before an existing row is changed. */
    public function beforeWrite(School $school, LearningContent $content): void;
}
