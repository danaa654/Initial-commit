<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This table stores Room -> Subject Offering PREFERENCES only.
     *
     * It intentionally has no day/time/faculty columns — this is not a
     * schedule. It is the input the future Greedy Scheduler will read to
     * know which Subject Offerings a Room should be considered for. A
     * preference is scoped to a specific Subject Offering, and since every
     * Subject Offering already belongs to exactly one Academic Term
     * (subject_offerings.academic_term_id), preferences are automatically
     * term-scoped without needing a separate term column here.
     *
     * subject_offering_id is unique ON ITS OWN (not a [room_id,
     * subject_offering_id] pair) — a Subject Offering represents one
     * class meeting, which can only ever be physically held in one room.
     * This means an Offering can appear in this table at most once,
     * full stop, regardless of which room it's tied to. Checking it for
     * a different room in the Manage Subjects UI transfers it rather
     * than duplicating it — see RoomController::syncPreferredSubjects().
     */
    public function up(): void
    {
        Schema::create('room_subject_offering', function (Blueprint $table) {

            $table->id();

            $table->foreignId('room_id')
                ->constrained('rooms')
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
        Schema::dropIfExists('room_subject_offering');
    }
};