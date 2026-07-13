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
        Schema::create('subjects', function (Blueprint $table) {

            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Subject Information
            |--------------------------------------------------------------------------
            |
            | Subjects are a MASTER LIST. A subject exists only once
            | (e.g. NSTP1, PATHFIT1, IT101) and is shared across as many
            | curriculums as needed via the curriculum_subjects pivot table.
            |
            */

            $table->string('subject_code', 20)->unique();
            $table->string('descriptive_title');

            /*
            |--------------------------------------------------------------------------
            | Units
            |--------------------------------------------------------------------------
            */

            $table->unsignedTinyInteger('units');

            /*
            |--------------------------------------------------------------------------
            | Contact Hours Per Week
            |--------------------------------------------------------------------------
            */

            $table->unsignedTinyInteger('lecture_hours')->default(0);

            $table->unsignedTinyInteger('laboratory_hours')->default(0);

            $table->unsignedTinyInteger('total_hours');

            /*
            |--------------------------------------------------------------------------
            | Subject Classification
            |--------------------------------------------------------------------------
            */

            // true = Major
            // false = Minor

            $table->boolean('is_major')->default(true);

            /*
            |--------------------------------------------------------------------------
            | Required Room Type
            |--------------------------------------------------------------------------
            */

            $table->enum('required_room', [
                'Lecture',
                'Computer Laboratory',
                'Science Laboratory',
                'Speech Laboratory',
                'PE Area',
                'Any',
            ])->default('Lecture');

            /*
            |--------------------------------------------------------------------------
            | Schedule Settings
            |--------------------------------------------------------------------------
            */

            // true = can split into multiple meetings
            // false = one continuous meeting only

            $table->boolean('allow_split_schedule')->default(true);

            /*
            |--------------------------------------------------------------------------
            | Prerequisite
            |--------------------------------------------------------------------------
            */

            $table->foreignId('prerequisite_id')
                ->nullable()
                ->constrained('subjects')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            $table->boolean('active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subjects');
    }
};