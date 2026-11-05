<?php

namespace App\Support\HrReports;

use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * HR.14: who may see which HR report, decided fresh on every request --
 * discovery (the hub), the report page, its filter options and every page of
 * rows all pass through here.
 *
 * - A report is visible only to a viewer holding ALL of its authority for
 *   the whole School. Platform, elevation, Group and Network authority hold
 *   no School capability, so they see nothing (rule 83).
 * - The hub opens only when at least one report is visible, and lists only
 *   those (a hidden report is not named).
 * - An unknown key, or one that is not a RENDERED report, is NOT FOUND.
 *
 * There is no `reports.view` capability, no export and no saved view (owner
 * decision 2026-11-05).
 */
final class HrReportAccess
{
    public function __construct(
        private readonly HrReportRegistry $registry,
        private readonly CampusScopeResolver $scopes,
    ) {}

    public function viewer(User $actor, School $school): HrReportViewer
    {
        return new HrReportViewer($actor, $school, $this->scopes);
    }

    /**
     * @return list<HrReportDefinition>
     *
     * @throws AuthorizationException
     */
    public function authorizeHub(HrReportViewer $viewer): array
    {
        $visible = array_values(array_filter($this->registry->definitions(), fn (HrReportDefinition $d) => $this->mayOpen($viewer, $d)));
        if ($visible === []) {
            $this->authorizeNothing();
        }

        return $visible;
    }

    /**
     * @throws NotFoundHttpException unknown key or not a rendered report
     * @throws AuthorizationException
     */
    public function authorizeReport(HrReportViewer $viewer, string $key): HrReport
    {
        $report = $this->registry->rendered($key) ?? throw new NotFoundHttpException;
        if (! $this->mayOpen($viewer, $report->definition())) {
            $this->authorizeNothing();
        }

        return $report;
    }

    public function mayOpen(HrReportViewer $viewer, HrReportDefinition $definition): bool
    {
        return $viewer->holdsAll($definition->authority);
    }

    /**
     * The columns of $definition this viewer may see, in declared order.
     *
     * @return list<HrReportColumn>
     */
    public function visibleColumns(HrReportViewer $viewer, HrReportDefinition $definition): array
    {
        return array_values(array_filter($definition->columns, fn (HrReportColumn $c) => $viewer->maySee($c)));
    }

    /** @throws AuthorizationException */
    private function authorizeNothing(): never
    {
        throw new AuthorizationException('This action is unauthorized.');
    }
}
