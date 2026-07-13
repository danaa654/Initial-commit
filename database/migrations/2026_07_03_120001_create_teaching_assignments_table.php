<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('teaching_assignments', function (Blueprint $table) {

            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Subject Offering / Faculty
            |--------------------------------------------------------------------------
            |
            | A Teaching Assignment is Faculty Loading's core record: one
            | faculty member assigned to teach one Subject Offering. The
            | Offering already carries its own academic_term_id,
            | section_id, curriculum_item_id, and subject_id — none of
            | that is duplicated here.
            |
            | MySQL's identifier limit is 64 characters. Laravel's default
            | constraint name pattern is "{table}_{column}_foreign" (and
            | "{table}_{col1}_{col2}_..._unique" for composite indexes).
            | On this table that default pattern pushes past the limit
            | once combined with the app's naming conventions, which is
            | what throws "Identifier name is too long". Passing a short,
            | explicit indexName to constrained() keeps Laravel's
            | table-inference (faculty_id -> faculty, etc.) while giving
            | the constraint itself a short hand-picked name.
            |
            */

            $table->foreignId('subject_offering_id')
                ->unique()
                ->constrained(indexName: 'ta_subject_offering_fk')
                ->cascadeOnDelete();

            $table->foreignId('faculty_id')
                ->constrained(indexName: 'ta_faculty_fk')
                ->cascadeOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Remarks
            |--------------------------------------------------------------------------
            */

            $table->string('remarks')
                ->nullable();

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            $table->boolean('active')
                ->default(true);

            $table->timestamps();

            /*
            |--------------------------------------------------------------------------
            | Prevent Duplicate Assignments
            |--------------------------------------------------------------------------
            |
            | Exactly one faculty member may be assigned to a given
            | Subject Offering — enforced by the unique() on
            | subject_offering_id above, so no separate composite unique
            | index is needed here anymore.
            |
            */
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teaching_assignments');
    }
};