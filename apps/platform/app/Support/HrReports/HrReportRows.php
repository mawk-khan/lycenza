<?php

namespace App\Support\HrReports;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * HR.14: maps a page of source rows to report rows, keeping the paginator's
 * total, page and links (one typed helper for every HR read model).
 */
final class HrReportRows
{
    /**
     * @template TSource
     *
     * @param  LengthAwarePaginator<array-key, TSource>  $page
     * @param  callable(TSource): array<string, mixed>  $map
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public static function map(LengthAwarePaginator $page, callable $map): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, array<string, mixed>> $mapped */
        $mapped = $page->through($map);

        return $mapped;
    }
}
