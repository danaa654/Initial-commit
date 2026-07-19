<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records WHETHER a Faculty assignment / Room preference was made
     * through the Subject Offerings page's "Override Eligibility"
     * checkbox (see SubjectOfferingController::assignFaculty() /
     * setPreferredRoom()) — a deliberate, explicit exception to the
     * normal Scope/Department (Faculty) or Lecture-Laboratory/Allowed
     * Programs (Room) eligibility rules.
     *
     * Master Grid reads this flag when a Subject Offering is dragged
     * or clicked onto the grid (see MasterGridDataService::
     * presentOffering()) so its own "Override Eligibility" checkboxes
     * in EditScheduleModal start pre-checked instead of the person
     * having to re-declare an exception that was already authorized
     * once. Without this column, Master Grid has no way to tell an
     * intentional override apart from a stale/mismatched Faculty or
     * Room, and would otherwise flag it as a fresh conflict every
     * time.
     */
    public function up(): void
    {
        Schema::table('teaching_assignments', function (Blueprint $table) {
            $table->boolean('is_override')->default(false)->after('active');
        });

        Schema::table('room_subject_offering', function (Blueprint $table) {
            $table->boolean('is_override')->default(false)->after('subject_offering_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teaching_assignments', function (Blueprint $table) {
            $table->dropColumn('is_override');
        });

        Schema::table('room_subject_offering', function (Blueprint $table) {
            $table->dropColumn('is_override');
        });
    }
};