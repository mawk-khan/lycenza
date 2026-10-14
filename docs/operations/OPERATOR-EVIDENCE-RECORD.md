# Operator evidence record — template (ADR 0058 §6, E29)

**Classification: Confidential.** An ADR 0058 register row that names an
"operator record" points to one entry in this format. The restore drill
keeps its own template (`RESTORE-DRILL-RECORD.md`).

## What an entry may contain

- **When:** dates and times in UTC, and durations.
- **Where:** the environment's **role** (for example "non-production
  validation environment"), never its hostname, IP, account id or URL.
- **Commands:** names, closed result codes and counts (for example
  `platform:verify-database: 0 failed`).
- **Artifacts:** image digests, qualification run ids and evidence-bundle
  hashes.
- **Provider facts:** configuration facts stated as present or absent (for
  example "click tracking disabled: yes"), DKIM selector names and key
  lengths, DMARC policy stage, aggregate pass rates, and provider message or
  event ids used in a drill.
- **Evidence references:** where the full evidence is kept (the secret
  store, provider console or CI artifact), by **reference**, never copied.

## What an entry must never contain

- Secrets, keys, tokens, passwords, credentials or connection strings.
- Hostnames, IP addresses or internal URLs.
- Email addresses, names or any School or personal data. Drill recipients
  are "operator-controlled mailbox A/B".
- Restored payloads, filenames, object keys or raw provider payloads.
- Raw scan reports; reference the run and bundle hash instead.

## Entry format

```text
### EVID-<row>-<YYYY>-<n>  (for example EVID-E07-2026-1)

- Register row(s): E..
- Date(s) (UTC):
- Environment (role only):
- Performed by (role): Operator / Owner / Security
- Authorization (rule 16): who authorized this action, and when (role and date)
- Steps performed: numbered list, with commands and closed results
- Result: PASS / FAIL / PARTIAL, with the reason code
- Evidence references: where it is kept, by reference only
- Hygiene check (E29): reviewed by (role) on (date). Entry contains no
  secret, hostname, address or School data.
- Follow-ups: none, or a numbered list
```

A row moves to `EVIDENCE_COMPLETE` only by a dated, reviewed repository
change that cites the entry (ADR 0058 §6). **E29** is satisfied once every
cited entry has passed its hygiene check. Its final review is the last step
before O1.

## Evidence store and operator identity (PPR.1, OD-PPR-9, ADR 0074 §26.8)

**Raw evidence lives outside Git, always.** It means: command transcripts,
provider console exports, DMARC reports, CI artifacts copied before their
90-day expiry, drill measurements and screenshots.

**Location.** Under an operator-configured root, never a path written into
application code:

```text
$LYCENZA_OPERATOR_EVIDENCE_ROOT/ppr/<EVID id>/
```

- The application never reads this root. It is an operator convention for
  the machines and storage the operator controls.
- **Permissions:** the root and every directory are operator-only (`0700`
  or the platform equivalent). Files holding anything sensitive are `0600`
  (or equivalent).
- **Retention:** keep it at least until O1 is determined, then as the
  owner's evidence-retention decision says (E21 does not cover operator
  evidence). Delete it only by a recorded decision.

**What reaches the repository** is only the E29-safe entry above:
- the evidence id, UTC timestamps and the operator reference;
- the operator **role**;
- action and result codes;
- safe command or tool names;
- hashes of the raw evidence bundle;
- a minimised outcome;
- the repository SHA when relevant.

**What never reaches the repository:**
- credentials, API keys and passwords;
- private keys;
- raw secret-bearing provider responses;
- real hostnames that the hygiene rules prohibit.

**Operator identity:**
- **An opaque, stable reference** such as `OP-001`, plus a **role** such as
  `Release Operator`, `Infrastructure Operator`, `Security` or `Owner`.
- **The mapping** from reference to person is kept by the owner outside
  the repository.
- **Never in an entry:** no personal email address or name.
- **Authorization lines** name the authorizing role and date the same way.

**Naming.** It stays exactly `EVID-<row>-<YYYY>-<n>`:
- `<row>` is the ADR 0058 register row the action evidences, for example
  `EVID-E09-2026-1`.
- A PPR action that evidences no register row uses its slice id as the row
  token, for example `EVID-PPR2-2026-1`.
- An action evidencing several rows uses the primary row and lists the
  others under "Register row(s)".
- Sequence numbers are per row token and per year, and are never reused.

**No fabricated entries.** An entry describes an action that actually
happened. None exists as of PPR.1: no environment, provider account or drill
has been created.
