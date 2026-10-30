<?php

namespace App\Support\StudentReports;

use Illuminate\Validation\Rule;

/**
 * SIS 11.1: the closed vocabulary of Student report filters. A report
 * declares which of these it supports; nothing else from the query string
 * is read. Every filter only NARROWS the rows the viewer may already see,
 * in SQL.
 *
 * - `entity` filters (year, Campus, Grade, Section) accept a UUID only. An
 *   unknown, foreign or out-of-scope id matches nothing; it never errors in
 *   a way that would confirm a record exists.
 * - `choice` filters accept exactly the values the report offers this
 *   viewer (its options()); anything else is a validation error, never a
 *   query error.
 * - `date` filters are School-local calendar dates (Y-m-d, no UTC
 *   conversion); `to` must not be before `from`.
 * - `search` uses the shared StudentSearch matching (number or name words).
 */
final class StudentReportFilter
{
    public const string ENTITY = 'entity';

    public const string CHOICE = 'choice';

    public const string DATE = 'date';

    public const string SEARCH = 'search';

    /** key => [label, kind] -- the key is also the query-string parameter. */
    public const array FILTERS = [
        'q' => ['Student name or number', self::SEARCH],
        'name' => ['Student name', self::SEARCH],
        'student_name' => ['Student name', self::SEARCH],
        'student_number' => ['Student number', self::SEARCH],
        'roll_number' => ['Roll number', self::SEARCH],
        'academic_year_id' => ['Academic year', self::ENTITY],
        'campus_id' => ['Campus', self::ENTITY],
        'grade_level_id' => ['Grade', self::ENTITY],
        'section_id' => ['Section', self::ENTITY],
        'status' => ['Status', self::CHOICE],
        'category' => ['Category', self::CHOICE],
        'type' => ['Type', self::CHOICE],
        'action' => ['Action', self::CHOICE],
        'reason' => ['Reason', self::CHOICE],
        'from' => ['From date', self::DATE],
        'to' => ['To date', self::DATE],
    ];

    public static function exists(string $key): bool
    {
        return isset(self::FILTERS[$key]);
    }

    public static function kind(string $key): string
    {
        return self::FILTERS[$key][1];
    }

    /**
     * Validation rules for exactly the declared filters (plus sort and page).
     *
     * @param  list<string>  $filters
     * @param  array<string, list<array{value: string, label: string}>>  $options  the report's options for this viewer
     * @param  list<string>  $sorts  the declared sort keys
     * @return array<string, mixed>
     */
    public static function rules(array $filters, array $options, array $sorts): array
    {
        $rules = [
            'sort' => ['sometimes', 'nullable', Rule::in($sorts)],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ];
        foreach ($filters as $key) {
            $rules[$key] = match (self::kind($key)) {
                self::SEARCH => ['sometimes', 'nullable', 'string', 'max:255'],
                self::ENTITY => ['sometimes', 'nullable', 'uuid'],
                self::CHOICE => ['sometimes', 'nullable', 'string', Rule::in(array_column($options[$key] ?? [], 'value'))],
                default => $key === 'to' && in_array('from', $filters, true)
                    ? ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from']
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
}
