<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This table stores Faculty -> Subject Offering PREFERENCES only —
     * the direct mirror of room_subject_offering
     * (2026_07_03_130000_create_room_subject_offering_table.php), same
     * reasoning throughout:
     *
     * It intentionally has no day/time/room columns — this is not an
     * assignment. Faculty Loading (teaching_assignments) remains the
     * only place a Faculty is actually attached to a Subject Offering;
     * this table is purely the input the future Greedy Scheduler (and,
     * in the meantime, the Master Grid workspace's "Preferred Faculty"
     * card field) reads to know which Faculty a Subject Offering should
     * be considered for. A preference is scoped to a specific Subject
     * Offering, and since every Subject Offering already belongs to
     * exactly one Academic Term (subject_offerings.academic_term_id),
     * preferences are automatically term-scoped without needing a
     * separate term column here.
     *
     * subject_offering_id is unique ON ITS OWN (not a [faculty_id,
     * subject_offering_id] pair) — same rule as Room preferences: a
     * Subject Offering can only have one Preferred Faculty at a time.
     * Preferring it for a different faculty member in the Manage
     * Subjects UI transfers it rather than duplicating it — see
     * FacultyController::syncPreferredSubjects().
     */
    public function up(): void
    {
        Schema::create('faculty_subject_offering', function (Blueprint $table) {

            $table->id();

            $table->foreignId('faculty_id')
                ->constrained('faculties')
                ->cascadeOnDelete();

            $table->foreignId('subject_offering_id')
                ->unique()
                ->constrained('subject_offerings')
                ->cascadeOnDelete();

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faculty_subject_offering');
    }
};