<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E21-RH.1 (ADR 0021 amendment 2026-10-04): closes the isolated runtime
 * privilege defect found by the E21-RH retention privilege audit.
 *
 * `payroll_lwf_annual_charges` is posted payroll evidence (the structural
 * once-per-cycle LWF record, E21.3F `payroll_ledger`). The runtime role
 * received SELECT/INSERT/UPDATE/DELETE through the default privileges, with
 * no trigger and no blocking foreign key, so a runtime session could destroy
 * or rewrite it directly -- outside every retention, hold and age control.
 * No application path uses UPDATE or DELETE: Payroll only INSERTs a charge
 * (`StatutoryPayrollCalculationService::chargeLwfCycle()`) and reads one
 * (`alreadyCharged`). Its only sanctioned removal is
 * `retention_expire_payroll_employee_evidence`, which deletes as the
 * function owner and is unaffected.
 *
 * Forward: REVOKE UPDATE, DELETE from `school_os_app` (idempotent). SELECT
 * and INSERT stay. Owner/migration access is untouched.
 *
 * Rollback is deliberately privilege-irreversible (CLAUDE.md rule 10):
 * `down()` does not re-grant UPDATE/DELETE, because that would restore a
 * known, never-used destructive runtime privilege. Rolling back succeeds and
 * leaves the safer state; re-running `up()` is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('REVOKE UPDATE, DELETE ON payroll_lwf_annual_charges FROM school_os_app');
    }

    public function down(): void
    {
        // Deliberately no re-grant: see the class docblock.
    }
};
