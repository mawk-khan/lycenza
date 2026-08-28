# Phase 1F.1 — Elective Group Domain & Schema Foundation

> Implements ONLY the schema/model/factory layer accepted by
> `docs/students/PHASE-1F-0-ELECTIVE-MUTUAL-EXCLUSIVITY-ARCHITECTURE.md`
> (Phase 1F.0/1F.0A/1F.0B). No `StudentSubjectEnrollmentService`
> behavior change, no configuration service, no routes/controllers/
> capabilities/Vue/OpenAPI. Those land in Phase 1F.2 (enforcement) and
> Phase 1F.3 (configuration), in that order — see §7 below.

## 1. Tables and ownership

**`elective_groups`** (new, AcademicStructure-owned):

```
id                UUID (UUIDv7, ADR 0019)
school_id         UUID  -> schools, cascade
academic_year_id  UUID  -> academic_years, restrict
campus_id         UUID  -> campuses, restrict
grade_level_id    UUID  -> grade_levels, restrict
name              string (display label, NOT unique)
code              string (App\Support\NormalizesCode)
timestamps
```

No `status` column (Phase 1F.0A/1F.0B, reaffirmed — no repository
evidence of an independent group lifecycle). Delete safety comes
entirely from restrict-on-delete FKs on this table's own parents, plus
`subject_offerings.elective_group_id`'s own restrict-on-delete FK.

**`subject_offerings`** gains one nullable column: `elective_group_id`.
NULL means "not a member of any mutual-exclusivity group" — unchanged
for every existing row (no semantic backfill).

**`student_enrollments`** gains one additional composite unique
constraint (no new column) — see §3.

**`student_subject_enrollments`** gains two nullable columns:
`student_enrollment_id` and `elective_group_id` (a denormalized
snapshot) — see §4.

AcademicStructure owns `ElectiveGroup` and the `SubjectOffering` →
`ElectiveGroup` configuration fact. Students/SIS owns
`StudentEnrollment`/`StudentSubjectEnrollment` and enforces Student
elective participation. No reverse AcademicStructure → Students
dependency was introduced.

## 2. Context keys

**Code uniqueness**: `(school_id, academic_year_id, campus_id,
grade_level_id, code)` — a code is unique within one academic context,
never platform-wide or School-wide, following the Subject/GradeLevel
precedent (`App\Support\NormalizesCode`, CLAUDE.md rule 74).

**`elective_groups_context_unique`**: `(id, school_id, academic_year_id,
campus_id, grade_level_id)` — the composite unique consumed by
`subject_offerings`' own composite FK below.

**`subject_offerings_elective_group_context_fk`**: `(elective_group_id,
school_id, academic_year_id, campus_id, grade_level_id)` →
`elective_groups(id, school_id, academic_year_id, campus_id,
grade_level_id)`, restrict-on-delete. Makes a cross-context group
assignment (wrong School/AcademicYear/Campus/GradeLevel) a foreign-key
violation, not merely an application-checked rule. Proven directly —
`ElectiveGroupIntegrityTest` rejects a foreign School, a mismatched
AcademicYear, Campus, and GradeLevel, each independently.

**`subject_offerings_required_group_check`**: `CHECK (elective_group_id
IS NULL OR is_required = false)` — a REQUIRED offering can never carry
a group assignment (architecture doc §9, unchanged from Phase 1F.0). A
same-row invariant; no trigger needed.

## 3. Placement anchor (`student_enrollments`)

New composite unique, purely additive, mirroring the exact precedent
`2026_08_24_090000_add_student_composite_unique_to_student_enrollments_table.php`
already established for `EnrollmentRolloverItem`:

```sql
ALTER TABLE student_enrollments
  ADD CONSTRAINT student_enrollments_id_school_student_year_unique
  UNIQUE (id, school_id, student_id, academic_year_id);
```

Trivially satisfied by every existing row (`id` alone is already
globally unique) — no backfill, no lifecycle change. Exists solely to
support the placement-anchor FK below.

## 4. StudentSubjectEnrollment: placement anchor + group snapshot

```
student_enrollment_id  UUID NULL
elective_group_id      UUID NULL
```

