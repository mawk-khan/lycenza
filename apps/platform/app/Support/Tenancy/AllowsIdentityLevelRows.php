<?php

namespace App\Support\Tenancy;

/**
 * Phase 0O.10A (ADR 0056 section 9.3): a tenant-owned model whose table also
 * holds IDENTITY-level rows (`school_id IS NULL`; TenantRls::enableWithPlatformScope).
 * Inside App\Support\Email\PlatformEmailScope -- and only there -- SchoolScope
 * shows exactly those rows and BelongsToSchool leaves `school_id` NULL on
 * create. Everywhere else the model behaves like any tenant model.
 */
interface AllowsIdentityLevelRows {}
