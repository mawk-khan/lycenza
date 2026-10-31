<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SIS 11.H1 (SIS 11.R1 finding L-1): the database enforces the relationship
 * the Applicant-document code assumes.
 *
 * Before: `applicant_documents.document_id` had no foreign key at all, and
 * `documents_id_applicant_school_unique (id, applicant_id, school_id)` was
 * referenced by nothing. It never could be: conversion re-points a
 * Document's owner from the Applicant to the Student, so an owner-specific
 * key would break the moved record.
 *
 * After:
 * - `documents_id_school_unique (id, school_id)` and
 *   `applicant_documents_document_fk (document_id, school_id)` -> documents,
 *   ON DELETE CASCADE: the record names an existing Document of its own
 *   School, and goes with its Document (the same contract as
 *   `student_documents`; Documents are only ever removed by a retention
 *   purge, never at runtime);
 * - `applicant_documents_document_owner_guard` (BEFORE INSERT): a new record
 *   names a Document owned by its own Applicant -- after that the identity
 *   is immutable (applicant_documents_guard), and a move never changes it;
 * - the dead owner-specific unique key is dropped.
 *
 * No backfill and no guessing: existing rows are verified first and the
 * migration refuses to run if any record names a missing or foreign
 * Document, or a Document of another Applicant that never moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphans = (int) DB::selectOne('SELECT count(*) AS n FROM applicant_documents a
            WHERE NOT EXISTS (SELECT 1 FROM documents d WHERE d.id = a.document_id AND d.school_id = a.school_id)')->n;
        $foreign = (int) DB::selectOne('SELECT count(*) AS n FROM applicant_documents a JOIN documents d ON d.id = a.document_id
            WHERE a.moved_to_student_at IS NULL AND d.applicant_id IS DISTINCT FROM a.applicant_id')->n;
        if ($orphans > 0 || $foreign > 0) {
            throw new RuntimeException("Cannot harden applicant documents: {$orphans} record(s) name a missing or foreign-School Document and {$foreign} name another Applicant's Document. Investigate them; nothing is guessed.");
        }

        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_id_applicant_school_unique');
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_id_school_unique UNIQUE (id, school_id)');
        DB::statement('ALTER TABLE applicant_documents ADD CONSTRAINT applicant_documents_document_fk
            FOREIGN KEY (document_id, school_id) REFERENCES documents (id, school_id) ON DELETE CASCADE');

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION applicant_documents_document_owner_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM documents d
                               WHERE d.id = NEW.document_id AND d.school_id = NEW.school_id AND d.applicant_id = NEW.applicant_id) THEN
                    RAISE EXCEPTION 'an applicant document record names a Document of its own Applicant' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER trg_applicant_documents_document_owner_guard BEFORE INSERT ON applicant_documents
                FOR EACH ROW EXECUTE FUNCTION applicant_documents_document_owner_guard();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_applicant_documents_document_owner_guard ON applicant_documents;
            DROP FUNCTION IF EXISTS applicant_documents_document_owner_guard();
            SQL);
        DB::statement('ALTER TABLE applicant_documents DROP CONSTRAINT applicant_documents_document_fk');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_id_school_unique');
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_id_applicant_school_unique UNIQUE (id, applicant_id, school_id)');
    }
};
