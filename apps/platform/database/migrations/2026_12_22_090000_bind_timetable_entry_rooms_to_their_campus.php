<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ORG.7D-0 R1 (ADR 0076 §36): a Timetable entry's Room must be in the
     * entry's own Campus. Until now `timetable_entries_room_fk` bound the Room
     * to the School only, so an entry for a Campus A Section could book a
     * Campus B Room (ORG.7C finding R1, §35.7).
     *
     * Additive: `rooms` gains a `(id, school_id, campus_id)` key and the entry
     * a second composite FK onto it. MATCH SIMPLE keeps a Room-less entry
     * (`room_id` NULL) valid. The entry's Campus is already pinned to its
     * Section and Subject Offering (`timetable_entries_section_fk`,
     * `timetable_entries_subject_offering_fk`); a Room's Campus can no longer
     * change while an entry references it (NO ACTION on update).
     *
     * Adding the FK validates every existing row: if any entry already
     * references another Campus's Room the migration fails as a whole and
     * rewrites nothing (ORG.7D-0 §5 -- history is never corrected silently).
     * ORG.7D-0 preflight found zero such rows in every local database.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE rooms ADD CONSTRAINT rooms_campus_context_unique UNIQUE (id, school_id, campus_id)');
        DB::statement('ALTER TABLE timetable_entries ADD CONSTRAINT timetable_entries_room_campus_fk
            FOREIGN KEY (room_id, school_id, campus_id) REFERENCES rooms (id, school_id, campus_id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE timetable_entries DROP CONSTRAINT timetable_entries_room_campus_fk');
        DB::statement('ALTER TABLE rooms DROP CONSTRAINT rooms_campus_context_unique');
    }
};
