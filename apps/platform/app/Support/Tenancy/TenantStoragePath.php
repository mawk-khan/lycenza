<?php

namespace App\Support\Tenancy;

use App\Models\School;
use InvalidArgumentException;

/**
 * Tenant-aware storage path primitive (section 26). Does NOT implement
 * the Documents module (ADR 0012) -- only the safe path-building rule
 * every future file-handling module must use, so tenant-owned files are
 * structurally segregated under "schools/{school_id}/..." and a
 * caller-supplied path fragment can never escape that prefix via
 * traversal.
 */
class TenantStoragePath
{
    public static function for(School $school, string $path): string
    {
        $normalized = self::rejectTraversal($path);

        return "schools/{$school->id}/{$normalized}";
    }

    private static function rejectTraversal(string $path): string
    {
        if ($path === '') {
            throw new InvalidArgumentException('Storage path must not be empty.');
        }

        // Require a clean relative fragment -- reject rather than
        // silently normalize a leading slash, so a caller mistake is
        // never quietly absorbed into a technically-safe-but-unintended
        // key.
        if (str_starts_with($path, '/')) {
            throw new InvalidArgumentException("Storage path must be relative (no leading slash): {$path}");
        }

        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException(
                    "Storage path segment rejected (traversal or empty component): {$path}"
                );
            }
        }

        // Defence in depth against encoded traversal attempts.
        if (str_contains($path, "\0") || str_contains(rawurldecode($path), '..')) {
            throw new InvalidArgumentException("Storage path rejected: {$path}");
        }

        return implode('/', $segments);
    }
}
