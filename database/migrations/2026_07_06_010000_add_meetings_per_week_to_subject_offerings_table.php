<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Session Settings (Master Grid > Generate Schedule > Step 2) needs
 * somewhere to persist "how many times a week does this class meet" —
 * the ONE new fact that step introduces. Everything else it edits
 * already has a home:
 *
 *   - Total duration/week   -> subject_offerings.hours (unchanged)
 *   - Preferred faculty     -> teaching_assignments (Faculty Loading's
 *                              existing table — GreedyScheduleService
 *                              already tries this faculty first, see
 *                              resolveAssignedFaculty())
 *   - Preferred room        -> room_subject_offering (the existing
 *                              pivot GreedyScheduleService already
 *                              reads first, see preferredRoomCode())
 *
 * meetings_per_week is nullable — null means "not set yet", which the
 * SubjectOffering::hours_per_meeting accessor and the frontend both
 * treat as 1x/week (the safest default: one meeting carrying the full
 * weekly duration) so nothing that predates this column silently
 * breaks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subject_offerings', function (Blueprint $table) {
            $table->unsignedTinyInteger('meetings_per_week')->nullable()->after('hours');
        });
    }

    public function down(): void
    {
        Schema::table('subject_offerings', function (Blueprint $table) {
            $table->dropColumn('meetings_per_week');
        });
    }
};