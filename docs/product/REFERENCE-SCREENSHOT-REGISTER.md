# Reference screenshot register (ORG.9A, 2026-10-23)

Status: **evidence prerequisite — no screenshot pack was available to the
execution environment.** ADR 0076's phase table (ORG.9 row) already required "the
owner's screenshots and XLS menu reports available to the execution
environment" for ORG.9. The workbook was available (`REFERENCE-PARITY-MATRIX.md`
§2); the screenshot packs the owner supplied in earlier conversations are
not on this machine and were not re-supplied. **No screen family is indexed
and no E3–E5 claim is made anywhere in the ORG.9A outputs.**

## Expected packs (from the ORG.9A brief)

| Pack | Status | Effect on the matrix | Functions that would gain most |
|---|---|---|---|
| HR (incl. HR MIS / reports) | not available | HR rows stay E2 | HR-003, HR-041, HR-042, HR-04x filters |
| Finance (incl. reports) | not available | Finance rows stay E2 | FIN-012/013/014, FIN-031/034/054, FIN-033 |
| Inventory (owner: "completed pack") | not available | Inventory rows stay E2 | INV-005/011/014/020 |
| Transport (owner: "completed pack") | not available | Transport rows stay E2 | TRN-015, TRN-036, fee reports |
| Student Information | not available | SIS rows stay E2 | CRT-001/002, SIS-016/019/020 |
| Reporting / MIS | not available | report rows stay E2 (report class from labels only) | all `report_class` rows |

## How to index a pack when supplied

Place the images (or an archive) in a folder readable from the WSL
environment, outside the repository; screenshots are competitor material and
are never committed (ADR 0075 §1). For each screen family record, in
Lycenza's own words:

| Field | Meaning |
|---|---|
| Domain | Lycenza domain |
| Screen family | a short neutral description (never the reference title or layout) |
| Functions evidenced | function ids from `reference-parity/functions.csv` |
| Workflow evidence | states, actions, confirmations seen (E4) |
| Filter evidence | filters visible on the screen |
| Role/permission evidence | persona or permission visible, if any |
| Mapped rows | the function ids whose evidence rises to E3/E4/E5 |

Indexing is read-only; a function's status or decision changes only when
the screen shows something the workbook label could not (for example a
workflow state or an approval step).
