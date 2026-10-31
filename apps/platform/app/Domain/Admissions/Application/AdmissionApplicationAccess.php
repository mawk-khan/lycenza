<?php

namespace App\Domain\Admissions\Application;

use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Models\Campus;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * ORG.7D-3 (ADR 0076 §11.7, §39): the ONE place PRE-CONVERSION Admissions work
 * reads or targets applications under the actor's CampusScope
 * (`admissions.view`/`.manage` are Campus-adopted for the application list,
 * detail and lifecycle only).
 *
 * - Authority source: the application's Campus (`admission_applications.
 *   campus_id` NOT NULL; no code path changes it). An Applicant has no
 *   Campus and is reached by a Campus-scoped actor only through an
 *   in-scope application -- never through the Applicant directory or page,
 *   which would list applications on other Campuses.
 * - Out of scope is NOT FOUND (404), like another School's application.
 * - Whole-School only (declared CampusScopeCatalogue::WHOLE_SCHOOL_OPERATIONS):
 *   the Applicant directory/page/create, starting an application (it needs
 *   the Applicant page), both Guardian searches, conversion, and the fee
 *   head / fee provenance.
 */
final class AdmissionApplicationAccess
{
    public function __construct(private readonly AdmissionApplicationReadService $reads) {}

    /** @param  array<string, mixed>  $filters */
    public function directory(CampusScope $scope, array $filters, int $perPage, ?\DateTimeZone $timezone = null): LengthAwarePaginator
    {
        return $this->reads->directory($filters, $perPage, $scope, $timezone);
    }

    /**
     * SIS 11.4: the same list query, unpaginated, for the governed export.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<AdmissionApplication>
     */
    public function directoryQuery(CampusScope $scope, array $filters, ?\DateTimeZone $timezone = null): Builder
    {
        return $this->reads->directoryQuery($filters, $scope, $timezone);
    }

    public function detail(CampusScope $scope, string $id): AdmissionApplication
    {
        $found = Str::isUuid($id) ? $this->reads->detail($id, $scope) : null;

        return $found ?? throw (new ModelNotFoundException)->setModel(AdmissionApplication::class, [$id]);
    }

    /** One application to change, within the scope. */
    public function application(CampusScope $scope, string $id): AdmissionApplication
    {
        $found = Str::isUuid($id) ? $scope->restrict(AdmissionApplication::query())->find($id) : null;

        return $found ?? throw (new ModelNotFoundException)->setModel(AdmissionApplication::class, [$id]);
    }

    /** @return list<array{id: string, name: string}> the Campus filter's choices: the scope's Campuses only */
    /**
     * The Campuses within the scope, by name. SIS 3.2: a directory filter
     * lists every Campus (history stays findable), an inactive one labelled;
     * a form choosing NEW context (`$activeOnly`) lists only active Campuses,
     * plus the application's current Campus (`$keep`) so a correction form
     * still shows what is recorded -- ActiveCampus refuses moving onto an
     * inactive Campus regardless.
     *
     * @return list<array{id: string, name: string}>
     */
    public function campusOptions(CampusScope $scope, bool $activeOnly = false, ?string $keep = null): array
    {
        return $scope->restrict(Campus::query(), 'id')
            ->when($activeOnly, fn ($q) => $q->where(fn ($w) => $w->where('status', 'active')->when($keep !== null, fn ($k) => $k->orWhere('id', $keep))))
            ->orderBy('name')->get(['id', 'name', 'status'])
            ->map(fn (Campus $c): array => ['id' => (string) $c->id, 'name' => $c->status === 'active' ? $c->name : "{$c->name} (inactive)"])->values()->all();
    }
}
