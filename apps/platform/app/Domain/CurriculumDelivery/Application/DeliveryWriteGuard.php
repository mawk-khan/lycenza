<?php

namespace App\Domain\CurriculumDelivery\Application;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Models\School;

/**
 * TCH.3: an extra authorization step CurriculumDeliveryService runs INSIDE
 * its own write transaction, before it inserts or row-locks a delivery.
 * The Tier 1 (School-wide `curriculum.delivery.manage`) path passes none;
 * the Tier 2 owned-teacher path passes TeacherDeliveryGuard. The business
 * rules, state machine and compare-and-swap stay the service's own --
 * there is no second implementation of them.
 */
interface DeliveryWriteGuard
{
    /** Before a delivery is started: the context and its started_on. */
    public function beforeStart(School $school, Section $section, SubjectOffering $offering, string $startedOn): void;

    /**
     * Before an existing delivery changes.
     *
     * @param  list<string>  $dates  every School-local date the change writes or replaces
     */
    public function beforeChange(School $school, CurriculumDelivery $delivery, array $dates): void;
}
