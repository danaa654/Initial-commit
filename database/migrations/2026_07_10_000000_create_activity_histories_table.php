<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * activity_histories — the write target for ActivityHistoryService::record().
 *
 * Deliberately separate from `audit_logs` (security/accountability,
 * Admin+Registrar) — this table backs the Activity History Timeline,
 * which tells the STORY of building a semester's schedule, grouped by
 * Academic Term. See ActivityHistoryService's class docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_histories', function (Blueprint $table) {
            $table->id();

            // Nullable + nullOnDelete: an Academic Term can theoretically
            // be deleted (see AcademicTermController::destroy()'s rules)
            // while its Activity History rows should still be readable —
            // grouping falls back to "Unassigned Term" in that case.
            $table->foreignId('academic_term_id')->nullable()
                ->constrained('academic_terms')->nullOnDelete();

            $table->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // Snapshot columns — same reasoning as AuditLog's user_name/
            // role: the Timeline must keep reading correctly even if the
            // user account referenced above is later deleted.
            $table->string('user_name')->nullable();

            // e.g. "Subject Offering", "Faculty Loading", "Master Grid",
            // "Publishing", "Academic Term", "System" — see
            // ActivityHistoryService::MODULES.
            $table->string('module');

            // e.g. "subject_offerings.generated", "master_grid.published"
            // — see ActivityHistoryService::EVENTS.
            $table->string('event');

            $table->string('title');
            $table->text('description')->nullable();

            // Arbitrary extra context for the card (counts, section
            // codes, department, old/new room, etc.) — rendered as a
            // simple key/value list under the description, never
            // relied on for filtering.
            $table->json('metadata')->nullable();

            // Precomputed at write time (not derived on the frontend)
            // so a new event type introduced later doesn't require a
            // frontend deploy just to render with the right color/icon
            // — see ActivityHistoryService::paletteFor().
            $table->string('icon');
            $table->string('color');

            $table->timestamp('created_at')->nullable();

            $table->index(['academic_term_id', 'created_at']);
            $table->index('module');
            $table->index('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_histories');
    }
};