<?php

namespace App\Support\StudentReports\Export;

/**
 * SIS 11.4 (D-11): the governed export contract of one report.
 *
 * - `capability`: the per-dataset export authority, held for the WHOLE
 *   School (never Campus-adopted). It is required IN ADDITION to the
 *   report's own authority -- viewing never implies exporting. Rows always
 *   follow the viewer's resolved view scope, so a viewer whose view is
 *   Campus-scoped exports only their Campuses.
 * - `columns`: the only columns that can leave the system (defaults first).
 * - `filenameBase`: a fixed slug; the filename never carries request input.
 * - Formats: CSV download and the print view (FORMATS). No XLSX or PDF in
 *   11.4 (no approved writer exists).
 */
final readonly class StudentReportExportDefinition
{
    public const string CSV = 'csv';

    public const string PRINT = 'print';

    public const array FORMATS = [self::CSV, self::PRINT];

    /** Synchronous, streamed CSV ceiling: a larger result asks to narrow the filters (no background job in 11.4). */
    public const int MAX_ROWS = 10000;

    /** The print view's ceiling: a printed list longer than this is not a useful page. */
    public const int MAX_PRINT_ROWS = 1000;

    /** @param  list<StudentReportExportColumn>  $columns */
    public function __construct(
        public string $capability,
        public array $columns,
        public string $filenameBase,
    ) {
        if (preg_match('/^[a-z]+\.export$/', $capability) !== 1) {
            throw new \InvalidArgumentException("{$capability} is not a per-dataset export capability.");
        }
        if (preg_match('/^[a-z][a-z0-9-]*$/', $filenameBase) !== 1) {
            throw new \InvalidArgumentException("Unsafe export filename base {$filenameBase}.");
        }
        $keys = array_map(fn (StudentReportExportColumn $c) => $c->key, $columns);
        if ($keys === [] || count($keys) !== count(array_unique($keys))) {
            throw new \InvalidArgumentException('An export declares unique columns.');
        }
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(fn (StudentReportExportColumn $c) => $c->key, $this->columns);
    }
}
