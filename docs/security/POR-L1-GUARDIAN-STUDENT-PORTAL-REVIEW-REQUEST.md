# POR-L1 — Guardian- and Student-facing portal access to Student information: Review Request

**Status at drafting (2026-10-08): DRAFT REQUEST — NOT SENT, NOT ANSWERED.**
This document asks a question. It records no approval, and nothing in it may
be read as one.

- **To:** Lead Privacy Counsel & Data Protection Officer — India Operations
- **From:** the product owner, Lycenza School OS
- **Subject:** Guardian- and Student-facing portal access to Student
  information (programme POR)
- **Register item:** ADR 0058 row **E46 (POR-L1)**. It is distinct from:
  - E42 (RES-L7: Student/Guardian access to marks and results);
  - E39–E41 (results, report cards, transcripts);
  - E35–E37 (StudentMark);
  - E28 (online payments);
  - E21 (retention periods).
- **Engineering contract:**
  `docs/architecture/adr/0070-guardian-student-portal-contract.md` (POR.0,
  documentation only; nothing is built).
- **Prior determinations:**
  - `STUDENTMARK-CHILDRENS-DATA-DETERMINATION.md`: Guardian- and
    Student-facing surfaces "each … requires its own future determination
    before implementation begins" (marks);
  - `RES-L0-STUDENTMARK-REVALIDATION-DETERMINATION.md`: excludes Student and
    Guardian access;
  - `TCH-L1-TEACHER-ATTENDANCE-DETERMINATION.md`: teacher Attendance only;
    silent on Guardians and Students.

## Request text

Dear Lead Privacy Counsel,

We ask for a determination on the following processing, **separately for
each surface**:

> May the School OS let authenticated Guardians read, in their own School
> only, specific information the School holds about the Students they are
> recorded as being responsible for, as described below? On what conditions?
> Separately: may Students ever have their own accounts, and on what terms?

### 1. The processing we propose (narrow)
1. **Who:**
   - an adult whose Guardian record the School created;
   - who accepted a School-issued, one-time email invitation;
   - who signs in with their own password (optionally MFA);
   - whose account the School links to that Guardian record.

   The link proves identity only. Access to any Student is decided
   separately from the School's recorded Guardian↔Student relationships.
2. **Which Students:** by default only Students for whom the School has
   recorded this Guardian as a **legal guardian**, while the Student is
   active at the School. This default is our engineering safeguard, not a
   legal conclusion; please confirm, widen or narrow it.
3. **What, per surface:**
   - (a) the Guardian's own in-app messages and announcements;
   - (b) a linked Student's attendance status by date (present, absent,
     late, excused; no reasons are recorded);
   - (c) a linked Student's fee statement;
   - (d) receipts for payments covering that Student, never another
     Student's details;
   - later, separately: (e) replying in a School conversation.
