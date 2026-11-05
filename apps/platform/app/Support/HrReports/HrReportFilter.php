<?php

namespace App\Support\HrReports;

use Illuminate\Validation\Rule;

/**
 * HR.14: the closed vocabulary of HR report filters. A report declares which
 * of these it supports; nothing else from the query string is read. Every
 * filter only NARROWS the rows, in SQL.
 *
 * - `entity` filters accept a UUID only. An unknown or another School's id
 *   matches nothing; it never errors in a way that confirms a record exists.
 * - `choice` filters accept exactly the values the report offers (its
 *   options(), sourced from the owning module); anything else is a
 *   validation error, never a query error.
 * - `date` filters are School-local calendar dates (Y-m-d); `month` is Y-m.
 *   An end (DATE_RANGES) is never before its start.
 * - `search` matches a staff number or name words, bound as parameters with
 *   LIKE wildcards escaped.
 */
final class HrReportFilter
{
    public const string ENTITY = 'entity';

    public const string CHOICE = 'choice';

    public const string DATE = 'date';

    public const string MONTH = 'month';

    public const string SEARCH = 'search';

    /** key => [label, kind] -- the key is also the query-string parameter. */
    public const array FILTERS = [
        'q' => ['Name or staff number', self::SEARCH],
        'as_of' => ['As of', self::DATE],
        'from' => ['From', self::DATE],
        'to' => ['To', self::DATE],
        'month' => ['Month', self::MONTH],
        'staff_type' => ['Staff type', self::CHOICE],
        'category_id' => ['Employee category', self::ENTITY],
        'department_id' => ['Department', self::ENTITY],
        'position_id' => ['Designation', self::ENTITY],
        'campus_id' => ['Campus', self::ENTITY],
        'line' => ['Reporting line', self::CHOICE],
        'movement' => ['Movement', self::CHOICE],
        'leave_year_id' => ['Leave year', self::CHOICE],
        'leave_type_id' => ['Leave type', self::CHOICE],
        'reason' => ['Reason', self::CHOICE],
        'corrected_by' => ['Corrected by', self::CHOICE],
        'status' => ['Status', self::CHOICE],
        'task_category_id' => ['Task category', self::CHOICE],
        'frequency' => ['Recurrence', self::CHOICE],
    ];

    /** End date => start date: an end is never before its start. */
    public const array DATE_RANGES = ['to' => 'from'];

    public static function exists(string $key): bool
    {
        return isset(self::FILTERS[$key]);
    }

    public static function kind(string $key): string
    {
        return self::FILTERS[$key][1];
    }

    /**
     * Validation rules for exactly the declared filters (plus sort, page and,
     * on a customizable report, the chosen columns).
     *
     * @param  list<string>  $filters
     * @param  array<string, list<array<string, string>>>  $options
     * @param  list<string>  $sorts
     * @param  list<string>|null  $selectable  the column keys this viewer may choose (null: not customizable)
     * @return array<string, mixed>
     */
    public static function rules(array $filters, array $options, array $sorts, ?array $selectable): array
    {
        $rules = [
            'sort' => ['sometimes', 'nullable', Rule::in($sorts)],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ];
        if ($selectable !== null) {
            $rules['columns'] = ['sometimes', 'array', 'min:1', 'max:'.max(1, count($selectable))];
            $rules['columns.*'] = ['string', 'distinct', Rule::in($selectable)];
        }
        foreach ($filters as $key) {
            $rules[$key] = match (self::kind($key)) {
                self::SEARCH => ['sometimes', 'nullable', 'string', 'max:100'],
                self::ENTITY => ['sometimes', 'nullable', 'uuid'],
                self::CHOICE => ['sometimes', 'nullable', 'string', Rule::in(array_column($options[$key] ?? [], 'value'))],
                self::MONTH => ['sometimes', 'nullable', 'date_format:Y-m'],
                default => isset(self::DATE_RANGES[$key]) && in_array(self::DATE_RANGES[$key], $filters, true)
                    ? ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.self::DATE_RANGES[$key]]
                    : ['sometimes', 'nullable', 'date_format:Y-m-d'],
            };
        }

        return $rules;
    }

    /**
     * @param  list<string>  $filters
     * @param  array<string, list<array<string, string>>>  $options
     * @return list<array<string, mixed>>
     */
    public static function present(array $filters, array $options): array
    {
        return array_map(fn (string $key) => [
            'key' => $key,
            'label' => self::FILTERS[$key][0],
            'kind' => self::kind($key),
            'options' => $options[$key] ?? [],
        ], $filters);
    }

    /** A LIKE pattern for literal text (wildcards escaped). */
    public static function like(string $term): string
    {
        return '%'.addcslashes($term, '\\%_').'%';
    }
}
