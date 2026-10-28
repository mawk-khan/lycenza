<?php

namespace App\Support\Setup;

/**
 * ORG.12A (ADR 0080 §5): the closed setup-category vocabulary of the
 * Organization Control Plane. Code-owned; a School never defines a category.
 */
enum SetupCategory: string
{
    case ORGANIZATION = 'organization';
    case ACADEMIC = 'academic';
    case PEOPLE = 'people';
    case OPERATIONS = 'operations';
    case FINANCE = 'finance';
    case COMMUNICATIONS = 'communications';
    case INTEGRATIONS = 'integrations';
    case PORTAL = 'portal';
    case COMPLIANCE = 'compliance';
}
