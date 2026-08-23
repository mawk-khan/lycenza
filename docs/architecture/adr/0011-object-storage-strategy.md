# ADR 0011: S3-Compatible Object Storage

- Status: Accepted
- Date: 2026-08-22

## Context

The ERP eventually stores a large volume of files: admission documents,
student photos, fee receipts, exam papers/answer scripts, HR documents,
compliance records. This needs a storage technology decision now so the
local dev environment, filesystem abstraction, and future modules are
consistent from the start.

## Decision

All application file storage uses **S3-compatible object storage**
through Laravel's `Filesystem` (`flysystem`) `s3` driver. Local
development and CI use **MinIO** as a self-hosted S3-compatible
service (`infrastructure/docker/docker-compose.yml`'s `minio` service);
production uses a managed S3-compatible provider (concrete provider is
a deployment-time decision, deliberately not made in Phase 0A — see the
stop gates in `docs/roadmap/MASTER-ROADMAP.md`).

## Rationale

- The S3 API is the de facto standard for object storage; every major
  cloud (including India-based/India-region options) and several
  India-specific providers offer S3-compatible storage, so this
  decision doesn't lock the product into one vendor.
- Laravel's `flysystem` `s3` driver already supports a custom
  `endpoint` and path-style addressing (see
  `apps/platform/config/filesystems.php`), which is exactly what's
  needed to point the same driver at MinIO locally and a real
  S3-compatible provider in production — no code branches per
  environment.
- Object storage (not local disk, not database BLOBs) is the correct
  fit for files that must survive application server restarts/scaling
  and that shouldn't bloat Postgres (ADR 0003) with binary payloads.

## Alternatives considered

1. **Local disk storage.** Rejected for anything beyond a developer's
   own machine: doesn't survive horizontal scaling or redeploys, no
   built-in durability story, and complicates backup/retention policy
   compared to object storage.
2. **Database BLOBs.** Rejected: bloats the primary transactional
   database, hurts backup/restore time, and Postgres is not the right
   tool for large binary file storage at this volume.
3. **Locking to one specific cloud's proprietary storage API
   (non-S3-compatible).** Rejected: would tie the whole file-handling
   layer to one vendor with no abstraction, contrary to the
   provider-independence principle this product applies elsewhere (see
   ADR 0013 for the equivalent principle applied to AI providers).

## Consequences

- Every environment (local, CI, staging, production) needs a running
  S3-compatible endpoint; there's no "just write to disk" fallback path
  for file uploads.
- File storage architecture (how files attach to domain entities,
  access control, retention, virus scanning) is a separate design
  concern layered on top of this ADR — see ADR 0012.
- Object storage credentials must be tenant-aware at the *path* level
  even though the bucket/credential may be shared (see
  `docs/architecture/TENANCY.md`, "tenant-aware files") — this ADR
  fixes the storage technology, not the per-tenant access-control
  scheme, which ADR 0012 and `docs/security/DATA-CLASSIFICATION.md`
  cover.

## Future extraction/evolution path

If a specific customer's compliance requirements demand storage in a
specific data-residency region or a dedicated bucket/credential set,
that's a configuration change (a different `AWS_*`/endpoint
configuration for that tenant's file operations) rather than an
architecture change, because the abstraction is already provider-
independent by construction.