**`student_subject_enrollments_placement_anchor_fk`**:
`(student_enrollment_id, school_id, student_id, academic_year_id)` →
`student_enrollments(id, school_id, student_id, academic_year_id)`,
restrict-on-delete. When `student_enrollment_id` is non-null,
PostgreSQL rejects a row whose claimed `StudentEnrollment` does not
belong to this row's own `student_id`/`academic_year_id`/`school_id` —
closing the cross-Student bypass the architecture doc's §13
counterexample describes (`student_enrollment_id` pointing at a
different Student's enrollment). Proven directly by
`StudentSubjectEnrollmentElectiveGroupIntegrityTest`: wrong Student,
wrong School, and wrong AcademicYear are each independently rejected.

Introduced because `(student_id, academic_year_id)` is NOT equivalent
to one specific `StudentEnrollment` — a Student may be withdrawn and
re-enrolled within the same AcademicYear (architecture doc §0A).

**`student_subject_enrollments_elective_group_fk`**:
`(elective_group_id, school_id)` → `elective_groups(id, school_id)`,
restrict-on-delete. Proves only "this is a real group in this School" —
it does NOT, and cannot under PostgreSQL's default `MATCH SIMPLE`,
prove the snapshot equals the target `SubjectOffering`'s actual group
(a NULL snapshot skips FK checking entirely). That equality is proven
by the trigger in §5, not by this FK.

**`student_subject_enrollments_grouped_requires_anchor_check`**:
`CHECK (elective_group_id IS NULL OR student_enrollment_id IS NOT
NULL)`. A grouped participation can never exist without also recording
which placement it belongs to. This does NOT by itself solve the
snapshot-equality problem — the trigger does — it only closes a
narrower "grouped but anchor-less" orphan state.

## 5. Snapshot-equality trigger (the Phase 1F.0B correction)

```sql
CREATE OR REPLACE FUNCTION assert_student_subject_enrollment_elective_group_snapshot()
RETURNS trigger AS $$
DECLARE
    offering_group_id uuid;
BEGIN
    SELECT elective_group_id INTO offering_group_id
    FROM subject_offerings
    WHERE id = NEW.subject_offering_id AND school_id = NEW.school_id;

    IF NEW.elective_group_id IS DISTINCT FROM offering_group_id THEN
        RAISE EXCEPTION
            'student_subject_enrollments.elective_group_id (%) must match subject_offerings.elective_group_id (%) for subject_offering_id %',
            NEW.elective_group_id, offering_group_id, NEW.subject_offering_id;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_student_subject_enrollments_elective_group_snapshot
BEFORE INSERT OR UPDATE ON student_subject_enrollments
FOR EACH ROW EXECUTE FUNCTION assert_student_subject_enrollment_elective_group_snapshot();
```

Mirrors `assert_membership_role_assignment_scope()`'s exact shape
(`2026_08_22_091000_create_membership_role_assignments_table.php`).
`IS DISTINCT FROM` is NULL-safe: allows grouped→correct-group and
ungrouped→NULL, rejects grouped→NULL (the empirically-proven bypass —
architecture doc §0B), grouped→wrong-group, and ungrouped→any-group.
Resolves the target Offering by BOTH `id` AND `school_id` — never an
unqualified lookup — so the trigger cannot be confused about tenant
identity independent of RLS. Touches no Student PII (only UUIDs in its
error message). Narrow by design: one relational fact only, never a
lifecycle/business decision — the future Phase 1F.2 write path must
still take the `SELECT ... FOR UPDATE` SubjectOffering lock
(architecture doc §16A) before deriving what it inserts; the trigger is
defense-in-depth, not a substitute for that lock.

## 6. Group partial unique index

```sql
CREATE UNIQUE INDEX student_subject_enrollments_one_active_per_elective_group
  ON student_subject_enrollments (student_enrollment_id, elective_group_id)
  WHERE status = 'active' AND elective_group_id IS NOT NULL
```

The DB-enforced race backstop for "different SubjectOfferings, same
ElectiveGroup, same StudentEnrollment" (architecture doc §4). The
pre-existing `student_subject_enrollments_one_active_per_offering`
partial unique (`(student_id, subject_offering_id) WHERE status =
'active'`) is retained unchanged — it protects a different invariant
(duplicate membership in the identical Offering), and its own
pre-existing potential staleness (keyed on `student_id`, not
`student_enrollment_id`) is out of scope for this checkpoint.

## 7. Implementation order (revised, per the 1F.0B architecture pass)

1. **Phase 1F.1 (this checkpoint)** — schema + models + factories +
   integrity tests. No service/controller/route changes.
2. **Phase 1F.2** — `StudentSubjectEnrollmentService::enroll()`/
   `transfer()` updated to lock the target SubjectOffering, derive both
   snapshot columns from the locked row, and translate the new unique
   violation to `ElectiveGroupConflictException`.
3. **Phase 1F.3** — AcademicStructure-owned `ElectiveGroup`
   configuration service (create group, assign/remove Offering
   membership), enforcing the required-offering rule and immutability-
   once-participation-exists rule inside the same SubjectOffering lock.

Enforcement ships before configuration so there is never a window where
a staff-configured group has no enforced effect — `elective_group_id`
starts NULL for every Offering until Phase 1F.3 exists, so Phase 1F.2's
tests set it directly on factory-created fixtures.

## 8. Legacy data / initial adoption policy

No backfill performed anywhere in this migration set:

- Existing `subject_offerings` rows remain ungrouped
  (`elective_group_id IS NULL`).
- Existing `student_subject_enrollments` rows remain
  `student_enrollment_id IS NULL` and `elective_group_id IS NULL` — both
  are nullable precisely for this reason. Deterministic backfill of
  `student_enrollment_id` is not attempted: a Student can have multiple
  sequential `StudentEnrollment` rows for the same AcademicYear
  (withdrawn-then-re-enrolled), so attributing a historical
  `StudentSubjectEnrollment` row to one specific prior
  `StudentEnrollment` can be genuinely ambiguous with no reliable
  disambiguator. No heuristic name/date-based inference was performed.
- Historical offerings that already have participation cannot
  subsequently be grouped through normal Phase 1F.3 configuration
  (initial-adoption policy, unchanged from Phase 1F.0A) — out of scope
  to retrofit here.

Existing Phase 1C behavior is unaffected: `StudentSubjectEnrollmentService::createRow()`
does not populate the two new columns, both remain NULL for every
ungrouped write, and every CHECK/FK/trigger above trivially permits
`NULL`/`NULL`.

## 9. RLS

`elective_groups` uses `App\Support\Tenancy\TenantRls` exactly like
every other Academic Structure table (ENABLE + FORCE, fail-closed with
no context, School A/B isolation) — added to
`AcademicStructureRlsIsolationTest`'s shared table list rather than a
duplicate one-off RLS test file.

## 10. Rollback

`down()` for each migration reverses in dependency order: drop the
partial unique index, drop the trigger, drop the trigger function, drop
the CHECK constraints, drop the composite FKs, drop the new columns,
drop the new `student_enrollments` unique, drop the `subject_offerings`
CHECK/FK/column, disable RLS and drop `elective_groups`. Proven on a
Phase 1F-owned isolated PostgreSQL 16 instance (`docker-compose.phase1f.yml`,
never the shared `school_os_test`/dev database): fresh migrate → roll
back exactly these 5 migrations → re-migrate, all PASS, with no orphan
trigger/function left behind (`pg_proc`/`pg_trigger` queried directly
after rollback).

## 11. Deferred to Phase 1F.2/1F.3

- `StudentSubjectEnrollmentService::enroll()`/`transfer()` populating
  the new columns.
- The `SELECT ... FOR UPDATE` SubjectOffering lock and
  `ElectiveGroupConflictException` translation.
- The AcademicStructure-owned group configuration service and its
  authorization (`academics.subjects.view`/`.manage`, reused).
- Real two-process concurrency tests (same-group concurrent enroll,
  configuration-vs-enroll race, transfer races) — these require the
  canonical service to exist and are explicitly out of scope for a
  schema-only checkpoint (this checkpoint proves the raw DB uniqueness
  invariant only).
- Administrative API/UI, capabilities, OpenAPI.
