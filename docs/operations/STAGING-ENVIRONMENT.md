# Staging environment contract (PPR.1, ADR 0074 §26)

**Status: CONTRACT IMPLEMENTED IN THE APPLICATION. NO STAGING ENVIRONMENT
EXISTS.** It is built only through the PPR.2 plan's separately approved
operator steps (rule 16): the
[architecture](STAGING-INFRASTRUCTURE-ARCHITECTURE.md),
[runbook](STAGING-PROVISIONING-RUNBOOK.md) and
[evidence plan](STAGING-EVIDENCE-PLAN.md). Nothing here describes
infrastructure that has been provisioned.

**What staging is.** The production-equivalent environment **without real
School data** (ADR 0058 §3, "Deployment"). Staging, pre-production and the
qualification environment are one environment, and the deployment evidence
for O1 is produced there.

## 1. `APP_ENV`

| Value | Meaning |
|---|---|
| `production` | the real deployment |
| `staging` | this environment |
| `local` | DDEV / a developer machine |
| `testing` | PHPUnit |

**Anything else refuses to boot:**
- typos (`stage`, `preprod`, `prod2`);
- the wrong case (`Production`);
- an empty value.

The refusal says the value is unsupported and lists the four supported
values. It never echoes the value, and debug is forced off.

**An unset `APP_ENV` is `production`,** the strictest value.

**Authority.** `App\Support\Configuration\EnvironmentPolicy` is the only
place that classifies the environment:
- **hardened:** `production`, `staging`;
- **development:** `local`, `testing`.

## 2. Staging is production-equivalent

**The boot guard** (`ProductionConfigurationGuard`, about 60 violation
codes) runs for staging exactly as for production. A refusal reads "unsafe
production configuration (codes …)" in both: staging is production-equivalent.

**Every runtime hardening applies:**
- HSTS on secure responses;
- forced HTTPS URLs;
- `platform:verify-database` failing an unencrypted connection;
- JSON logs by default;
- the email sending-domain gates;
- the SendGrid refusals;
- service-key age enforcement.

**Every development feature stays off:**
- the Guardian portal;
- StudentMark and teacher marks;
- `X-School-Id`;
- domain fakes and development hosts;
- the fake email, event and notification providers;
- the demo/internal routes;
- the demo seeder;
- the local catalogue fixtures;
- the teacher Attendance bearer API.

**Production-only refusals stay refused in staging.** Removing any of them
is a reviewed, separate change, never a staging setting.

## 3. Staging vs production — the complete difference list

| | Staging | Production | Pinned by |
|---|---|---|---|
| **Data** | Synthetic only (§4) | Real School data only after go-live authorisation | This contract; ADR 0074 §6 |
| **Lifetime** | Disposable; may be rebuilt at any time | Never rebuilt | This contract |
| **Email** | Captured by default: `MAIL_STAGING_DELIVERY=capture` declares the SMTP target a non-delivering sink, which may then be a Mailpit-style service | The declaration is refused (`mail_staging_delivery_outside_staging`) | `EnvironmentPolicy::STAGING_DIFFERENCES` `mail_staging_capture`; `HardenedEnvironmentBootTest`; `ProductionConfigurationGuardTest` |
| **`MAIL_SENDING_VERIFIED`** | Not required for a declared capture sink (nothing is delivered and no domain is verified before E19) | Required | `STAGING_DIFFERENCES` `mail_sending_verified_not_required_for_capture`; `StagingParityTest` |
| **Recipients** | Synthetic or operator-controlled test mailboxes only | Real users | This contract; ADR 0060 OD-1 |
| **Qualification tooling** | Only under explicit PPR operator actions (rule 16) | Never | ADR 0074 §23 |

Everything else is the same, including:
- HTTPS and HSTS;
- database TLS;
- secure cookies;
- the configuration checks;
- authorization and tenancy;
- three workers and one scheduler;
- private, encrypted, versioned storage;
- debug off;
- the secret-store architecture;
- migration behaviour (§6).

**A new difference** is an ADR 0074 amendment and a change to the list
pinned by `EnvironmentPolicyTest`, never a convenience.

## 4. Data policy (OD-PPR-2)

**Staging uses synthetic data only.**

**Prohibited:**
- real School data;
- copies of production databases;
- pseudonymised production databases;
- anonymised production-derived datasets, unless a later explicit legal
  determination authorises them;
- real Student, Guardian or employee data or statutory identifiers;
- real finance records;
- production documents or uploads.

**Allowed ways to create staging data:**
- project-controlled fixtures or seeding;
- the application's own bootstrap and setup workflows
  (`platform:bootstrap-root`, `platform:provision-school-admin-account`,
  School setup);
- ordinary UI/API workflows with intentionally synthetic values.

**Not allowed:**
- manual SQL edits as the standard way to populate data;
- legacy-import tooling built to populate staging.

**The DDEV demo seeder refuses non-DDEV environments by design**
(`DemoEnvironmentGuard`).

**Synthetic values:**
- **Email addresses:** test domains the operator controls. Non-delivering
  reserved names (`example.test`) wherever nothing may be delivered.
- **Phone numbers:** fictional.
- **Documents:** synthetic files.
- **Statutory identifiers:** structurally valid but synthetic.

**Retention.** Staging is disposable, and its backups contain staging data
only. On decommission, the database, bucket (all versions), Redis and
secrets are destroyed, and the destruction is recorded (E29).

## 5. Email capture over TLS

**TLS is never relaxed.** Outside `local`/`testing`, SMTP with
`MAIL_SMTP_TLS=none` is refused at submission. The boot guard requires
`required` or `implicit` TLS and SMTP credentials. A plaintext Mailpit is
therefore not a staging option.

**The staging capture sink is a TLS-capable Mailpit-equivalent service:**
- Mailpit supports STARTTLS and SMTP authentication;
- it is reachable only on the private network;
- it is its own service, never a loopback address.

**Configuration:**

```text
MAIL_PROVIDER=smtp
MAIL_STAGING_DELIVERY=capture
MAIL_HOST=<the capture service>  MAIL_PORT=<its STARTTLS port>
MAIL_SMTP_TLS=required           MAIL_USERNAME / MAIL_PASSWORD from the secret store
MAIL_SENDING_DOMAIN=<the staging sending domain>  MAIL_PROVIDER_EVENTS=none
```

**SendGrid stays dormant and refused in staging.** External delivery from
staging opens only in PPR.6, under the frozen E17–E20 contract, to approved
synthetic or test mailboxes. The `MAIL_STAGING_DELIVERY` value set will be
extended there by a reviewed change. `MAIL_PROVIDER=none` (email disabled)
is also a complete, safe staging mode.

## 6. Releases and rollback

**Releases.** Staging deploys only VERIFIED digests (ADR 0052) and runs the
same maintenance-window release (`MAINTENANCE-WINDOW-RELEASE.md`).

**Rollback** follows the one rollback contract in that runbook:
- no `down()` on rollback;
- forward corrective migrations;
- PITR restore only as an approved recovery event.

## 7. Evidence

- **Raw evidence:** stays outside Git, under
  `$LYCENZA_OPERATOR_EVIDENCE_ROOT/ppr/`.
- **The repository:** holds only E29-safe `EVID-<row>-<YYYY>-<n>` entries
  (`OPERATOR-EVIDENCE-RECORD.md`).
- **Operators:** appear as opaque references (`OP-001`) plus a role.
