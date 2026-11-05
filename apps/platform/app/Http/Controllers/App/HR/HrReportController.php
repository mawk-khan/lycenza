<?php

namespace App\Http\Controllers\App\HR;

use App\Http\Controllers\Controller;
use App\Support\HrReports\HrReportAccess;
use App\Support\HrReports\HrReportColumn;
use App\Support\HrReports\HrReportDefinition;
use App\Support\HrReports\HrReportFilter;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR.14 (owner decisions 2026-11-05): the HR Reports hub and the shared HR
 * report page -- read only and browser-only. Authority is HrReportAccess
 * (per report, from the closed HrReportRegistry, re-checked on every
 * request); the controller only validates the declared filters and chosen
 * columns and renders. No export, print, saved view, write or read audit.
 */
class HrReportController extends Controller
{
    public function index(TenantContext $context, HrReportAccess $access): Response
    {
        $school = $context->requireSchool();
        $visible = $access->authorizeHub($access->viewer($context->actor(), $school));

        $categories = [];
        foreach (HrReportDefinition::CATEGORIES as $key => $label) {
            $reports = array_values(array_filter($visible, fn (HrReportDefinition $d) => $d->category === $key));
            if ($reports !== []) {
                $categories[] = ['key' => $key, 'label' => $label, 'reports' => array_map(fn (HrReportDefinition $d) => $d->summary(), $reports)];
            }
        }

        return Inertia::render('App/HR/Reports/Index', ['categories' => $categories]);
    }

    public function show(Request $request, TenantContext $context, HrReportAccess $access, string $report): Response
    {
        $school = $context->requireSchool();
        $viewer = $access->viewer($context->actor(), $school);
        $read = $access->authorizeReport($viewer, $report);
        $definition = $read->definition();
        $visible = $access->visibleColumns($viewer, $definition);
        $visibleKeys = array_map(fn (HrReportColumn $c) => $c->key, $visible);

        $options = $read->options($school, $viewer);
        $defaults = $read->defaults($school, $options);
        $rules = HrReportFilter::rules($definition->filters, $options, array_keys($definition->sorts), $definition->customizable ? $visibleKeys : null);

        // HR.16F: a refused filter, sort, column or range is SHOWN on this page
        // (never a redirect to whatever page came before): the offending
        // parameters are dropped, the report renders with its defaults, and
        // each message is passed as `errors`. Never more than a few passes.
        $input = $request->query();
        $errors = [];
        for ($attempt = 0; ; $attempt++) {
            $validator = Validator::make($input, $rules);
            if ($validator->fails() && $attempt < 3) {
                $input = $this->without($input, $validator->errors()->toArray(), $errors);

                continue;
            }
            $validated = $validator->fails() ? [] : $validator->validated();
            $filters = [];
            foreach ($definition->filters as $key) {
                $value = isset($validated[$key]) ? (string) $validated[$key] : '';
                $filters[$key] = $value === '' ? ($defaults[$key] ?? '') : $value;
            }
            $sort = $validated['sort'] ?? $definition->defaultSort();
            $chosen = $definition->customizable && isset($validated['columns'])
                ? array_values(array_intersect($visibleKeys, $validated['columns']))
                : array_values(array_map(fn (HrReportColumn $c) => $c->key, array_filter($visible, fn (HrReportColumn $c) => ! $definition->customizable || $c->default)));
            try {
                $this->assertRange($definition, $filters);
                $rows = $read->page($school, $viewer, $filters, $sort, (int) ($validated['page'] ?? 1), $chosen)->withQueryString();

                break;
            } catch (ValidationException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }
                $range = array_intersect(array_keys($e->errors()), ['from', 'to']) !== [] ? ['from' => [], 'to' => []] : [];
                $input = $this->without($input, [...$range, ...$e->errors()], $errors);
            }
        }
        $allowed = array_flip([...$chosen, 'key']);
        // Defence in depth: nothing outside the chosen, visible columns leaves the server.
        $rows->setCollection($rows->getCollection()->map(fn (array $row) => array_intersect_key($row, $allowed)));
        $filtered = array_filter(array_diff_assoc($filters, $defaults), fn (string $v) => $v !== '') !== [];

        return Inertia::render('App/HR/Reports/Show', [
            'report' => $definition->summary(),
            'filters' => HrReportFilter::present($definition->filters, $options),
            'values' => [...$filters, 'sort' => $sort === $definition->defaultSort() ? '' : $sort],
            'defaults' => $defaults,
            'columns' => array_values(array_map(fn (HrReportColumn $c) => $c->toArray(), array_filter($visible, fn (HrReportColumn $c) => in_array($c->key, $chosen, true)))),
            'selectable' => $definition->customizable ? array_map(fn (HrReportColumn $c) => $c->toArray(), $visible) : [],
            'chosen' => $definition->customizable ? $chosen : [],
            'sorts' => array_map(fn (string $k, string $l) => ['value' => $k, 'label' => $l], array_keys($definition->sorts), $definition->sorts),
            'rows' => $rows,
            'hasAny' => $filtered ? $read->hasAny($school, $viewer) : $rows->total() > 0,
            'errors' => $errors,
            'provenance' => [
                'source' => $definition->owner,
                'basis' => HrReportDefinition::BASES[$definition->basis],
                'timezone' => $school->timezone,
                'generatedAt' => CarbonImmutable::now(SchoolTimezone::resolve($school))->toIso8601String(),
                'freshness' => 'Live: read from the source records when the page loaded.',
            ],
        ]);
    }

    /** @param  array<string, string>  $filters */
    private function assertRange(HrReportDefinition $definition, array $filters): void
    {
        if ($definition->maxRangeDays === null || ($filters['from'] ?? '') === '' || ($filters['to'] ?? '') === '') {
            return;
        }
        $days = CarbonImmutable::parse($filters['from'])->diffInDays(CarbonImmutable::parse($filters['to'])) + 1;
        if ($filters['to'] < $filters['from']) {
            throw ValidationException::withMessages(['to' => 'The end date cannot be before the start date.']);
        }
        if ($days > $definition->maxRangeDays) {
            throw ValidationException::withMessages(['to' => "Choose a range of at most {$definition->maxRangeDays} days."]);
        }
    }

    /**
     * $input without every parameter named in $failures (by root key), and
     * each failure's first message added to $errors under its root key.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, list<string>>  $failures
     * @param  array<string, string>  $errors
     * @return array<string, mixed>
     */
    private function without(array $input, array $failures, array &$errors): array
    {
        foreach ($failures as $key => $messages) {
            $root = explode('.', (string) $key)[0];
            if ($messages !== []) {
                $errors[$root] ??= (string) $messages[0];
            }
            unset($input[$root]);
        }

        return $input;
    }
}
