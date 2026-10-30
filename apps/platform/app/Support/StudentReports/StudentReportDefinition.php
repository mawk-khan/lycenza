<?php

namespace App\Support\StudentReports;

/**
 * SIS 11.1: the fixed contract of one Student report. Code-owned and closed:
 * never a table, setting, tenant formula or request-built query.
 *
 * - `authority`: ALL of these are required -- the same capabilities that
 *   show the data at its source. There is no broad `reports.view`.
 * - `scope`: CAMPUS_AWARE (the destination reads through the domain's
 *   Campus access class, so a Campus holder of every key sees it, cut to
 *   their Campuses) or WHOLE_SCHOOL (history across Campuses and years:
 *   every key must be held for the whole School).
 * - `route`: where the report opens. A RENDERED report is served by the
 *   shared report page (`app.students.reports.show`) from its StudentReport
 *   read model; any other route is an existing dedicated report page that
 *   this catalogue governs (its filters and sorts are pinned to that page).
 * - `sorts`: a closed key => label map (the first is the default); the read
 *   model maps each key to a fixed ORDER BY. Request input only picks a key.
 */
final readonly class StudentReportDefinition
{
    public const string CAMPUS_AWARE = 'campus_aware';

    public const string WHOLE_SCHOOL = 'whole_school';

    public const string RENDERED = 'app.students.reports.show';

    /** Hub sections, in display order. A category appears only when it holds a report. */
    public const array CATEGORIES = [
        'students' => 'Students',
        'enrollment' => 'Enrollment',
        'lifecycle' => 'Lifecycle',
    ];

    public const int MAX_PER_PAGE = 50;

    /**
     * @param  list<string>  $authority
     * @param  list<string>  $filters  keys of StudentReportFilter::FILTERS
     * @param  list<StudentReportColumn>  $columns
     * @param  array<string, string>  $sorts  key => label; the first is the default
     * @param  list<string>  $reference  SIS reference-map families (docs/sis/SIS-REFERENCE-CAPABILITY-MAP.md)
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public string $category,
        public string $owner,
        public array $authority,
        public string $scope,
        public string $route,
        public array $filters,
        public array $columns,
        public array $sorts,
        public int $perPage,
        public array $reference,
    ) {
        if (preg_match('/^[a-z_]+\.[a-z_]+$/', $key) !== 1) {
            throw new \InvalidArgumentException("Invalid Student report key {$key}.");
        }
        if (! isset(self::CATEGORIES[$category]) || ! in_array($scope, [self::CAMPUS_AWARE, self::WHOLE_SCHOOL], true)) {
            throw new \InvalidArgumentException("{$key}: unknown category or scope.");
        }
        if ($authority === [] || $sorts === [] || $columns === [] || trim($owner) === '') {
            throw new \InvalidArgumentException("{$key} must name its authority, owner, columns and sorts.");
        }
        foreach ($filters as $filter) {
            if (! StudentReportFilter::exists($filter)) {
                throw new \InvalidArgumentException("{$key}: unknown filter {$filter}.");
            }
        }
        $keys = array_map(fn (StudentReportColumn $c) => $c->key, $columns);
        if (count($keys) !== count(array_unique($keys))) {
            throw new \InvalidArgumentException("{$key}: duplicate column.");
        }
        if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new \InvalidArgumentException("{$key}: page size must be 1..".self::MAX_PER_PAGE.'.');
        }
    }

    public function isRendered(): bool
    {
        return $this->route === self::RENDERED;
    }

    public function defaultSort(): string
    {
        return (string) array_key_first($this->sorts);
    }

    public function url(): string
    {
        return $this->isRendered()
            ? route(self::RENDERED, ['report' => $this->key], absolute: false)
            : route($this->route, absolute: false);
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'url' => $this->url(),
        ];
    }
}
