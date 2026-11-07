<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * HR.R1-C11 (C10-N1): `is_paid`, `tracks_balance` and `allows_half_day` froze once a
 * policy or ledger row existed. An untracked leave type has neither, so approved
 * leave could be reclassified after the fact, and `LeaveCoverageReader` reads
 * `is_paid` live for Payroll's absence evidence. A request is use too. `is_paid` here
 * is a leave classification, not a payment state.
 *
 * This function has one definition, the 2026_11_17 foundation migration, and no later
 * migration patches it, so down() restores that definition exactly. Submitting leave
 * holds the type FOR SHARE, so a type update and a submission are ordered.
 * Reversible; no row changes.
 */
return new class extends Migration
{
    private function definition(string $inUse): string
    {
        return <<<SQL
            CREATE OR REPLACE FUNCTION leave_types_freeze() RETURNS trigger LANGUAGE plpgsql AS \$\$
            BEGIN
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.school_id IS DISTINCT FROM OLD.school_id OR NEW.code IS DISTINCT FROM OLD.code THEN
                    RAISE EXCEPTION 'leave_types: identity and code never change';
                END IF;
                IF (NEW.is_paid IS DISTINCT FROM OLD.is_paid OR NEW.tracks_balance IS DISTINCT FROM OLD.tracks_balance OR NEW.allows_half_day IS DISTINCT FROM OLD.allows_half_day)
                   AND ({$inUse}) THEN
                    RAISE EXCEPTION 'leave_types: a type in use keeps its paid, balance and half-day rules (leave_type_in_use)';
                END IF;
                RETURN NEW;
            END;
            \$\$;
            SQL;
    }

    private const POLICY_OR_LEDGER = 'EXISTS (SELECT 1 FROM leave_policies WHERE school_id = OLD.school_id AND leave_type_id = OLD.id)
            OR EXISTS (SELECT 1 FROM leave_ledger_entries WHERE school_id = OLD.school_id AND leave_type_id = OLD.id)';

    public function up(): void
    {
        DB::unprepared($this->definition(self::POLICY_OR_LEDGER.'
            OR EXISTS (SELECT 1 FROM leave_requests WHERE school_id = OLD.school_id AND leave_type_id = OLD.id)'));
    }

    public function down(): void
    {
        DB::unprepared($this->definition(self::POLICY_OR_LEDGER));
    }
};
