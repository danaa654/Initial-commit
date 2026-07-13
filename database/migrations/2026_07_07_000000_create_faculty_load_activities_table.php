<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lightweight audit trail for Faculty Loading — one row per assign/
 * unassign action, kept independent of the `teaching_assignments`
 * table itself so a removed assignment still leaves a record behind
 * (a plain DELETE on teaching_assignments would otherwise erase all
 * trace that the assignment ever existed). Powers the "Recent
 * Activity" panel on the Faculty Loading overview — see
 * TeachingAssignmentController::index()/store()/destroy() and
 * Index.vue.
 *
 * faculty_id / subject_offering_id / performed_by all null-on-delete
 * rather than cascade — an old activity row ("Dr. X was assigned to
 * CS101") should stay visible in the feed even after the faculty or
 * offering it references is later deleted. The *_snapshot columns
 * exist for exactly that case: FacultyLoadActivity falls back to them
 * whenever the live relation is gone, so old entries never degrade
 * into "—".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faculty_load_activities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('faculty_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subject_offering_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();

            // 'assigned' | 'unassigned' — a plain string rather than an
            // enum column, so a future third action (e.g.
            // 'reassigned') never needs a schema change.
            $table->string('action');

            // Snapshots taken at log time, independent of the live
            // relations above.
            $table->string('faculty_name_snapshot')->nullable();
            $table->string('subject_snapshot')->nullable();
            $table->string('edp_code_snapshot')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['faculty_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faculty_load_activities');
    }
};