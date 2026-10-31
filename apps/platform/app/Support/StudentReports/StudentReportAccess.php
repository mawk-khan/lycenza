<?php

namespace App\Support\StudentReports;

use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * SIS 11.1: who may see which Student report, decided fresh per request.
 *
 * - A report is visible only to a viewer holding ALL of its authority under
 *   its scope policy: whole School for a WHOLE_SCHOOL report; at least one
 *   Campus for a CAMPUS_AWARE one, whose destination then reads through the
 *   domain's Campus access class (StudentAccess), so opening Reports never
 *   widens what a Campus holder sees.
 * - The hub opens only when at least one report is visible, and lists only
 *   those (a hidden report is not named).
 * - An unknown key, or one that is not a RENDERED report, is NOT FOUND.
 *
 * There is no `reports.view` capability: a report is never broader than the
 * data capabilities behind it.
 */
final class StudentReportAccess
{
    public function __construct(
        private readonly StudentReportRegistry $registry,
        private readonly CampusScopeResolver $scopes,
    ) {}

    public function viewer(User $actor, School $school): StudentReportViewer
    {
        return new StudentReportViewer($actor, $school, $this->scopes);
    }

    /**
     * The reports this viewer may open (refused when there are none).
     *
     * @return list<StudentReportDefinition>
     *
     * @throws AuthorizationException
     */
    public function authorizeHub(StudentReportViewer $viewer): array
    {
        $visible = array_values(array_filter($this->registry->definitions(), fn (StudentReportDefinition $d) => $this->mayOpen($viewer, $d)));
        if ($visible === []) {
            $this->authorizeNothing();
        }

        return $visible;
    }

    /**
     * The RENDERED report behind $key, when this viewer may open it.
     *
     * @throws NotFoundHttpException unknown key or not a rendered report
     * @throws AuthorizationException
     */
    public function authorizeReport(StudentReportViewer $viewer, string $key): StudentReport
    {
        $report = $this->registry->rendered($key) ?? throw new NotFoundHttpException;
        if (! $this->mayOpen($viewer, $report->definition())) {
            $this->authorizeNothing();
        }

        return $report;
    }

    /**
     * SIS 11.4 (D-11): the exporter of $key when this viewer may export it --
     * the report's own authority AND the dataset's export capability, held
     * for the whole School (never Campus-adopted). Viewing never implies
     * exporting.
     *
     * @return array{0: StudentReportDefinition, 1: Export\StudentReportExporter}
     *
     * @throws NotFoundHttpException unknown or not exportable
     * @throws AuthorizationException
     */
    public function authorizeExport(StudentReportViewer $viewer, string $key): array
    {
        $definition = $this->registry->find($key);
        $exporter = $this->registry->exporter($key);
        if ($definition === null || $exporter === null) {
            throw new NotFoundHttpException;
        }
        if (! $this->mayExport($viewer, $key)) {
            $this->authorizeNothing();
        }

        return [$definition, $exporter];
    }

    public function mayExport(StudentReportViewer $viewer, string $key): bool
    {
        $definition = $this->registry->find($key);
        $exporter = $this->registry->exporter($key);

        return $definition !== null && $exporter !== null && $this->mayOpen($viewer, $definition)
            && $viewer->holdsWholeSchool($exporter->exportDefinition()->capability);
    }

    public function mayOpen(StudentReportViewer $viewer, StudentReportDefinition $definition): bool
    {
        foreach ($definition->authority as $capability) {
            $held = $definition->scope === StudentReportDefinition::WHOLE_SCHOOL
                ? $viewer->holdsWholeSchool($capability)
                : $viewer->holdsAnywhere($capability);
            if (! $held) {
                return false;
            }
        }

        return true;
    }

    /** @throws AuthorizationException */
    private function authorizeNothing(): never
    {
        throw new AuthorizationException('This action is unauthorized.');
    }
}
