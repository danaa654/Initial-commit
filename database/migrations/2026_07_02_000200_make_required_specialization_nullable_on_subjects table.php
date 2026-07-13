<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Add the new column
        |--------------------------------------------------------------------------
        |
        | required_room_group replaces required_specialization. The value
        | set is different: General/BSIT/BSED/BSHM/BSTM/BSCRIM (academic
        | programs) instead of General/IT/HM/TM/ED/FB/LD/QD/FI
        | (specializations) — notably, the four Criminalistics
        | specializations (FB/LD/QD/FI) all collapse into the single
        | BSCRIM room group, since the scheduler only needs to know "this
        | needs a Criminalistics lab," not which specialization. The new
        | column is added alongside the old one first, backfilled, then
        | the old column is dropped.
        |
        */

        DB::statement("
            ALTER TABLE subjects
            ADD COLUMN required_room_group ENUM(
                'General', 'BSIT', 'BSED', 'BSHM', 'BSTM', 'BSCRIM'
            ) NULL DEFAULT 'General' AFTER required_room_type
        ");

        /*
        |--------------------------------------------------------------------------
        | Backfill from the old values
        |--------------------------------------------------------------------------
        |
        | IT, HM, TM, ED, and General map 1:1 to their new room group.
        | FB, LD, and QD, and FI (Forensic Ballistics / Lie Detection /
        | Questioned Documents / Forensic Investigation) are all
        | specializations *under* the BSCRIM program, not standalone room
        | groups — so all four collapse to BSCRIM. The scheduler picks
        | whichever Criminalistics laboratory is free; it doesn't need to
        | know which specialization the subject is under.
        |
        */

        DB::statement("
            UPDATE subjects
            SET required_room_group = CASE required_specialization
                WHEN 'IT'      THEN 'BSIT'
                WHEN 'HM'      THEN 'BSHM'
                WHEN 'TM'      THEN 'BSTM'
                WHEN 'ED'      THEN 'BSED'
                WHEN 'FB'      THEN 'BSCRIM'
                WHEN 'LD'      THEN 'BSCRIM'
                WHEN 'QD'      THEN 'BSCRIM'
                WHEN 'FI'      THEN 'BSCRIM'
                WHEN 'General' THEN 'General'
                ELSE NULL
            END
        ");

        /*
        |--------------------------------------------------------------------------
        | Drop the old column
        |--------------------------------------------------------------------------
        */

        DB::statement('ALTER TABLE subjects DROP COLUMN required_specialization');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("
            ALTER TABLE subjects
            ADD COLUMN required_specialization ENUM(
                'General', 'IT', 'HM', 'TM', 'ED', 'FB', 'LD', 'QD', 'FI'
            ) NULL DEFAULT 'General' AFTER required_room_type
        ");

        /*
        |--------------------------------------------------------------------------
        | Reversing BSCRIM is inherently lossy
        |--------------------------------------------------------------------------
        |
        | BSCRIM could have originally been FB, LD, QD, or FI — that
        | distinction was intentionally discarded going forward, so there's
        | no way to recover which one it was. This deliberately falls
        | through to NULL rather than guessing one of the four: picking
        | any single value (e.g. always "FB") would be silently wrong for
        | the other three specializations, which is worse than an obvious
        | gap. Anyone running this down() migration should expect to
        | manually re-assign a specialization to every ex-BSCRIM row
        | afterward.
        |
        */

        DB::statement("
            UPDATE subjects
            SET required_specialization = CASE required_room_group
                WHEN 'BSIT'   THEN 'IT'
                WHEN 'BSHM'   THEN 'HM'
                WHEN 'BSTM'   THEN 'TM'
                WHEN 'BSED'   THEN 'ED'
                WHEN 'General' THEN 'General'
                ELSE NULL
            END
        ");

        DB::statement('ALTER TABLE subjects DROP COLUMN required_room_group');
    }
};