// SWC.1 (ADR 0073 section 7, owner decision X4): the user-facing product
// name for the browser title. Mirrors App\Support\ProductIdentity::NAME
// (pinned equal by Tests\Feature\ProductIdentityTest); pages read the
// shared `product` prop instead.
export const PRODUCT_NAME = 'Lycenza';
