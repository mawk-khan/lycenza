<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ORG.9D-2 (ADR 0076 §74, PV1-11) -- the fee-due reminder, as narrow as
     * the existing Communications model allows:
     *
     * 1. `fee_reminder` joins the audience_type CHECK (the same additive
     *    widen every audience type used), and a fee reminder is never
     *    Emergency (no approval) nor Required (it respects each Guardian's
     *    email preference and consent) -- database-enforced too.
     * 2. `communication_announcement_fee_reminders` -- the authored Finance
     *    selection (population DEFINITION: balance status, year, the
     *    Charge's stored Campus or whole-School Charges, current Grade or
     *    Section), one per announcement, the
     *    `communication_announcement_academic_cohorts` shape. Never a Student
     *    id or an amount. Same-School composite keys throughout (rule 70).
     *    Append-only for the runtime role: written once with the draft.
     * 3. `communication_fee_reminder_lines` -- what each recipient's email
     *    says, written once at publish: a Student display name and that
     *    Student's outstanding with its currency, and the instant it was
     *    read. Needed because a Communications message has ONE body for
     *    every recipient and a delivery's `destination_snapshot` is a
     *    destination (and expires with telemetry, before its content).
     *    Hung under `communication_recipients` so it is content: kept and
     *    purged with the announcement (E21-D3), cascading through the
     *    recipient. Append-only for the runtime role -- a published
     *    reminder's figures are never rewritten. No Student, Charge or
     *    Payment id; no generic template variables.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE communication_announcements DROP CONSTRAINT communication_announcements_audience_type_check');
        DB::statement(
            'ALTER TABLE communication_announcements ADD CONSTRAINT communication_announcements_audience_type_check '.
            "CHECK (audience_type IN ('individual', 'school_wide', 'staff', 'student', 'guardian', 'guardians_of_students', 'grade', 'section', 'subject_offering', 'fee_reminder'))"
        );
        DB::statement(
            'ALTER TABLE communication_announcements ADD CONSTRAINT communication_announcements_fee_reminder_shape_check '.
            "CHECK (audience_type <> 'fee_reminder' OR (dispatch_mode = 'standard' AND requirement = 'optional'))"
        );

        Schema::create('communication_announcement_fee_reminders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('announcement_id');
            $table->string('balance_status'); // outstanding|overdue
            $table->uuid('academic_year_id')->nullable();
            $table->uuid('campus_id')->nullable();
            $table->boolean('campus_unattributed')->default(false);
            $table->uuid('grade_level_id')->nullable();
            $table->uuid('section_id')->nullable();
            $table->timestamps();

            $table->unique('announcement_id');
            $table->index('school_id');

            $table->foreign(['announcement_id', 'school_id'], 'cafr_announcement_school_foreign')
                ->references(['id', 'school_id'])->on('communication_announcements')->cascadeOnDelete();
            $table->foreign(['academic_year_id', 'school_id'], 'cafr_academic_year_school_foreign')
                ->references(['id', 'school_id'])->on('academic_years')->restrictOnDelete();
            $table->foreign(['campus_id', 'school_id'], 'cafr_campus_school_foreign')
                ->references(['id', 'school_id'])->on('campuses')->restrictOnDelete();
            $table->foreign(['grade_level_id', 'school_id'], 'cafr_grade_level_school_foreign')
                ->references(['id', 'school_id'])->on('grade_levels')->restrictOnDelete();
            $table->foreign(['section_id', 'school_id'], 'cafr_section_school_foreign')
                ->references(['id', 'school_id'])->on('sections')->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE communication_announcement_fee_reminders ADD CONSTRAINT cafr_balance_status_check '.
            "CHECK (balance_status IN ('outstanding', 'overdue'))"
        );
        DB::statement(
            'ALTER TABLE communication_announcement_fee_reminders ADD CONSTRAINT cafr_campus_shape_check '.
            'CHECK (NOT (campus_unattributed AND campus_id IS NOT NULL))'
        );
        DB::statement(
            'ALTER TABLE communication_announcement_fee_reminders ADD CONSTRAINT cafr_one_class_filter_check '.
            'CHECK (num_nonnulls(grade_level_id, section_id) <= 1)'
        );
        TenantRls::enable('communication_announcement_fee_reminders');
        TenantRls::makeAppendOnly('communication_announcement_fee_reminders');

        Schema::create('communication_fee_reminder_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('recipient_id');
            $table->unsignedSmallInteger('position');
            $table->text('student_name');
            $table->decimal('outstanding_amount', 14, 2);
            $table->char('currency', 3);
            $table->timestampTz('as_of');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['recipient_id', 'position']);
            $table->index('school_id');

            $table->foreign(['recipient_id', 'school_id'], 'cfrl_recipient_school_foreign')
                ->references(['id', 'school_id'])->on('communication_recipients')->cascadeOnDelete();
        });

        DB::statement(
            'ALTER TABLE communication_fee_reminder_lines ADD CONSTRAINT cfrl_amount_positive_check '.
            'CHECK (outstanding_amount > 0)'
        );
        TenantRls::enable('communication_fee_reminder_lines');
        TenantRls::makeAppendOnly('communication_fee_reminder_lines');
    }

    /**
     * Reversible only while no announcement is a fee reminder (rule 10): a
     * sent reminder is history and is never rewritten to roll back.
     */
    public function down(): void
    {
        if (DB::table('communication_announcements')->where('audience_type', 'fee_reminder')->exists()) {
            throw new RuntimeException("communication_announcements holds 'fee_reminder' audiences; they are history and are never rewritten to roll back.");
        }

        TenantRls::disable('communication_fee_reminder_lines');
        Schema::dropIfExists('communication_fee_reminder_lines');
        TenantRls::disable('communication_announcement_fee_reminders');
        Schema::dropIfExists('communication_announcement_fee_reminders');

        DB::statement('ALTER TABLE communication_announcements DROP CONSTRAINT communication_announcements_fee_reminder_shape_check');
        DB::statement('ALTER TABLE communication_announcements DROP CONSTRAINT communication_announcements_audience_type_check');
        DB::statement(
            'ALTER TABLE communication_announcements ADD CONSTRAINT communication_announcements_audience_type_check '.
            "CHECK (audience_type IN ('individual', 'school_wide', 'staff', 'student', 'guardian', 'guardians_of_students', 'grade', 'section', 'subject_offering'))"
        );
    }
};