4. **How:**
   - read-only (except replies in (e), and marking one's own message read);
   - web only, signed in;
   - MFA required for (b)–(d);
   - one School at a time;
   - no search across Students;
   - an unknown or unauthorised Student is indistinguishable from a
     non-existent one.
5. **Audit:** each child-specific read is recorded with the Guardian, School,
   Student identifier and surface — never the content.
6. **Not included:**
   - marks, results, report cards, transcripts (E42/E39–E41);
   - Student accounts;
   - payments;
   - exports and PDFs;
   - mobile apps and API access;
   - views across Schools;
   - any Guardian seeing another Guardian's activity.
7. **Production:** refused in code until your determination is recorded and
   its production conditions are met.

### 2. Questions
Please answer each. For each, say whether it affects **design**,
**development** or **production**.

**Surfaces**
1. **Communications:** may Guardians read their own in-app messages,
   announcements and attachments?
2. **Attendance:** may Guardians see a linked Student's attendance history?
   Over what period? In particular, may a Guardian see Attendance dated
   **before** their relationship was recorded, for example after a mid-year
   change of custody or guardianship? (The development build shows the
   current academic year, at most 62 days at a time, whatever the
   relationship's start; ADR 0070 §25.4.) *(Added 2026-10-09 by POR.2;
   still a draft, not sent.)*
3. **Fee statements:** may Guardians see a linked Student's fee statement,
   including concessions or waivers?
4. **Receipts:** may Guardians see receipts? How must a payment that covers
   several Students (siblings) be shown?
5. **Replies and conversations:** may Guardians reply to or start
   conversations? Does creating content need anything beyond reading?

**Who qualifies**
6. **Legal guardians only?** Is access limited to recorded legal guardians?
7. **Non-legal-guardian parents:** may a parent who is not a recorded legal
   guardian (or a primary contact who is not one) receive access? To which
   surfaces?
8. **Separated or divorced parents:** what applies when parents are
   separated or divorced?
9. **Court orders and restricted contact:** how must court orders,
   restricted-contact or no-contact cases be handled? Must access be
   blockable per Student, independently of the relationship record?
10. **School responsibility:** what must the School record or verify for
    restrictions? Who is accountable for keeping it current?
11. **Several Guardians:** for one Student, do all qualifying Guardians get
    equal access?
12. **Visibility between Guardians:** may Guardians see each other's portal
    activity? Must they be prevented?

**Ending access**
13. **Revocation:** when a relationship is ended or revoked, must access end
    immediately?
14. **Withdrawal or transfer:** when a Student withdraws or transfers, must
    access end?
15. **History after access ends:** after access ends, may a Guardian still
    see historical records? For how long?

**Age and Student accounts**
16. **Age 18:** at the Student's majority, must Guardian access end,
    continue, or need the adult Student's consent?
17. **Adult Students:** what control must an adult Student have over
    Guardian access?
18. **Older minors:** are there autonomy considerations (e.g. 16–17) that
    affect Guardian access?
19. **Student accounts:** are they permitted at all? From what age?
20. **Student capabilities:** what age-appropriate capability sets apply?
    (Our authorization documentation marks this as a legal-review design
    question.)
21. **Guardian visibility of Student activity:** may Guardians see what a
    Student does in their own account?

**Security**
22. **Guardian MFA:** is it required? For which surfaces?
23. **Different assurance per surface:** may different surfaces require
    different MFA assurance (e.g. none for the message inbox, required for
    attendance and fees)?

**Basis and obligations**
24. **Legal basis:** what is the legal basis? Is the School's recorded
    Guardian status enough, or is a separate consent needed?
25. **DPDP Act 2023:** which children's-data obligations apply (including
    verifiable parental consent, tracking, behavioural monitoring and
    targeted-advertising restrictions)?
26. **Notice:** what notice must Guardians (and Students) receive, and when?
27. **Rights and grievances:** how do Guardian data-principal rights and the
    grievance route apply to portal processing?

**Audit and retention**
28. **Audit:** which reads must be audited? Is the proposed envelope (§1.5)
    sufficient, excessive or insufficient?
29. **Audit retention:** how does audit retention interact with the open
    retention decisions (E21)?

**Production and review**
30. **Production conditions:** what conditions must be met before real
    Schools use each surface?
31. **Re-review triggers:** what triggers re-review (new surface, Student
    surface, mobile/API, marks, jurisdiction, incidents)?
32. **Expiry:** is there an expiry or a review date?

### 3. Requested form of answer
Please give **per surface (a)–(e), and separately for Student accounts**, one
of: **APPROVED / APPROVED WITH CONDITIONS / NOT APPROVED**. Also give:
- the date;
- your name and role;
- answers to questions 1–32;
- **design permission**, **development permission** and **production
  permission**, each stated separately;
- the conditions;
- any prohibited processing;
- scope limits;
- expiry, re-review triggers and any jurisdictional restriction.

**Separate outcomes, please.** An answer on one surface must not be read as
an answer on another. Nothing here asks about marks or results (E42), so no
answer here can authorise them.

This request does **not** ask about:
- production StudentMark (E36) or teacher marks (E35/E37);
- results, report cards or transcripts (E39–E41);
- marks/results access by Students or Guardians (E42);
- online payment (E28);
- retention periods (E21);
- teacher Attendance (E33).

Each stays a separate item.

## Recording the answer (engineering instructions)
- Record the answer verbatim in a new dated document,
  `docs/security/POR-L1-GUARDIAN-STUDENT-PORTAL-DETERMINATION.md`.
- Update ADR 0058 row E46 by a dated, reviewed change. Preserve historical
  text.
- Amend ADR 0070 where the answer changes a default: the legal-guardian
  predicate, MFA, history after access ends, age 18, restrictions.
- Nothing in `PortalAvailability` is lifted until the recorded production
  conditions are met.
- Engineering never fills in or infers any part of the answer.
