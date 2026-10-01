# Runbook: staff and School-admin accounts (ADR 0059, Phase 0O.12B)

**Status: repository-implemented and qualified.** Main `e52c4c4` has both
images VERIFIED (`PHASE-0O-READINESS.md` §44), and ADR 0058 E24 is
REPOSITORY_COMPLETE. Nothing here has been performed against
a real deployment; every production step is deploy-gated (rule 16).

- **What it is.** How people get, lose and regain login access to a
  School.
- **What it never is.** It never creates or changes an HR Employee: User is
  not Employee, and linking is an explicit HR action under the existing
  rules.

## 1. The first School's administrator (operator console)

Use this once per School, before the School is first activated. Nothing
here is reachable over HTTP.

1. **Root exists.** `platform:bootstrap-root` has run, and root has
   enrolled MFA (every lifecycle action needs a fresh code).
2. **Create the account.** On the operator console (the release/console
   process that holds the admin database credentials), run:

   ```
   php artisan platform:provision-school-admin-account person@school.example --name="Full Name" [--school=<slug-or-id>] [--hours=24]
   ```

   - Type the email again when asked.
   - The command creates an account with **no password** and prints a
     **one-time activation link, once**. It is never logged, stored or
     audited.
   - `--school` names a School that is still `provisioning`. It is audit
     context only.
3. **Hand the link over.** Give it to that person over an **authenticated
   channel**, such as a verified call or an in-person handover. Never use
   chat, a ticket, a shared mailbox or a screenshot. The link is Highly
   Sensitive and expires (24 h by default, 72 h at most).
4. **Activation.** The person opens the link on the platform host and
   chooses a password. There is no auto-login: they then sign in normally.
5. **Create the School.** Root creates it (`/app/platform/schools`),
   naming that email as administrator. Or, for a School already
   provisioning, root replaces the bootstrap administrator.
6. **Activate the School.** Root activates it. While the administrator has
   not activated their account, activation is refused with
   `admin_not_activated`.

**The link was lost or expired.** Run the same command again for the same
email. It re-issues a new link, and the old one stops working at once.
Never use `platform:user-password-reset` or account recovery: both refuse
an account that was never activated.

**Refusals (specific, for the operator):**
- the account already has a password: name it directly as the
  administrator;
- the account is disabled: re-enabling is a separate decision;
- the School is not provisioning;
- the run is non-interactive, or the confirmation did not match.

## 2. Staff accounts (Settings → Staff accounts, the School's own admins)

| Action | Capabilities | Fresh MFA code |
|---|---|---|
| Invite | `school.members.manage` + `school.roles.manage` | yes |
| Resend or revoke an invitation | `school.members.manage` | yes |
| Remove access (suspend) or reactivate | `school.members.manage` + `school.roles.manage` | yes |
| Grant or revoke one role | `school.roles.manage` | yes |
| View the page | `school.members.view` | no |

- **Invitations** go by email only, through the durable email layer
  (critical, School-scoped). While email is unavailable, inviting is
  refused (`email_unavailable`). They expire after 7 days, and only one is
  pending per address.
- **Roles** come only from the School role catalog, and only ones whose
  permissions you hold yourself.
- **Production: do not grant the `teacher` role** while TCH-L1 / ADR 0058
  E33 is OPEN (owner decision, ADR 0063 §40). The role also enables
  teacher Attendance, which awaits a legal/compliance determination.
  Development and demo environments are unaffected.
- **Accepting an invitation.**
  - A new person chooses a name and password.
  - A person with an existing account signs in first, then opens the link
    again.
- **Anti-enumeration.** The School only ever learns whether an address is
  already its own member or already invited.
- **Removing access (off-boarding)** suspends the person's access to THIS
  School and revokes their roles here.
  - Their account, their password, MFA, other Schools and HR records are
    untouched.
  - Their next request to this School is refused.
- **Reactivating** requires choosing roles again. Earlier roles never come
  back by themselves.
- **Limits:**
  - nobody changes their own access here: another School Admin does it;
  - no change may leave the School without an administrator who can
    manage staff (`last_administrator`).

## 3. Diagnostics

- **School audit log:** `staff.account_*`, `school.membership.*`
  (ids and role keys only).
- **Platform audit:** `platform.account.provisioned`,
  `platform.account.activation_reissued`, `auth.account_activated`,
  `staff.account_invitation_refused_protected` (a platform operator
  refused as staff).
- **Email:** purpose `staff_account_invitation` (critical); OBS-31–38
  apply.
- **Cleanup:** `platform:staff-account-credentials-prune` (hourly)
  deletes activation credentials 24 h after they end and invitations 7
  days after. The audit ledgers are the record. No legal retention is
  implied (ADR 0058 E21).
