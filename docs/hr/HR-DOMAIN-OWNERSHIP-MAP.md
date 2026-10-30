# HR domain ownership map (HR.EXP-0)

Each function names one owner. Every other domain reaches it only through
that owner's Application service or domain events (rule 4).

| Function | Owner | Not owned by | Notes |
|---|---|---|---|
| School and Campus profile, Network governance | Organization (ADR 0080) | HR | HR reads Campus through `ActiveCampus` / composite keys |
| Login identity, memberships, staff invitations, role grants | Identity (rules 92–94) | HR | creating an Employee never creates a User; job titles are never roles |
| Employee identity, employment, assignments, Departments, Positions, Categories, reporting line | HR | Organization, Identity | `CurrentEmployeeAssignments` is the one currency reader |
| HR profile sections (personal, addresses, contacts, notes, qualifications, experience, certifications) | HR | — | sensitive projection later via the central deny-only policy (rule 114; no School policy yet) |
| HR document records | HR (metadata) + Documents (bytes, owner type `employee`) | — | content security (EXIF, PDF, malware) is Documents / platform security |
| ActingEmployee (who the staff member is) | HR | Students, Payroll | identifies, never authorizes |
| Teacher ownership | TeachingAssignments (ADR 0063) | HR | `teacher` holds no `hr.*` key |
| Leave types, years, policies, ledger, requests, decisions, staff calendar | Leave | HR, Payroll | decisions whole-School; the calendar is Leave-owned today |
| Staff attendance | StaffAttendance | Student Attendance, Leave | no clock, device or biometric data (HRX-L2) |
| Compensation, structures, runs, payslips, statutory engine | Payroll | HR, Finance | Payroll calculates; it never owns a ledger |
| Journal posting, accounts, periods | Finance (ledger) | Payroll | via `LedgerService` only |
| Salary disbursement / bank files | **not built** (would be Payments/Finance + legal) | Payroll | bank data does not exist |
| Staff audiences (Staff announcements, Events) | Communications, via `CurrentStaffMembers` (rule 108) | HR | HR defines who is current Staff, never messages |
| Staff-issued assets | Inventory (to a Department, never a person, rule 105) | HR | HR may project a safe summary later |
| Staff transport | Transport | HR | — |
| Workforce composition, trends, headcount | Analytics (person-count gate, ADR 0040) | HR | HR keeps transactional lists only |
| Recruitment / applicant tracking | **HR (future sub-domain)**, owner decision | Admissions (Student applicants) | a candidate is never an Employee until a canonical conversion |
| Performance, training, discipline, grievances | **owner/legal decision** | — | no domain today; discipline is legally sensitive |
| Exports | D-11 (cross-product decision) | HR | no HR export exists |
