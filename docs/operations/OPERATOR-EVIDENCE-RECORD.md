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
