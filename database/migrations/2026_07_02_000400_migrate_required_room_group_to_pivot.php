<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Backfill room_group_subject from the old required_room_group column
        |--------------------------------------------------------------------------
        |
        | Every subject that had a required_room_group value gets exactly one
        | matching row in the new pivot table. Subjects that had NULL (e.g.
        | Practicum/OJT or "None" room-type subjects) simply get no rows —
        | that's the new representation of "no program restriction assigned".
        |
        */

        DB::statement("
            INSERT INTO room_group_subject (subject_id, room_group, created_at, updated_at)
            SELECT id, required_room_group, NOW(), NOW()
            FROM subjects
            WHERE required_room_group IS NOT NULL
        ");

        /*
        |--------------------------------------------------------------------------
        | Drop the old single-value column
        |--------------------------------------------------------------------------
        |
        | Superseded by the room_group_subject pivot — keeping both would leave
        | two fields answering the same question, and only the pivot supports
        | the "one or more programs" requirement.
        |
        */

        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn('required_room_group');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {

            $table->enum('required_room_group', [
                'General',
                'BSIT',
                'BSED',
                'BSHM',
                'BSTM',
                'BSCRIM',
            ])->nullable()->default('General')->after('required_room_type');

        });

        /*
        |--------------------------------------------------------------------------
        | Reversing a many-to-many is inherently lossy
        |--------------------------------------------------------------------------
        |
        | If a subject ended up with more than one program (e.g. Business
        | Marketing -> BSHM + BSTM), there's no single value that correctly
        | represents that on the old column. This deliberately picks the
        | first assigned program alphabetically-by-insert rather than
        | guessing — anyone running this down() migration should expect to
        | manually review any subject that had multiple programs assigned.
        |
        */

        DB::statement("
            UPDATE subjects s
            LEFT JOIN (
                SELECT subject_id, MIN(room_group) AS room_group
                FROM room_group_subject
                GROUP BY subject_id
            ) rgs ON rgs.subject_id = s.id
            SET s.required_room_group = rgs.room_group
        ");
    }
};