<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Session Settings lets a subject meet 1x/2x/3x a week (meetings_per_
 * week), and GreedyScheduleService now actually honors that — placing
 * one block per meeting-day, same faculty/room/time on each, per the
 * spec's day-combination rules (MW/TTh/WF/MTh for 2x, MWF/TThS for 3x).
 *
 * That means a single Subject Offering can now legitimately have
 * MULTIPLE rows in `schedules` (e.g. one for Monday, one for
 * Wednesday) — which the original unique(subject_offering_id)
 * constraint made physically impossible. This migration replaces that
 * constraint with unique(subject_offering_id, day): still exactly one
 * row per subject PER DAY (a subject can never appear twice on the
 * same day — that would be a real duplicate, not a second meeting),
 * but no longer exactly one row per subject overall.
 *
 * MasterGridController::save() has been updated to match — it now
 * upserts on ['subject_offering_id', 'day'] instead of
 * ['subject_offering_id'] alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            // The unique index on subject_offering_id is what the FK
            // constraint (sched_subject_offering_fk) is physically
            // built on in MySQL — you can't drop that index while the
            // FK still references it. Drop the FK first, then the
            // now-unreferenced unique index, then rebuild both against
            // the new composite unique index.
            $table->dropForeign('sched_subject_offering_fk');
            $table->dropUnique(['subject_offering_id']);

            $table->unique(['subject_offering_id', 'day']);

            $table->foreign('subject_offering_id', 'sched_subject_offering_fk')
                ->references('id')->on('subject_offerings')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropForeign('sched_subject_offering_fk');
            $table->dropUnique(['subject_offering_id', 'day']);

            $table->unique(['subject_offering_id']);

            $table->foreign('subject_offering_id', 'sched_subject_offering_fk')
                ->references('id')->on('subject_offerings')
                ->cascadeOnDelete();
        });
    }
};