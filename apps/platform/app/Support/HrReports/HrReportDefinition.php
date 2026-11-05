<?php

namespace App\Support\HrReports;

/**
 * HR.14: the fixed contract of one HR report. Code-owned and closed: never a
 * table, setting, tenant formula, saved query or request-built SQL.
 *
 * - `authority`: ALL of these are required, for the WHOLE School (HR reports
 *   read history across Campuses and employments). There is no broad
 *   `reports.view`: a report is never broader than the data behind it.
 * - `route`: a RENDERED report is served by the shared page
 *   (`app.hr.reports.show`) from its HrReport read model; any other route is
 *   an existing dedicated HR page this catalogue governs (it keeps its own
 *   filters, columns and authority checks, and the hub links to it only for
 *   a viewer holding `authority`).
 * - `basis`: what the rows mean in time -- CURRENT (today's state), AS_OF
 *   (the state on one chosen date, from effective-dated history) or PERIOD
 *   (events inside a date range).
 * - `classification`: the data class the report reads
 *   (docs/security/DATA-CLASSIFICATION.md); every report here is at most
 *   HR-confidential, never sensitive-personal, pay or statutory data.
 * - `sorts`: a closed key => label map (the first is the default); the read
 *   model maps each key to a fixed ORDER BY with a unique tie-breaker.
 * - `customizable`: the viewer may choose among the columns they may see.
 * - `maxRangeDays`: the longest from..to range accepted (a resource limit,
 *   refused with a validation error -- never a silent truncation).
 */
final readonly class HrReportDefinition
{
    public const string RENDERED = 'app.hr.reports.show';

    public const string CURRENT = 'current';

    public const string AS_OF = 'as_of';

    public const string PERIOD = 'period';

    public const string INTERNAL_DIRECTORY = 'internal_directory';

    public const string EMPLOYMENT_OPERATIONS = 'employment_operations';

    public const string HR_CONFIDENTIAL = 'hr_confidential';

    /** Hub sections, in display order. A category appears only when it holds a report. */
    public const array CATEGORIES = [
        'workforce' => 'Workforce',
        'lifecycle' => 'Employment and lifecycle',
        'hierarchy' => 'Reporting lines',
        'leave' => 'Leave',
        'attendance' => 'Staff attendance',
        'tasks' => 'Staff tasks',
    ];

    public const array BASES = [self::CURRENT => 'Current state', self::AS_OF => 'As of a date', self::PERIOD => 'Events in a period'];

    public const array CLASSIFICATIONS = [
        self::INTERNAL_DIRECTORY => 'Internal staff directory',
        self::EMPLOYMENT_OPERATIONS => 'Employment operations',
        self::HR_CONFIDENTIAL => 'HR confidential',
    ];

    public const int MAX_PER_PAGE = 50;

    /**
     * @param  list<string>  $authority
     * @param  list<string>  $filters  keys of HrReportFilter::FILTERS
     * @param  list<HrReportColumn>  $columns
     * @param  array<string, string>  $sorts  key => label; the first is the default
     * @param  list<string>  $reference  HR reference-map capability ids (docs/hr/HR-REFERENCE-CAPABILITY-MAP.md)
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public string $category,
        public string $owner,
        public array $authority,
        public string $route,
        public string $basis,
        public string $classification,
        public array $reference,
        public array $filters = [],
        public array $columns = [],
        public array $sorts = [],
        public int $perPage = 25,
        public bool $customizable = false,
        public ?int $maxRangeDays = null,
    ) {
        if (preg_match('/^hr\.[a-z_]+$/', $key) !== 1) {
            throw new \InvalidArgumentException("Invalid HR report key {$key}.");
        }
        if (! isset(self::CATEGORIES[$category]) || ! isset(self::BASES[$basis]) || ! isset(self::CLASSIFICATIONS[$classification])) {
            throw new \InvalidArgumentException("{$key}: unknown category, basis or classification.");
        }
        if ($authority === [] || trim($owner) === '' || $reference === []) {
            throw new \InvalidArgumentException("{$key} must name its authority, owner and reference capabilities.");
        }
        foreach ($filters as $filter) {
            if (! HrReportFilter::exists($filter)) {
                throw new \InvalidArgumentException("{$key}: unknown filter {$filter}.");
            }
        }
        if ($this->isRendered()) {
            if ($columns === [] || $sorts === []) {
                throw new \InvalidArgumentException("{$key}: a rendered report declares its columns and sorts.");
            }
            $keys = array_map(fn (HrReportColumn $c) => $c->key, $columns);
            if (count($keys) !== count(array_unique($keys))) {
                throw new \InvalidArgumentException("{$key}: duplicate column.");
            }
            if ($perPage < 1 || $perPage > self::MAX_PER_PAGE) {
                throw new \InvalidArgumentException("{$key}: page size must be 1..".self::MAX_PER_PAGE.'.');
            }
        } elseif ($filters !== [] || $columns !== [] || $sorts !== [] || $customizable) {
            throw new \InvalidArgumentException("{$key}: a governed page keeps its own filters, columns and sorts.");
        }
        if ($maxRangeDays !== null && (! in_array('from', $filters, true) || ! in_array('to', $filters, true) || $maxRangeDays < 1)) {
            throw new \InvalidArgumentException("{$key}: a range limit needs the from/to filters.");
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
            'owner' => $this->owner,
            'basis' => self::BASES[$this->basis],
            'classification' => self::CLASSIFICATIONS[$this->classification],
            'url' => $this->url(),
        ];
    }
}
