<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Guardian Account Invitation (Phase 5D.3 §17)
    |--------------------------------------------------------------------------
    |
    | How long an issued invitation stays usable before it becomes
    | (derived, never a written status -- see
    | App\Domain\Identity\Infrastructure\GuardianAccountInvitation::isExpired())
    | expired. Deliberately separate from any Communication Hub config
    | (config/communications.php) -- this gates account provisioning,
    | an Identity-domain concern, not a communication broadcast.
    |
    */

    'guardian_invitation_expiry_days' => (int) env('GUARDIAN_INVITATION_EXPIRY_DAYS', 7),

];
