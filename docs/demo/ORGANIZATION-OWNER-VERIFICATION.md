# Organization Owner Verification Guide (ORG.12 closure)

A short path through the finished Organization Control Plane, in local DDEV
only. Run `ddev demo-reset` first, then sign in at
`https://lycenza.ddev.site/login` with the documented local demo password.
All data is fictional (`docs/demo/ORGANIZATION-DEMO-DATA.md`).

**Allow about 20 minutes. Use a private window per persona.** Sign out, or
switch windows, between steps.

| # | Sign in as | Click | You should see | You should NOT be able to |
|---|---|---|---|---|
| 1 | `network.viewer@example.test` | *Your School Groups* → **Northstar Education Network** → **Network report** | 6 member Schools. Relationships: owned 2, franchise 1, managed 1, affiliate 1, unclassified 1. Telangana 4 (Hyderabad Central 2, Hyderabad West 1, Region only 1), Karnataka 1 (Bengaluru North 1), Not placed 1. Governance attention: 1 unclassified (*List* shows Mysuru) | see any student, staff, fee or Campus figure, or any link into a School |
| 2 | `network.region@example.test` | Same Network → **Network report** | Telangana only: Gachibowli, Jubilee Hills, Secunderabad, Warangal. No "Not placed" row | find Hebbal or Mysuru, even by searching their names |
| 3 | `network.zone@example.test` | Same → **Network report** | Hyderabad Central only: Jubilee Hills and Secunderabad | see any other Zone or Region |
| 4 | `network.manager@example.test` | Same → the Group page (**Northstar Education Network**) | The governance workspace (Regions, Zones, classification, placement). **Network report** shows the same 6 Schools | open `/app/students` or any School page: refused |
| 5 | `gachibowli.admin@example.test` | *School setup* → **Setup & readiness** | Core in progress: Academic year needs work, and dependent areas are blocked | — |
| 6 | (same) | *School setup* → **School setup** → School profile; **Campuses** | Full institutional profile; two Campuses with addresses and services | change identity references without a reason and a fresh code |
| 7 | (same) | *Settings* → **Access Center** | 17 role templates; effective access per person, e.g. "Cashier — Campus: Gachibowli Main Campus" | grant anything here (read-only) |
| 8 | (same) | *School setup* → **Branding & navigation** | Brand inherited from the Northstar Network (stripe and chip); Data health hidden; Students reads "Learners" | gain or lose access through a menu change |
| 9 | (same) | *School setup* → **Integrations & devices** | Webhooks Healthy, API clients Configured (verify MFA if asked); SMS, payment gateway, biometric, RFID, VTS and AI shown Not available, with the reason | see a URL, secret, key or client name; test a connection |
| 10 | (same) | *School setup* → **Organization report** | Setup summary by category (core and enabled modules kept separate). Configuration gaps, blocked first, each with its owner and a *Configure* link. Integrations, presentation and access summaries. "Sensitive field catalogue available; configurable deny policy not implemented." | see a person, record or amount; download anything |
| 11 | `greenfield.admin@example.test` | *School setup* → **Organization report** | Optional modules listed as *not enabled*, never as gaps; Lycenza default brand | — |
| 12 | `school.admin@example.test` | *School setup* → **Organization report** | Lycenza Demo School: core complete | — |
| 13 | `platform.manager@example.test` | Try `/app/school-setup/organization-report` and the Network report URL | Refused (no School membership, no Network grant) | read any School's report or a Network report through a platform role |

## What closure means

- The Organization Control Plane is complete for this product stage.
- That does not mean the product is complete or production-ready. SIS, HR,
  Finance, Admissions, Integrations and the portals have their own
  programmes.
- The open prerequisites are recorded in ADR 0080 §13 and
  `docs/organization/ORG-12-REFERENCE-CAPABILITY-MAP.md`: sensitive-field
  deny policy, governed media, domain integrations, legal gates.

`ddev demo-reset` restores everything after any experiment.
