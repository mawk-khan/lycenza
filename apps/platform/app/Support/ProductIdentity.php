<?php

namespace App\Support;

/**
 * SWC.1 (ADR 0073 section 7, owner decision X4): the user-facing product
 * name on the signed-in shell, the Dashboard, the public `/` page and the
 * browser title.
 *
 * Deliberately NOT `config('app.name')`: APP_NAME also derives the session
 * cookie name and the cache and Redis key prefixes (config/session.php,
 * cache.php, database.php), so changing it for branding would sign every
 * user out and orphan cached state. Email keeps its own sender name
 * (`email.from_name`, ADR 0055). `resources/js/support/product.ts` mirrors
 * this value for the browser title; Tests\Feature\ProductIdentityTest pins
 * the two equal.
 */
final class ProductIdentity
{
    public const NAME = 'Lycenza';
}
