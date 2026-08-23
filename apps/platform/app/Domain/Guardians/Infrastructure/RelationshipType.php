<?php

namespace App\Domain\Guardians\Infrastructure;

/**
 * The family-relationship dimension of a Student<->Guardian pair --
 * deliberately independent of legal/emergency/pickup authority, which
 * live as their own boolean columns on StudentGuardianRelationship (a
 * grandparent can be the legal guardian without collapsing that
 * distinction into this enum). Stored as a plain string column
 * (`relationship_type`), matching every other constrained-value column
 * in this codebase (GradeLevel/AcademicYear/Section/Subject `status`
 * columns are plain strings validated at the application layer, not a
 * PostgreSQL native enum type) -- this is the first such column backed
 * by a native PHP enum class via Eloquent's enum cast, for the
 * type-safety a "which of ten fixed values" domain concept warrants,
 * without introducing a parallel storage mechanism.
 */
enum RelationshipType: string
{
    case Mother = 'mother';
    case Father = 'father';
    case GenericParent = 'parent'; // PHP forbids an enum case literally named `Parent` (reserved)
    case StepParent = 'step_parent';
    case Grandparent = 'grandparent';
    case LegalGuardian = 'legal_guardian';
    case FosterGuardian = 'foster_guardian';
    case Sibling = 'sibling';
    case Relative = 'relative';
    case Other = 'other';
}
