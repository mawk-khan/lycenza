<?php

namespace App\Domain\Communications\Domain;

/**
 * Phase 5A.1 §2.4/§16: `Critical` exists now so a later Phase 5H
 * emergency-broadcast feature does not require a message schema change
 * -- no emergency workflow reads this value yet.
 */
enum CommunicationPriority: string
{
    case Normal = 'normal';
    case Important = 'important';
    case Urgent = 'urgent';
    case Critical = 'critical';
}
