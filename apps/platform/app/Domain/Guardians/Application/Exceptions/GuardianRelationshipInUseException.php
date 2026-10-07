<?php

namespace App\Domain\Guardians\Application\Exceptions;

use App\Support\Observability\SafeException;
use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Guardian unlink (2026-10-07; ADR 0038 note): the relationship is still
 * referenced by retained processing-authorization evidence -- a recorded or
 * terminal (withdrawn / revoked / superseded) row of the append-only ledger --
 * so it is not deleted. The evidence is never detached or removed to make
 * room. Fixed text: no constraint, table, SQL, grant id, Student or consent
 * detail.
 */
class GuardianRelationshipInUseException extends GuardianException
{
    /** The one foreign key this refusal stands for (student_processing_authorizations -> student_guardian_relationships, RESTRICT). */
    public const string FOREIGN_KEY = 'spa_guardian_relationship_context_foreign';

    public function __construct()
    {
        parent::__construct(
            409,
            'GUARDIAN_RELATIONSHIP_IN_USE',
            'This Guardian relationship cannot be removed because retained authorization evidence still depends on it.',
        );
    }

    /**
     * The database backstop, narrowly: a foreign-key violation (SQLSTATE 23503) naming exactly FOREIGN_KEY, anywhere
     * in the previous-exception chain. Any other integrity error -- another key, another SQLSTATE, or the name in some
     * other error's text -- is not this refusal.
     */
    public static function isViolation(Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if (($cause instanceof QueryException || $cause instanceof PDOException)
                && SafeException::sqlstate($cause) === '23503'
                && str_contains($cause->getMessage(), 'constraint "'.self::FOREIGN_KEY.'"')) {
                return true;
            }
        }

        return false;
    }
}
