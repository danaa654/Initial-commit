<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The persisted output of "Save Schedule" (Phase 2 of the Greedy
     * Schedule Generator). Everything before this point — the Greedy
     * preview, the Interactive Review edits — lives only in memory /
     * in the browser. A row only ever lands here after the Registrar
     * or Admin clicks Save Schedule and every block has passed
     * ScheduleValidationService with zero conflicts.
     *
     * subject_offering_id is unique — a Subject Offering can only ever
     * have one final schedule block, mirroring the same
     * one-Offering-one-row rule teaching_assignments and
     * room_subject_offering already enforce. day/start/end are stored
     * as plain minute offsets (0-1439) — the exact same unit the Greedy
     * Scheduler, useTimetableGrid.js, and ScheduleValidationService all
     * already compute in, so nothing has to be converted back and
     * forth at the boundary.
     */
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {

            $table->id();

            $table->foreignId('academic_term_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('subject_offering_id')
                ->unique()
                ->constrained(indexName: 'sched_subject_offering_fk')
                ->cascadeOnDelete();

            $table->foreignId('faculty_id')
                ->nullable()
                ->constrained(indexName: 'sched_faculty_fk')
                ->nullOnDelete();

            $table->foreignId('room_id')
                ->constrained(indexName: 'sched_room_fk')
                ->restrictOnDelete();

            // Monday|Tuesday|...|Sunday — matches AcademicTerm's day
            // field names capitalized, so the frontend can round-trip
            // the same string it already displays.
            $table->string('day', 10);

            $table->unsignedSmallInteger('start_minutes');
            $table->unsignedSmallInteger('end_minutes');

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index(['academic_term_id', 'day']);
            $table->index(['room_id', 'day']);
            $table->index(['faculty_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};