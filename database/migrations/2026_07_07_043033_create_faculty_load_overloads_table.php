<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A Faculty Load Overload is a request to raise ONE faculty
     * member's effective teaching cap above their normal max_units —
     * for departments (e.g. CCS) that don't have enough warm bodies to
     * keep every faculty member under the standard 24-unit ceiling.
     *
     * Each row is one request for a chunk of extra units (always a
     * multiple of 3 — Faculty::OVERLOAD_INCREMENT_UNITS — since every
     * subject here carries 3 units). A faculty member's EFFECTIVE cap
     * (see Faculty::getEffectiveMaxUnitsAttribute()) is max_units plus
     * the sum of every row here still in the 'approved' status — never
     * 'pending' or 'declined'. The running total of
     * approved + pending overload for one faculty member may never
     * exceed Faculty::MAX_OVERLOAD_UNITS (12) — enforced in
     * FacultyLoadOverloadService, not here.
     *
     * requested_by / reviewed_by are deliberately separate columns
     * (not "created_by" reused for both) so an Admin/Registrar
     * self-approving their own request and a Dean's request later
     * approved by someone else both read unambiguously in an audit
     * trail.
     */
    public function up(): void
    {
        Schema::create('faculty_load_overloads', function (Blueprint $table) {

            $table->id();

            $table->foreignId('faculty_id')
                ->constrained()
                ->cascadeOnDelete();

            // Always a multiple of 3 (one subject's worth) — enforced
            // in FacultyLoadOverloadService, not by a DB check
            // constraint, so the allowed increment can change later
            // without a migration.
            $table->unsignedTinyInteger('units');

            // pending   -> awaiting Admin/Registrar review (Dean/
            //              Assistant Dean/OIC requests always start
            //              here).
            // approved  -> counts toward the faculty's effective cap.
            // declined  -> does not count toward anything; kept for
            //              history instead of being deleted.
            $table->enum('status', [
                'pending',
                'approved',
                'declined',
            ])->default('pending');

            $table->foreignId('requested_by')
                ->constrained('users');

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Why the requester needs the extra units — required for
            // Dean/Assistant Dean/OIC requests, optional for
            // Admin/Registrar (who auto-approve their own request and
            // don't need to justify it to anyone).
            $table->text('reason')->nullable();

            // Only ever set when status = declined — the Admin/
            // Registrar's explanation for the requester.
            $table->text('decline_reason')->nullable();

            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faculty_load_overloads');
    }
};