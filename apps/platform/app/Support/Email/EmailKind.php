<?php

namespace App\Support\Email;

/**
 * ADR 0055 section 3: critical mail (account access, security) and
 * standard mail (School operational notices) get separate budgets and
 * complaint scopes. Critical never bypasses suppression.
 */
enum EmailKind: string
{
    case Critical = 'critical';
    case Standard = 'standard';
}
