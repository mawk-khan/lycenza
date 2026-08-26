<?php

namespace App\Domain\Communications\Domain;

/**
 * Phase 5D.2 -- the two factual states an explicit consent/withdrawal
 * decision can record for a domain recipient's channel. Deliberately
 * only two cases: absence of any event row is its own, third state
 * ("unknown" -- no decision was ever recorded) represented by a null
 * current-status lookup, never a third enum case here (a real event
 * always records one explicit fact; "no fact yet" is not itself a
 * fact). See CommunicationDomainConsentEvent's docblock for why this
 * is an append-only ledger, not a single mutable row.
 */
enum CommunicationConsentStatus: string
{
    case Granted = 'granted';
    case Withdrawn = 'withdrawn';
}
