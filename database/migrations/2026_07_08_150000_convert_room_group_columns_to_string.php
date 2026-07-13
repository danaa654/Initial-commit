<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * room_group_subject.room_group and room_group_room.room_group were
     * both created as a hardcoded ENUM('General','BSIT','BSED','BSHM',
     * 'BSTM','BSCRIM') — a DB-level whitelist that predates
     * SubjectRoomGroup::options() reading live from the programs table.
     *
     * The application layer (SubjectRoomGroup::options(), SubjectController
     * and RoomController's validation, Create/Edit forms) was already made
     * fully dynamic so a newly added Program is immediately selectable —
     * but the ENUM columns underneath never got the same treatment, so any
     * program outside the original six (e.g. BSIE) silently truncates to
     * '' on insert and throws SQLSTATE[01000]: Data truncated.
     *
     * Converting both columns to a plain VARCHAR removes that ceiling for
     * good — a new Program never requires a migration here again.
     *
     * Raw SQL (rather than Blueprint::change()) so this doesn't pull in
     * doctrine/dbal just for two column type changes.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE room_group_subject MODIFY room_group VARCHAR(30) NOT NULL"
        );

        DB::statement(
            "ALTER TABLE room_group_room MODIFY room_group VARCHAR(30) NOT NULL"
        );
    }

    /**
     * Reverse the migrations.
     *
     * Rolling back restores the original fixed ENUM. Any row carrying a
     * program added after this migration ran (e.g. BSIE) will fail this
     * ALTER TABLE if the enum whitelist doesn't include it — clean up or
     * re-map those rows first if you ever actually need to roll back.
     */
    public function down(): void
    {
        DB::statement(
            "ALTER TABLE room_group_subject MODIFY room_group ENUM('General','BSIT','BSED','BSHM','BSTM','BSCRIM') NOT NULL"
        );

        DB::statement(
            "ALTER TABLE room_group_room MODIFY room_group ENUM('General','BSIT','BSED','BSHM','BSTM','BSCRIM') NOT NULL"
        );
    }
};