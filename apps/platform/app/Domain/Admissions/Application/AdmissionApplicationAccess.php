<?php

namespace App\Domain\Admissions\Application;

use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Models\Campus;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
    public function directory(CampusScope $scope, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->reads->directory($filters, $perPage, $scope);
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
    public function campusOptions(CampusScope $scope): array
    {
        return $scope->restrict(Campus::query(), 'id')->orderBy('name')->get(['id', 'name'])
            ->map(fn (Campus $c): array => ['id' => $c->id, 'name' => $c->name])->values()->all();
    }
}
