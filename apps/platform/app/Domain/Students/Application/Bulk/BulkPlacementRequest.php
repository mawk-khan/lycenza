<?php

namespace App\Domain\Students\Application\Bulk;

/**
 * SIS 11.13: one reviewed bulk move. Each row names the Student, the
 * placement the operator SAW (the stale-state binding: execution refuses
 * the whole batch unless that placement is still the Student's current one
 * in the source Section) and the Student's roll number in the target
 * Section (required by the placement contract; never allocated here).
 *
 * Rows are kept in Student-id order: the lock order and the signature both
 * use it.
 */
final readonly class BulkPlacementRequest
{
    /** @var list<array{studentId: string, enrollmentId: string, rollNumber: string}> */
    public array $rows;

    /** @param  list<array{studentId: string, enrollmentId: string, rollNumber: string}>  $rows */
    public function __construct(
        public BulkPlacementOperation $operation,
        public string $sourceSectionId,
        public string $targetSectionId,
        public string $effectiveDate,
        array $rows,
    ) {
        usort($rows, fn (array $a, array $b) => strcmp($a['studentId'], $b['studentId']));
        $this->rows = array_map(fn (array $r) => [
            'studentId' => $r['studentId'],
            'enrollmentId' => $r['enrollmentId'],
            'rollNumber' => $r['rollNumber'],
        ], $rows);
    }

    /** @return list<string> */
    public function studentIds(): array
    {
        return array_column($this->rows, 'studentId');
    }

    /**
     * The inverse of canonical() (a selection kept between the preview and
     * its review page); null when the stored shape is not one this class wrote.
     */
    public static function fromCanonical(mixed $data): ?self
    {
        if (! is_array($data) || ! is_string($data['source'] ?? null) || ! is_string($data['target'] ?? null)
            || ! is_string($data['effectiveDate'] ?? null) || ! is_array($data['rows'] ?? null)) {
            return null;
        }
        $operation = BulkPlacementOperation::tryFrom((string) ($data['operation'] ?? ''));
        $rows = [];
        foreach ($data['rows'] as $r) {
            if (! is_array($r) || ! is_string($r['studentId'] ?? null) || ! is_string($r['enrollmentId'] ?? null) || ! is_string($r['rollNumber'] ?? null)) {
                return null;
            }
            $rows[] = ['studentId' => $r['studentId'], 'enrollmentId' => $r['enrollmentId'], 'rollNumber' => $r['rollNumber']];
        }

        return $operation === null ? null : new self($operation, $data['source'], $data['target'], $data['effectiveDate'], $rows);
    }

    /** @return array<string, mixed> the canonical content the preview signature binds */
    public function canonical(): array
    {
        return [
            'operation' => $this->operation->value,
            'source' => $this->sourceSectionId,
            'target' => $this->targetSectionId,
            'effectiveDate' => $this->effectiveDate,
            'rows' => $this->rows,
        ];
    }
}
