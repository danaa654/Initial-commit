<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends faculty_load_activities (see the earlier
 * create_faculty_load_activities_table migration) to also cover
 * Faculty Load Overload actions — specifically an Admin/Registrar
 * directly adding overload units to a faculty member, which auto-
 * approves on submission (see FacultyLoadOverloadService::request()).
 * That single action needs two extra fields the original
 * assign/unassign rows never needed:
 *
 *   - overload_id: which FacultyLoadOverload this entry describes,
 *     null-on-delete for the same reason faculty_id/subject_offering_id
 *     already are — an old activity row should outlive the record it
 *     references.
 *   - units: how many overload units were involved, since there's no
 *     Subject Offering (and therefore no subject_snapshot) to read a
 *     unit count from for this action type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faculty_load_activities', function (Blueprint $table) {
            $table->foreignId('overload_id')->nullable()->after('subject_offering_id')
                ->constrained('faculty_load_overloads')->nullOnDelete();

            $table->unsignedInteger('units')->nullable()->after('action');
        });
    }

    public function down(): void
    {
        Schema::table('faculty_load_activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('overload_id');
            $table->dropColumn('units');
        });
    }
};