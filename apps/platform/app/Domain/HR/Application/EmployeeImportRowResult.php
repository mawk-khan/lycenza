<?php

namespace App\Domain\HR\Application;

/**
 * Phase 8A.12 -- the outcome of importing exactly one row. `status` is
 * one of `created`/`duplicate_exact`/`duplicate_potential`/`failed`.
 * `errors` is a small, explicit list of `{field, code, message}` --
 * NEVER the row's own input values (checkpoint brief section 32/46:
 * "errors contain field/error only", never a full-row echo).
 */
final class EmployeeImportRowResult
{
    /**
     * @param  array<int, array{field: ?string, code: string, message: string}>  $errors
     */
    private function __construct(
        public readonly int $rowNumber,
        public readonly string $status,
        public readonly ?string $employeeId,
        public readonly ?string $employeeNumber,
        public readonly array $errors,
    ) {}

    public static function created(int $rowNumber, string $employeeId, string $employeeNumber): self
    {
        return new self($rowNumber, 'created', $employeeId, $employeeNumber, []);
    }

    public static function duplicateExact(int $rowNumber, ?string $employeeId, ?string $employeeNumber): self
    {
        return new self($rowNumber, 'duplicate_exact', $employeeId, $employeeNumber, []);
    }

    public static function duplicatePotential(int $rowNumber): self
    {
        return new self($rowNumber, 'duplicate_potential', null, null, []);
    }

    /**
     * @param  array<int, array{field: ?string, code: string, message: string}>  $errors
     */
    public static function failed(int $rowNumber, array $errors): self
    {
        return new self($rowNumber, 'failed', null, null, $errors);
    }

    /**
     * @return array{row_number: int, status: string, employee_id: ?string, employee_number: ?string, errors: array<int, array{field: ?string, code: string, message: string}>}
     */
    public function toArray(): array
    {
        return [
            'row_number' => $this->rowNumber,
            'status' => $this->status,
            'employee_id' => $this->employeeId,
            'employee_number' => $this->employeeNumber,
            'errors' => $this->errors,
        ];
    }
}
