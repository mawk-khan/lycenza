# Test Environment & Worktree Safety

This document records a confirmed class of repository hazard (ambient
environment silently shadowing intended test configuration, and one
Git worktree's Docker Compose lifecycle silently affecting another's),
the exact root causes found for each, and the fail-closed mechanisms
this checkpoint (`chore/test-environment-hardening`) adds to close
them. It complements, and does not replace, `CLAUDE.md`'s existing
testing-safety rules (50-54) and `App\Support\Testing\TestDatabaseGuard`.

## 1. Observed incidents

All of the following were directly reproduced during Phase 5 closure
verification and this checkpoint, not inferred or invented:

- **CACHE_STORE / QUEUE_CONNECTION / MAIL_MAILER shadowing.** Running
  the Communications test suite via `docker exec` into the shared
  `platform` container, with no explicit `-e` overrides, produced 44
  failures across files completely untouched by the change under
  test. Root cause: the container's ambient process environment
  (`QUEUE_CONNECTION=redis`, `MAIL_MAILER=log`, from `apps/platform/.env`
  via `docker-compose.yml`'s `env_file:`) silently won over
  `phpunit.xml`'s intended `sync`/`array` values.
- **Development database reachable from a testing-labeled invocation.**
  The same ambient-shadowing mechanism applies to `DB_DATABASE`: a raw
  `docker exec` (or any invocation that does not explicitly re-pass
  `DB_DATABASE=school_os_test`) resolves to the ordinary development
  database `school_os`, exactly the class of incident
  `App\Support\Testing\TestDatabaseGuard` was built to catch (see its
  docblock for the original Phase 0C.3A occurrence) -- now reproduced
  as a **PHPUnit-level**, not just raw-`artisan`-level, risk.
- **The `$_SERVER` gap.** Adding `force="true"` to `phpunit.xml`'s
  `<env>` block corrects `getenv()`/`$_ENV`, but Laravel's `env()`
  helper (via `vlucas/phpdotenv`'s `RepositoryBuilder`) reads
  `ServerConstAdapter` (`$_SERVER`) BEFORE `EnvConstAdapter` (`$_ENV`)
  or Laravel's appended `PutenvAdapter` (`getenv()`) -- confirmed by
  reading `vendor/vlucas/phpdotenv/src/Repository/RepositoryBuilder.php`
  directly. PHPUnit's `PhpHandler` never touches `$_SERVER`. PHP's CLI
  SAPI (`variables_order` including `E`) seeds `$_ENV` and `$_SERVER`
  identically from the real process environment at startup, before
  `force="true"` ever runs -- so without a fix, `getenv('QUEUE_CONNECTION')`
  correctly returns `sync` while `config('queue.default')` (what the
  application actually uses) still returns `redis`, in the exact same
  process. This was found, not assumed: see
  `tests/Feature/Infrastructure/TestEnvironmentSafetyTest.php`'s
  subprocess proofs.
- **`config:cache` freezes `app()->environment()` itself.** Caching
  configuration while `APP_ENV` resolves to `local` makes
  `app()->environment()` keep returning `local` even when a later
  invocation explicitly passes `APP_ENV=testing` on `docker exec`.
  Confirmed directly (see section 7). This never produces a dangerous
  fail-OPEN bypass (the guard reads the same frozen source it would use
  for real queries, so it stays internally consistent and refuses), but
  it produces a confusing "refuses to run outside APP_ENV=testing"
  error unrelated to the caller's actual `APP_ENV`.
- **`docker-compose.yml`'s hardcoded `name: school-os`.** Every
  worktree that has not manually worked around it (see
  `docker-compose.phase1b.yml`, `docker-compose.phase1d1.yml`, and the
  `school-os-phase1f` project observed live in this repository's
  history) shares one Docker Compose project. `docker compose up`
  from a different worktree directory recreates the `platform`
  container bound to THAT worktree's `apps/platform`, and a bare
  `docker compose down` run without an explicit `-p`/project scope
  stops and removes the SHARED project's containers regardless of
  which worktree the operator intended -- reproduced directly during
  this checkpoint (see section 12/"Failure examples").
- **Worktree-relative bind paths.** `docker-compose.yml`'s
  `./apps/platform:/var/www/app` volume is resolved relative to
  wherever `docker compose` is invoked from, so which worktree's code
  actually runs inside the shared container depends entirely on which
  worktree last ran a `docker compose up`/`run` -- confirmed directly
  by inspecting `docker inspect <container> --format '{{.Mounts}}'`
  across this session.
- **Host toolchain cannot run this suite directly.** The host PHP CLI
  has no `pdo_pgsql` extension in this environment. Every real test
  invocation in this repository's actual practice goes through the
  `platform` container. `phpunit.xml`'s `DB_HOST` is written
  accordingly (see section 6).

Not observed and not claimed here: any case where the guard mechanisms
below actually let a destructive operation reach the wrong database
(every incident above is either a test-assertion failure from the
wrong service being used, or a confusing refusal -- never a silent
wrong-database write).

## 2. Root causes

1. **PHPUnit's `<env>` default semantics.** `vendor/phpunit/phpunit/src/TextUI/Configuration/PhpHandler.php`:
   `if ($force || getenv($name) === false) { putenv(...); }` -- an
   `<env>` entry without `force="true"` only ever fills a variable
   that is not already set; it never overrides an ambient one. This is
   documented PHPUnit behavior, not a bug in PHPUnit.
2. **`docker-compose.yml`'s `env_file:` directive.** Loading
   `apps/platform/.env` into the container makes every value in that
   file (development defaults, per `.env.example`:
   `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `MAIL_MAILER=log`,
   `SESSION_DRIVER=redis`, `DB_DATABASE=school_os`, `APP_ENV=local`) a
   REAL ambient process environment variable inside the container --
   which is exactly the "already set" state PHPUnit's non-forced
   `<env>` respects.
3. **`vlucas/phpdotenv`'s reader precedence.** `ServerConstAdapter`
   (`$_SERVER`) is checked before `EnvConstAdapter` (`$_ENV`) or
   Laravel's `PutenvAdapter` (`getenv()`) -- see
   `RepositoryBuilder::DEFAULT_ADAPTERS` and `Illuminate\Support\Env::getRepository()`.
   `force="true"` does not update `$_SERVER`.
4. **`docker-compose.yml`'s `name: school-os`** is a fixed literal,
   and Compose project identity determines both which containers a
   `docker compose` invocation controls and which bind-mounted
   directory backs them.

## 3. Ambient `.env` precedence problem (summary)

For any variable X that both `apps/platform/.env` and `phpunit.xml`
declare, the OLD effective precedence (highest wins) inside the
`platform` container was:

1. Ambient container environment (from `.env` via `env_file:`, or from
   `docker-compose.yml`'s own `environment:` block for that service)
2. `phpunit.xml`'s `<env>` value -- ONLY if (1) did not already define
   the variable

The NEW effective precedence, after this checkpoint:

1. `phpunit.xml`'s `<env ... force="true"/>` value (via
   `getenv()`/`$_ENV`, re-synced onto `$_SERVER` by
   `tests/bootstrap.php` before Laravel boots) -- for any invocation
   that goes through PHPUnit itself
2. An explicit `docker exec -e KEY=value` passed by
   `apps/platform/bin/safe-test` -- for any invocation, phpunit-driven
   or not (defense-in-depth, and the only protection a raw `artisan`
   command such as `platform:test-db-reset` ever gets, since it never
   parses `phpunit.xml`)
3. `TestDatabaseGuard` -- the fail-closed backstop specifically for
   the `pgsql`/`pgsql_admin` connections' resolved database, evaluated
   on every Laravel boot in the `testing` environment regardless of
   how that boot was invoked
4. Ambient container environment -- now only relevant for variables
   nothing above forces

## 4. `TestDatabaseGuard`

Unchanged in this checkpoint (it was already correctly designed): see
`app/Support/Testing/TestDatabaseGuard.php`. Runs on every Laravel
boot via `App\Providers\AppServiceProvider::register()`, checks
`config('database.connections.{pgsql,pgsql_admin}.database')` against
`config('database.testing_database')` whenever
`app()->environment('testing')`, and refuses to let the application
boot at all on mismatch. Deliberately config-only (never opens a
database connection to evaluate itself), which is also what makes it
immune to `config:cache` staleness creating a bypass: it reads exactly
the same frozen config the application would use for a real
connection, so a stale cache can make it refuse when it did not need
to (confusing, see section 7), but can never make it approve an
unsafe database the application would not also actually be using.

Coverage confirmed across every realistic invocation path in this
repository: `php artisan test` (boots the app in-process before
shelling to PHPUnit, and PHPUnit's own child process boots it again),
`vendor/bin/phpunit` directly, `docker exec` into the `platform`
container, `docker compose exec`/`run`, and any CI runner that
ultimately does one of these -- all of them boot the full Laravel
application, and `AppServiceProvider::register()` runs unconditionally
on every boot.

New negative-test coverage added this checkpoint:
`tests/Unit/Support/Testing/TestDatabaseGuardTest.php` (in-process,
simulated resolved config) and
`tests/Feature/Infrastructure/TestEnvironmentSafetyTest.php` (real
subprocess, via `platform:test-db-reset`).

## 5. Canonical safe test runner

Path: `apps/platform/bin/safe-test` (bash, no dependencies beyond
`bash`/`docker`).

```
apps/platform/bin/safe-test                              # full suite
apps/platform/bin/safe-test --filter=FooTest               # forwarded to phpunit
apps/platform/bin/safe-test tests/Feature/Foo/BarTest.php   # forwarded to phpunit
apps/platform/bin/safe-test --reset-db                      # reset test DB, then exit
apps/platform/bin/safe-test --diagnostic                    # print resolved config, then exit
SAFE_TEST_ISOLATED=1 apps/platform/bin/safe-test              # isolated Compose project (see section 12)
```

Also reachable as `composer test:safe` from a host with Composer
installed (thin wrapper; the bash script is the primary documented
path since it has no host-toolchain dependency beyond Docker).

What it does, in order: brings up `postgres`/`redis`/`minio`/`platform`
for the resolved Compose project; runs `php artisan config:clear`
inside the container (see section 7); runs
`php artisan platform:env-diagnostic` and refuses to proceed at all if
resolved configuration fails the fail-closed contract; then runs the
requested action with the full `SAFE_ENV` override set explicitly
passed on `docker exec`, and (for the `test` action)
`php -d memory_limit=1G vendor/bin/phpunit` (the default 128M CLI
`memory_limit` reliably exhausts on this repository's full suite; see
section 16).

## 6. DB fail-closed contract

`phpunit.xml`'s `DB_HOST` is `postgres` (the Compose service alias),
not `127.0.0.1`, and both now carry `force="true"`. This is a real
value correction, not just a flag addition: the OLD literal value
(`127.0.0.1`) only ever "worked" by accident, because it was always
shadowed by the container's own already-correct ambient
`DB_HOST=postgres` (set by `docker-compose.yml`'s `environment:` block
for the `platform` service) -- two wrongs cancelling out. Once
`force="true"` made the block authoritative, the value written had to
become the genuinely correct one for the only realistic invocation
path (inside the `platform` container; the host toolchain has no
`pdo_pgsql`).

**Explicit connection-target override (added at integration into
`main`).** A forced `DB_HOST=postgres` is correct inside the
docker-compose `platform` container but unreachable from CI (whose
PostgreSQL service is on `127.0.0.1`) and from DDEV (`db`, admin role
`db`). Rather than un-forcing `DB_HOST` -- which would re-open the
ambient-shadowing hole this section closes -- `tests/bootstrap.php`
applies `PHPUNIT_DB_HOST` / `PHPUNIT_DB_PORT` / `PHPUNIT_DB_ADMIN_USERNAME`
/ `PHPUNIT_DB_ADMIN_PASSWORD` after PHPUnit's forced values. These
names are set only deliberately (CI's PHPUnit step, `ddev test`), never
by `.env`/`env_file`, and can change only WHERE the test database is:
`DB_DATABASE`, `APP_ENV`, the runtime role and every cache/session/
queue/mail value remain forced (proven by
`TestEnvironmentSafetyTest::the_connection_target_override_cannot_redirect_the_test_database_name`).

`TestDatabaseGuard` remains the authoritative fail-closed backstop:
`APP_ENV=testing` plus a resolved `pgsql`/`pgsql_admin` database other
than `config('database.testing_database')` refuses application boot
entirely, proven in `TestDatabaseGuardTest` and, via a real subprocess
against `platform:test-db-reset`, in `TestEnvironmentSafetyTest`.

## 7. Cache isolation

`phpunit.xml`: `CACHE_STORE="array" force="true"`, `SESSION_DRIVER="array" force="true"`.
Proven under hostile ambient `CACHE_STORE=redis`/`SESSION_DRIVER=redis`
via a real subprocess in
`TestEnvironmentSafetyTest::hostile_ambient_cache_queue_and_mail_do_not_shadow_phpunit_via_force_true`.

**Config-cache interaction (item 7 of the checkpoint brief).**
Confirmed directly: caching configuration
(`php artisan config:cache`) while `APP_ENV` resolves to `local`
freezes `app()->environment()` itself, not just individual `config()`
values -- a subsequent invocation explicitly passing
`-e APP_ENV=testing` on `docker exec` still sees `app()->environment()`
return `local`, and `TestDatabaseGuard`'s (and
`platform:test-db-reset`'s own) `app()->environment('testing')` check
refuses to proceed. This is always the SAFE direction (a confusing
refusal, never a silent unsafe execution) because every check involved
reads the identical frozen config source a real query would also use
-- there is no path by which a stale cache makes the guard approve
something the application would not also actually be doing. `bin/safe-test`
defends against the confusing-refusal case anyway by running
`php artisan config:clear` before every invocation, mirroring what
`composer test`'s existing script already did for the same reason.
This is documented, not scripted as a permanent automated test: a real
`config:cache` write mutates shared container state for every process
using that container until cleared, so making it a routine automated
test would risk corrupting unrelated concurrent test runs in the same
container.

## 8. Queue isolation

`phpunit.xml`: `QUEUE_CONNECTION="sync" force="true"`. This is the
literal fix for the closure-blocker incident (section 1). Proven under
hostile ambient `QUEUE_CONNECTION=redis` by the same subprocess test
referenced in section 7. A future queue-integration test that
genuinely needs a real Redis-backed queue must explicitly opt in
(e.g. `Config::set('queue.default', 'redis')` scoped to that test, or
an isolated Redis instance per section 10) -- nothing here prevents
that, it only prevents it from happening BY ACCIDENT via ambient
shadowing.

## 9. Mail isolation

`phpunit.xml`: `MAIL_MAILER="array" force="true"`. Proven under
hostile ambient `MAIL_MAILER=smtp` by the same subprocess test.
`COMMUNICATION_EMAIL_ENABLED=false` (the Phase 5 production gate for
whether Communication Hub email may leave the system at all,
`config('communications.channels.email.enabled')`) is untouched by
this checkpoint -- infrastructure hardening changes test-time
configuration resolution, not this production semantic. Tests
asserting real send behavior continue to use `Mail::fake()`, as
before; nothing here changes that pattern.

## 10. Redis rules

No test in this repository's existing suite intentionally targets a
real Redis instance for cache/queue/session; the default contract
(`CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array`)
is what every test gets unless it explicitly overrides `config()` for
itself. `REDIS_HOST`/`REDIS_PORT`/`REDIS_DB`/`REDIS_CACHE_DB` are
listed in `platform:env-diagnostic`'s output for visibility but are
not forced by `phpunit.xml`, since the shared Redis instance
(`redis` service, `school-os` project) is legitimately still used by
whatever the `platform` container's OTHER concurrent processes are
doing (e.g. a real dev server). This checkpoint does not add or
require `FLUSHALL`/`FLUSHDB` anywhere, and none was used at any point
during this work -- see `CLAUDE.md`'s equivalent rule and section 16
below for the incident where a bare `docker compose down` (not a
Redis flush) briefly stopped the shared project's containers during
this very checkpoint.

## 11. MinIO rules

`AWS_ENDPOINT` already resolves correctly for the only realistic
invocation path: `docker-compose.yml`'s `environment:` block sets
`AWS_ENDPOINT=http://minio:9000` for the `platform` service (higher
Compose precedence than `env_file:`), confirmed directly via
`docker exec ... env`. The `localhost`-vs-`minio` inconsistency named
in the checkpoint brief is specifically a HOST-side concern (a
hypothetical host-native test run, or host tooling reaching the same
bucket, would need `http://localhost:9000`, since `minio` only
resolves inside the Compose network) -- not a problem for the
documented containerized test path, which this checkpoint makes the
one canonical path via `bin/safe-test`. `platform:env-diagnostic`
prints the resolved `AWS_ENDPOINT`/`AWS_BUCKET` so this stays visible
rather than assumed.

## 12. Compose worktree collision

`docker-compose.yml`'s `name: school-os` is left unchanged: it remains
the correct default for the common "one primary worktree, one
developer" case, and rewriting it risks breaking every existing
document/script that already assumes it (CLAUDE.md's "Running things
locally", and every other worktree's own ad hoc
`docker-compose.phase*.yml` files, which already assume a fixed,
predictable name to intentionally differ FROM).

`bin/safe-test` instead supports `SAFE_TEST_ISOLATED=1`, which:

- derives a project name deterministically from the worktree root
  (`school-os-test-$(worktree path | cksum)`) -- the SAME worktree
  path always derives the SAME name (idempotent re-runs reuse
  containers/volumes), and a DIFFERENT worktree path always derives a
  DIFFERENT name. Verified directly for three real worktree paths in
  this repository:

  ```
  .../lycenza-test-environment-hardening         -> school-os-test-1201721102
  .../lycenza-phase-5-approval-fingerprint-fix    -> school-os-test-2264800202
  .../lycenza                                     -> school-os-test-2739035513
  ```
- generates a throwaway Compose override (via `docker compose -f ... -f <generated>`,
  never editing the tracked `docker-compose.yml`) that removes ALL
  host port publishing (`ports: !reset []` -- Compose merges
  list-typed keys by default, so an empty list alone is a no-op; the
  Compose-spec `!reset` tag is what actually clears the base value,
  confirmed directly against `docker compose config` output) and
  reuses the already-built shared image (`image: school-os-platform:latest`)
  instead of rebuilding.
- since every real interaction `bin/safe-test` performs is
  `docker exec` into the `platform` container reaching
  `postgres`/`redis`/`minio` by Compose SERVICE NAME over that
  project's own private network, removing host port publishing
  entirely is safe and eliminates port collision between two isolated
  projects (or between an isolated project and the shared one) as a
  concern altogether, rather than requiring a scheme to pick unique
  host ports per worktree.

Verified live, end to end, during this checkpoint: `SAFE_TEST_ISOLATED=1`
brought up a fully isolated `school-os-test-<hash>` project (`docker ps`
confirmed zero published host ports, e.g. `5432/tcp` not
`0.0.0.0:5432->5432/tcp`), ran a real test filter successfully against
it, and the shared `school-os` project's containers were confirmed
running and unaffected throughout (except for the incident in section
16, which was a manual operator error, not something `bin/safe-test`
itself did).

Approaches considered and not taken: rewriting `docker-compose.yml`'s
`name:` unconditionally (breaks the documented default single-worktree
flow, item 16 of the checkpoint brief); a repository-wide dynamic
port-allocation scheme (unneeded complexity once host ports are simply
not published for isolated runs); one hand-authored
`docker-compose.phase<N>.yml` per phase forever (the existing,
organically-grown pattern this checkpoint replaces with one canonical,
parameterized mechanism).

## 13. Canonical dev workflow

Unchanged. `docker compose up -d postgres redis minio` /
`docker compose run --rm platform composer install` /
`php artisan serve --no-reload`, exactly as `CLAUDE.md`'s "Running
things locally" already documents, against the shared `school-os`
project. Nothing in this checkpoint requires a developer to learn a
new command list for ordinary development.

## 14. Canonical test workflow

`apps/platform/bin/safe-test` (see section 5) replaces hand-typing the
`docker exec -e ... -e ... -e ...` argument list this session
previously had to reconstruct from CLAUDE.md's documented commands
each time. `--reset-db` replaces the multi-line `DB_*`-prefixed
`php artisan platform:test-db-reset --force` invocation documented in
CLAUDE.md's "Running things locally" (that documented form remains
correct and still works; the script is the encoded, harder-to-typo
version of the identical values).

## 15. CI implications

Not evaluated in this checkpoint (no CI configuration exists in this
repository to update). If one is added later, it should invoke
`apps/platform/bin/safe-test` (with `SAFE_TEST_ISOLATED=1` if CI ever
runs multiple concurrent jobs against the same Docker host) rather
than reconstructing the environment-override list independently, so
CI and local development share exactly one source of truth for what
"safe" means.

## 16. Agent/Claude instructions

See `CLAUDE.md` rule 51 (updated this checkpoint) and the new rules
added alongside it: prefer `apps/platform/bin/safe-test` over a
hand-typed `docker exec -e ... php artisan test`/`vendor/bin/phpunit`
invocation; never run a bare `docker compose down`/`up` without an
explicit `-p <project>` once more than one Compose project might be in
play (see section 1's `name: school-os` hazard and section 17's
incident); never assume `phpunit.xml` alone overrides ambient `.env`
without `force="true"` (it does not, by PHPUnit's own documented
default); never flush Redis; never run a destructive migration/reset
outside `platform:test-db-reset`/`bin/safe-test --reset-db`, both of
which verify test-database identity via `TestDatabaseGuard` before
doing anything destructive.

## 17. Failure examples

- The 44-failure Phase 5 closure-blocker incident (section 1): every
  failure's assertion message was plausible-looking domain content
  (`'delivered'` vs `'pending'`, `Mail::assertSentCount` mismatches) --
  nothing about the failures themselves suggested an environment
  problem. The only reason it was correctly diagnosed as environmental
  rather than a real regression was that every failing test file was
  confirmed byte-identical to `main` (`git diff main` -- zero lines)
  before any code was suspected.
- **This checkpoint's own incident:** while manually debugging the
  Compose isolation override, a bare `docker compose down` (no `-p`
  flag) was run from `lycenza-test-environment-hardening` to "clean
  up," which -- because `docker-compose.yml`'s `name: school-os` is
  the default in the absence of an explicit override -- stopped and
  removed the SHARED `school-os` project's `redis` container and
  network instead of the isolated debugging project intended. No data
  was lost (`down` without `-v` preserves named volumes; all
  containers, including `postgres`/`minio`/`platform`, were confirmed
  back to healthy after `docker compose up -d` from the shared
  project's own definition), and the concurrent `school-os-phase1f`
  project was confirmed completely unaffected throughout -- but it is
  direct, first-person evidence for exactly the hazard this checkpoint
  exists to close, and is the reason `bin/safe-test` always passes an
  explicit `-p "${project_name}"` on every Compose invocation it makes
  rather than ever relying on the file's default.
- Config-cache freezing `app()->environment()` (section 7): reproduced
  directly, documented as a confusing-but-safe failure mode rather
  than left as a mystery for a future session to rediscover.

## 18. Security rationale

Every mechanism in this document exists to make an accidental
destructive/leaky operation IMPOSSIBLE BY DEFAULT rather than merely
discouraged by documentation: `force="true"` plus
`tests/bootstrap.php`'s `$_SERVER` sync remove the ambient-shadowing
class of bug at its source for anything PHPUnit-driven;
`TestDatabaseGuard` remains the config-based, connection-free,
always-evaluated backstop for anything that is not; `bin/safe-test`'s
explicit `-e` overrides are deliberate defense-in-depth on TOP of
`force="true"`, not a replacement for it (they also protect the raw
`artisan` commands `force="true"` cannot reach at all). Isolation
(`SAFE_TEST_ISOLATED=1`) is opt-in rather than default, preserving
`CLAUDE.md` rule 16's "no shared-environment changes without explicit
authorization" posture -- the shared `school-os` project remains the
default, unisolated behavior unless a caller deliberately asks for
isolation.

## 19. Remaining limitations

- `SAFE_TEST_ISOLATED=1` is opt-in, not default; a developer/agent who
  runs a bare `docker compose` command directly (not through
  `bin/safe-test`) without an explicit `-p` still targets the shared
  `school-os` project by default, and can still collide with another
  worktree exactly as before -- this checkpoint provides the tool and
  the documented discipline, not a structural guarantee that nobody
  ever bypasses it.
- The config-cache interaction (section 7) is documented and defended
  against by `bin/safe-test`, but not covered by a permanent automated
  test, because doing so would require mutating shared
  `bootstrap/cache/config.php` state in a way that could affect other
  concurrent processes in the same container.
- `force="true"` protects any invocation that parses `phpunit.xml`
  (`vendor/bin/phpunit`, `php artisan test`). It does not, and cannot,
  protect a raw `artisan` command that never touches that file --
  `platform:test-db-reset` and `platform:env-diagnostic` are safe
  because they are specifically designed to be (the former via
  `TestDatabaseGuard`, both via `bin/safe-test`'s explicit `-e`
  overrides), but a hypothetical FUTURE raw artisan command with its
  own destructive behavior would need the same explicit treatment --
  this is not automatically inherited.
- No CI configuration exists in this repository yet (section 15); the
  guidance for one is documented but unverified against a real CI
  runner.
- Redis/MinIO isolation is scoped to "nothing forces a shared instance
  by accident anymore" (sections 10-11), not to a fully isolated
  per-worktree Redis/MinIO by default -- `SAFE_TEST_ISOLATED=1` does
  start dedicated `redis`/`minio` containers for the isolated project,
  but the DEFAULT (non-isolated) path still shares the one `school-os`
  Redis/MinIO instance across whichever worktree currently has it
  mounted, same as before this checkpoint.

## Environment-specific integration tests (real MinIO)

`tests/Feature/Documents/DocumentMinioStorageTest.php`,
`DocumentReadMinioIntegrationTest.php` and
`DocumentHttpMinioIntegrationTest.php` (5 tests) deliberately exercise a
REAL S3-compatible endpoint rather than `Storage::fake()` (ADR 0011).
They require a reachable MinIO whose `AWS_*` settings (endpoint,
credentials, bucket) are supplied for the run -- the docker-compose
`minio` service (`docker compose up -d minio`). They are **expected to
fail** in any environment without one, including the DDEV review
environment (which deliberately runs no MinIO; see
`docs/development/DDEV-DEMO-REVIEW.md`). Such a failure means "MinIO
not provisioned", not a Documents regression; they are not skipped
automatically so that a missing object store can never masquerade as a
passing storage proof.

## Committed test data and the outbox (fixed)

Tests that opt out of `DatabaseTransactions` (`$connectionsToTransact =
[]` -- the real multi-process concurrency proofs) commit their rows.
`domain_event_outbox` has no FK to `schools`, so their events stayed
`pending` forever (whether or not the test then deleted its School; a
School with an approved/posted payroll run cannot be hard-deleted, by
design). `platform:outbox-dispatch` claims the oldest 100 pending rows,
so once enough residue accumulated in `school_os_test`, later tests'
own events were never claimed -- order-dependent failures in
`Webhooks/EndToEndProofBTest` and `WebhookReliability*`.
`Tests\TestCase::purgeOutboxResidueAfterCommittingTest()` now removes,
after every committing test, the outbox rows of Schools that no longer
exist or that the test itself created. Pre-existing Schools are never
touched.

## Load-sensitive real-concurrency tests (known, not yet fixed)

These tests race two or more real OS processes and assume the processes
genuinely overlap. Under full-suite load a process can finish before its
rival starts, so an occasional single failure appears in a full run;
each passes when re-run on its own. Observed during the 2026-09-23
consolidation (one per full run, never the same twice in a row):

- `Payroll/CompensationConcurrencyTest` scenario A -- root-caused:
  `SalaryStructureService::activate()` legitimately supersedes an
  already-active revision, so a SERIALIZED run activates both revisions
  in turn (still exactly one active at the end) while the test asserts
  exactly one `activated` output. Product behaviour is correct.
- `Payroll/PayrollRunLifecycleConcurrencyTest`,
  `AcademicStructure/AcademicYearActivationConcurrencyTest`,
  `HR/PrimaryAssignmentConcurrencyTest`,
  `Students/ProcessingAuthorization/ProcessingAuthorizationConcurrencyTest`
  (lock-holder signal deadline of 10s), and one
  `StudentEnrollment/*ConcurrencyTest` -- same symptom class, not
  individually root-caused.

Recommended fix (not applied): a start barrier in each subprocess script
(every process signals "booted" and waits until all have, then calls
the service), and assertions that accept every outcome the product
actually allows under serialization. Until then, re-run a single failing
concurrency test in isolation before treating it as a regression.

