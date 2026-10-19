<?php

namespace App\Domain\CurriculumDelivery\Application;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * ORG.7D-1 (ADR 0076 §37): the Tier 1 Campus check of a Campus-scoped
 * Curriculum Delivery administrator, run by CurriculumDeliveryService
 * through the DeliveryWriteGuard hook inside its transaction.
 *
 * - Start: the Offering (and so the Section, bound to it by composite
 *   foreign key) must be on a Campus in scope.
 * - Change: the delivery's STORED Campus must be in scope.
 *
 * Out of scope is NOT FOUND, with no id -- the same answer the service gives
 * an unknown delivery (callers also pre-resolve through the access class).
 * Built only by CurriculumDeliveryAccess, from a
 * CampusScope the resolver computed.
 */
final class CampusDeliveryWriteGuard implements DeliveryWriteGuard
{
    public function __construct(private readonly CampusScope $scope) {}

    public function beforeStart(School $school, Section $section, SubjectOffering $offering, string $startedOn): void
    {
        if (! $this->scope->covers($offering->campus_id) || ! $this->scope->covers($section->campus_id)) {
            throw (new ModelNotFoundException)->setModel(SubjectOffering::class);
        }
    }

    public function beforeChange(School $school, CurriculumDelivery $delivery, array $dates): void
    {
        if (! $this->scope->covers($delivery->campus_id)) {
            throw (new ModelNotFoundException)->setModel(CurriculumDelivery::class);
        }
    }
}
