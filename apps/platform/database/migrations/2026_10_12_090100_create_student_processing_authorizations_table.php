<?php

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 0H.4D-P2 -- Student/SIS Processing Authorization Registry.
     * ADR 0038; see docs/security/STUDENTMARK-CHILDRENS-DATA-DETERMINATION.md
     * for the underlying legal/privacy decision this is architecture
     * for. Students/SIS-owned (per docs/architecture/DOMAIN-MAP.md's
     * established dependency direction: Examinations already depends
     * on Students/SIS, never the reverse) -- a future StudentMark
     * checkpoint consumes this registry through a read service, never
     * becomes its system of record.
     *
     * ONE append-only fact per row: a School's processing-authorization
     * decision for one Student and one purpose, mirroring
     * `communication_domain_consent_events`' exact append-only shape
     * (TenantRls::makeAppendOnly) -- never updated or deleted at the
     * database privilege level. Current authorization state is ALWAYS
     * derived by StudentProcessingAuthorizationReadService (never a
     * stored `is_active`/`current_status` column that could drift).
     *
     * `purpose` is a closed vocabulary (currently exactly
     * `academic_records` -- the only purpose StudentMark needs; not an
     * "all processing" boolean, since Communications consent and
     * Guardian-portal account-linking already separately own their own
     * narrower facts and must never be proxied by this registry).
     *
     * `basis_type` is closed to the three legally-distinguished bases
     * the approved privacy decision names: `guardian_consent` (Student
     * age <18), `adult_student_consent` (Student age >=18, the Student
     * itself is the provider, no platform User account required), and
     * `statutory_school_purpose` (no natural-person provider; the
     * School as Data Fiduciary asserts this basis -- this registry
     * records that assertion, it does not itself adjudicate the law).
     * Never a `consent = true` boolean, which would destroy this
     * distinction.
     *
     * Lifecycle is closed to `recorded|withdrawn|revoked|superseded`.
     * A `recorded` row is a grant; `withdrawn`/`revoked`/`superseded`
     * rows are TERMINAL EVENTS pointing at the grant they end via
     * `terminates_authorization_id` -- never a status flip on the
     * grant row itself. `withdrawn` (the consent provider ended it
     * themselves) and `revoked` (administrative invalidation) are
     * deliberately distinct terminal reasons with the same structural
     * shape, per the approved architecture's explicit requirement that
     * they preserve different semantics.
     *
     * Two structural invariants are database-enforced, never
     * application-check-then-insert:
     * - `student_processing_authorizations_one_termination_per_grant`
     *   (partial unique index on `terminates_authorization_id` WHERE
     *   NOT NULL) -- one recorded grant cannot be terminated twice; a
     *   race between e.g. a withdrawal and a revocation against the
     *   SAME grant leaves exactly one winner, the same
     *   `UniqueConstraintViolationException`-is-the-real-guarantee
     *   discipline CLAUDE.md rule 30 already requires elsewhere.
     * - The self-referencing composite FK on
     *   `(terminates_authorization_id, school_id, student_id, purpose)`
     *   proves a terminal event can only terminate a PRIOR grant for
     *   the exact same School, Student, and purpose -- never another
     *   School's record, another Student's authorization, or a
     *   different purpose's lineage, structurally, not by application
     *   validation alone.
     *
     * `student_guardian_relationship_id` (required exactly when
     * `basis_type = 'guardian_consent'`) is a composite FK against
     * `student_guardian_relationships(id, school_id, student_id)` --
     * proving the referenced relationship belongs to the SAME School
     * AND the SAME Student. Guardian identity is derived from that
     * relationship (never a separately-stored `provider_guardian_id`)
     * so there is no second, potentially-contradictory pointer to keep
     * in sync -- one authoritative reference, not duplicated state.
     * For `adult_student_consent`, `student_id` itself IS the
     * provider (no extra column needed); for `statutory_school_purpose`,
     * no provider column is set at all. A CHECK constraint ties
     * `basis_type` to exactly the columns each basis requires.
     *
     * `recorded_by_user_id` (never nullable) is the staff User who
     * captured the authorization -- the SAME "who did this" precedent
     * `communication_domain_consent_events.recorded_by_user_id` and
     * `student_guardian_account_links.linked_by_user_id` already
     * established; no separate membership/role snapshot is stored,
     * consistent with every other ledger in this codebase.
     *
     * `note` is a short, optional, operator-facing field -- never the
     * legal basis itself, never copied into audit metadata (see
     * StudentProcessingAuthorizationService).
     *
     * Classified Highly Sensitive
     * (docs/security/DATA-CLASSIFICATION.md). No DELETE route exists
     * or ever will for this table; retention is indefinite pending an
     * explicit future retention policy.
     */
    public function up(): void
    {
        Schema::create('student_processing_authorizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained('schools')->cascadeOnDelete();
            $table->uuid('student_id');
            $table->string('purpose');
            $table->string('basis_type');
            $table->string('status');
            $table->uuid('terminates_authorization_id')->nullable();
            $table->uuid('student_guardian_relationship_id')->nullable();
            $table->timestamp('recorded_at');
            $table->foreignUuid('recorded_by_user_id')->constrained('users');
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index(['school_id', 'student_id', 'purpose']);

            // Self-referential parent-context key -- lets the terminal
            // composite FK below prove same School/Student/purpose.
            $table->unique(['id', 'school_id', 'student_id', 'purpose'], 'spa_context_unique');

            $table->foreign(['student_id', 'school_id'], 'spa_student_school_foreign')
                ->references(['id', 'school_id'])->on('students')
                ->restrictOnDelete();

            $table->foreign(
                ['terminates_authorization_id', 'school_id', 'student_id', 'purpose'],
                'spa_terminates_context_foreign'
            )
                ->references(['id', 'school_id', 'student_id', 'purpose'])->on('student_processing_authorizations')
                ->restrictOnDelete();

            $table->foreign(
                ['student_guardian_relationship_id', 'school_id', 'student_id'],
                'spa_guardian_relationship_context_foreign'
            )
                ->references(['id', 'school_id', 'student_id'])->on('student_guardian_relationships')
                ->restrictOnDelete();
        });

        DB::statement(
            'ALTER TABLE student_processing_authorizations ADD CONSTRAINT spa_purpose_check '.
            "CHECK (purpose IN ('academic_records'))"
        );

        DB::statement(
            'ALTER TABLE student_processing_authorizations ADD CONSTRAINT spa_basis_type_check '.
            "CHECK (basis_type IN ('guardian_consent', 'adult_student_consent', 'statutory_school_purpose'))"
        );

        DB::statement(
            'ALTER TABLE student_processing_authorizations ADD CONSTRAINT spa_status_check '.
            "CHECK (status IN ('recorded', 'withdrawn', 'revoked', 'superseded'))"
        );

        DB::statement(
            'ALTER TABLE student_processing_authorizations ADD CONSTRAINT spa_recorded_vs_terminal_check '.
            'CHECK ('.
            "(status = 'recorded' AND terminates_authorization_id IS NULL) OR ".
            "(status IN ('withdrawn', 'revoked', 'superseded') AND terminates_authorization_id IS NOT NULL)".
            ')'
        );

        DB::statement(
            'ALTER TABLE student_processing_authorizations ADD CONSTRAINT spa_basis_shape_check '.
            'CHECK ('.
            "(basis_type = 'guardian_consent' AND student_guardian_relationship_id IS NOT NULL) OR ".
            "(basis_type IN ('adult_student_consent', 'statutory_school_purpose') AND student_guardian_relationship_id IS NULL)".
            ')'
        );

        // Section 13's single-termination invariant: a recorded grant
        // may be pointed at by AT MOST ONE terminal event.
        DB::statement(
            'CREATE UNIQUE INDEX student_processing_authorizations_one_termination_per_grant '.
            'ON student_processing_authorizations (terminates_authorization_id) '.
            'WHERE terminates_authorization_id IS NOT NULL'
        );

        TenantRls::enable('student_processing_authorizations');

        // Deliberately NOT TenantRls::makeAppendOnly() (which REVOKEs
        // UPDATE/DELETE from school_os_app): PostgreSQL requires
        // UPDATE-or-DELETE privilege to acquire a row lock at all
        // (`SELECT ... FOR UPDATE` fails with a bare "permission
        // denied" otherwise) -- and this table's atomic termination
        // claim (StudentProcessingAuthorizationService::terminate())
        // and lock-capable read seam
        // (StudentProcessingAuthorizationReadService::
        // lockQualifyingAuthorizationIdForProcessing()) both genuinely
        // need `lockForUpdate()` to work. Revoking the privilege and
        // needing to lock rows are in direct conflict under Postgres'
        // actual privilege model, discovered empirically while writing
        // this checkpoint's concurrency tests.
        //
        // The fix: privileges stay granted, but a trigger unconditionally
        // rejects any UPDATE/DELETE attempt regardless of privilege --
        // an equivalent (arguably more explicit, since it names the
        // reason rather than surfacing a bare "permission denied")
        // database-enforced immutability guarantee, achieving the same
        // outcome `communication_domain_consent_events`' bare REVOKE
        // achieves for a table that never needs row locking.
        // The DELETE-rejection function deliberately allows deletes at
        // trigger depth > 0 -- i.e. deletes happening as part of an
        // ALREADY-IN-PROGRESS cascade (PostgreSQL implements
        // `ON DELETE CASCADE` via its own internal referential-
        // integrity trigger on the PARENT table, so a cascading delete
        // reaching this table always runs nested inside that trigger).
        // A trigger fires regardless of why a DELETE happened, unlike
        // privilege-based REVOKE (which cascades transparently, per
        // TenantRls::revokeDelete()'s docblock) -- so this table's own
        // `schools.school_id` cascadeOnDelete() would otherwise be
        // silently broken by the very trigger meant to protect it.
        // Only a DIRECTLY-issued DELETE statement (depth 0) is rejected.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION student_processing_authorizations_reject_update()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'student_processing_authorizations is append-only: UPDATE is not permitted';
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION student_processing_authorizations_reject_direct_delete()
            RETURNS trigger AS $$
            BEGIN
                IF pg_trigger_depth() = 1 THEN
                    RAISE EXCEPTION 'student_processing_authorizations is append-only: direct DELETE is not permitted (cascade delete from an owning School remains allowed)';
                END IF;
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER student_processing_authorizations_reject_update
                BEFORE UPDATE ON student_processing_authorizations
                FOR EACH ROW EXECUTE FUNCTION student_processing_authorizations_reject_update();

            CREATE TRIGGER student_processing_authorizations_reject_delete
                BEFORE DELETE ON student_processing_authorizations
                FOR EACH ROW EXECUTE FUNCTION student_processing_authorizations_reject_direct_delete();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS student_processing_authorizations_reject_delete ON student_processing_authorizations;
            DROP TRIGGER IF EXISTS student_processing_authorizations_reject_update ON student_processing_authorizations;
            DROP FUNCTION IF EXISTS student_processing_authorizations_reject_direct_delete();
            DROP FUNCTION IF EXISTS student_processing_authorizations_reject_update();
            SQL);

        TenantRls::disable('student_processing_authorizations');
        Schema::dropIfExists('student_processing_authorizations');
    }
};
